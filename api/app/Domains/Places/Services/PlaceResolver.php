<?php

declare(strict_types=1);

namespace App\Domains\Places\Services;

use App\Domains\Destinations\Models\Destination;
use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\Places\Models\Place;
use App\Domains\Places\Models\PlaceMergeCandidate;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Facades\DB;

/**
 * Canonical place resolution (spec s11).
 *
 * A provider listing is not a real-world place. The same landmark arrives from
 * OpenStreetMap as a way, from Google as a place id, and from a ticket supplier
 * as three different products — and each will spell it differently.
 *
 * The rule that matters: only high-confidence matches are merged automatically.
 * Anything ambiguous becomes a merge candidate for a human, because silently
 * fusing two different places is far more damaging than carrying a duplicate
 * for a week. A wrongly merged place sends people to the wrong address.
 */
class PlaceResolver
{
    public function resolve(PlaceCandidate $candidate, Destination $destination): PlaceResolution
    {
        /* 1. We have already mapped this exact provider record. */
        $mapped = $this->byProviderMapping($candidate);

        if ($mapped !== null) {
            return new PlaceResolution(PlaceResolution::MATCHED, $mapped, 1.0, ['reason' => 'existing_provider_mapping']);
        }

        /* 2. A shared Wikidata identity is as strong a signal as we get:
              two records naming the same entity are the same entity. */
        $viaWikidata = $this->byWikidata($candidate);

        if ($viaWikidata !== null) {
            return new PlaceResolution(PlaceResolution::MATCHED, $viaWikidata, 0.99, ['reason' => 'shared_wikidata_id']);
        }

        /* 3. Otherwise: proximity plus name similarity. */
        $nearby = $this->nearbyWithSimilarity($candidate, $destination);

        if ($nearby !== null) {
            $config = config('experience.resolution');
            $distance = $nearby['distance'];
            $similarity = $nearby['similarity'];

            $confidentlyClose = $distance <= $config['close_radius_metres'] && $similarity >= $config['name_similarity_match'];
            $confidentlyNamed = $distance <= $config['match_radius_metres'] && $similarity >= $config['name_similarity_strict'];

            if ($confidentlyClose || $confidentlyNamed) {
                return new PlaceResolution(
                    PlaceResolution::MATCHED,
                    $nearby['place'],
                    round(min(0.98, 0.6 + ($similarity * 0.4)), 3),
                    ['reason' => 'proximity_and_name', 'distance_m' => $distance, 'similarity' => $similarity],
                );
            }

            if ($similarity >= $config['name_similarity_review']) {
                $this->recordMergeCandidate($candidate, $nearby['place'], $similarity, $distance);

                return new PlaceResolution(
                    PlaceResolution::NEEDS_REVIEW,
                    null,
                    round($similarity, 3),
                    ['reason' => 'ambiguous', 'distance_m' => $distance, 'similarity' => $similarity],
                );
            }
        }

        return new PlaceResolution(PlaceResolution::CREATED, null, 1.0, ['reason' => 'no_plausible_match']);
    }

    private function byProviderMapping(PlaceCandidate $candidate): ?Place
    {
        $mapping = ExternalEntity::where('provider', $candidate->provider)
            ->where('provider_id', $candidate->providerId)
            ->where('entity_type', 'place')
            ->first();

        return $mapping === null ? null : Place::find($mapping->entity_id);
    }

    private function byWikidata(PlaceCandidate $candidate): ?Place
    {
        $wikidata = $candidate->externalRefs['wikidata'] ?? null;

        if ($wikidata === null) {
            return null;
        }

        $mapping = ExternalEntity::where('provider', 'wikidata')
            ->where('provider_id', $wikidata)
            ->where('entity_type', 'place')
            ->first();

        return $mapping === null ? null : Place::find($mapping->entity_id);
    }

    /** @return array{place: Place, distance: float, similarity: float}|null */
    private function nearbyWithSimilarity(PlaceCandidate $candidate, Destination $destination): ?array
    {
        $radius = (int) config('experience.resolution.match_radius_metres', 250);
        $point = $candidate->point;

        $rows = Place::query()
            ->where('destination_id', $destination->id)
            ->when(
                DB::getDriverName() === 'pgsql',
                fn ($q) => $q
                    ->selectRaw('places.*, similarity(normalised_name, ?) as name_similarity', [$candidate->normalisedName()])
                    ->whereRaw(
                        'earth_box(ll_to_earth(?, ?), ?) @> ll_to_earth(places.lat::float8, places.lng::float8)',
                        [$point->lat, $point->lng, $radius],
                    ),
                fn ($q) => $q->selectRaw('places.*, 0 as name_similarity'),
            )
            ->limit(25)
            ->get();

        $best = null;

        foreach ($rows as $place) {
            $distance = $point->distanceTo(new GeoPoint((float) $place->lat, (float) $place->lng));

            if ($distance > $radius) {
                continue;
            }

            /* Trust PostgreSQL's trigram similarity where available, and fall
               back to a string comparison elsewhere so the logic still works. */
            $similarity = $place->name_similarity !== null && $place->name_similarity > 0
                ? (float) $place->name_similarity
                : $this->stringSimilarity($candidate->normalisedName(), (string) $place->normalised_name);

            if ($best === null || $similarity > $best['similarity']) {
                $best = ['place' => $place, 'distance' => $distance, 'similarity' => $similarity];
            }
        }

        return $best;
    }

    private function stringSimilarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return round($percent / 100, 3);
    }

    private function recordMergeCandidate(PlaceCandidate $candidate, Place $place, float $similarity, float $distance): void
    {
        PlaceMergeCandidate::updateOrCreate(
            [
                'provider' => $candidate->provider,
                'provider_id' => $candidate->providerId,
                'place_id' => $place->id,
            ],
            [
                'candidate_name' => $candidate->name,
                'candidate_lat' => $candidate->point->lat,
                'candidate_lng' => $candidate->point->lng,
                'confidence' => round($similarity, 3),
                'signals' => [
                    'distance_m' => round($distance, 1),
                    'name_similarity' => round($similarity, 3),
                    'existing_place' => $place->name,
                ],
                'status' => 'pending',
            ],
        );
    }
}
