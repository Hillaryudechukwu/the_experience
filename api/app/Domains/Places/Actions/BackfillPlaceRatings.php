<?php

declare(strict_types=1);

namespace App\Domains\Places\Actions;

use App\Domains\Destinations\Models\Destination;
use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\ExternalSources\Models\ProviderSyncFailure;
use App\Domains\ExternalSources\Models\ProviderSyncRun;
use App\Domains\ExternalSources\Providers\GooglePlacesProvider;
use App\Domains\Places\Models\Place;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Adds Google's ratings to places we already hold.
 *
 * OpenStreetMap gives breadth, addresses, opening hours and — through Wikidata
 * — descriptions and licensed photography. The one thing it has no concept of
 * is a rating, and the quality component has been scoring every ingested place
 * neutral as a result.
 *
 * So rather than swapping one provider for the other, this uses each for what
 * it is good at. That is precisely what the canonical place model exists for:
 * one real-world place, several providers, no provider id as a primary key.
 *
 * Matching is conservative. A rating attached to the wrong place is worse than
 * no rating, so a candidate must be both close and named similarly before it is
 * accepted; anything else is left alone and counted.
 */
class BackfillPlaceRatings
{
    private const MATCH_RADIUS_METRES = 160;

    private const NAME_SIMILARITY = 0.60;

    public function __construct(private readonly GooglePlacesProvider $google) {}

    /**
     * @return array{run: ProviderSyncRun, matched: int, unmatched: int, skipped: int, failed: int, requests: int}
     *
     * "matched" counts places that gained a rating, opening hours, or both.
     */
    public function run(Destination $destination, array $options = []): array
    {
        $limit = (int) ($options['limit'] ?? 50);
        $force = (bool) ($options['force'] ?? false);
        $staleAfterDays = (int) ($options['stale_after_days'] ?? 30);
        $publishedOnly = (bool) ($options['published_only'] ?? false);

        $run = ProviderSyncRun::create([
            'provider' => $this->google->key(),
            'kind' => 'ratings:' . $destination->slug,
            'started_at' => CarbonImmutable::now(),
            'status' => 'running',
        ]);

        $places = $this->placesNeedingRatings($destination, $limit, $force, $staleAfterDays, $publishedOnly);
        $tally = ['matched' => 0, 'unmatched' => 0, 'skipped' => 0, 'failed' => 0, 'requests' => 0];

        foreach ($places as $place) {
            try {
                $tally['requests']++;

                $match = $this->findMatch($place);

                if ($match === null) {
                    $tally['unmatched']++;

                    continue;
                }

                if ($match->rating === null && $match->openingHours === null) {
                    $tally['skipped']++;

                    continue;
                }

                DB::transaction(fn () => $this->apply($place, $match));
                $tally['matched']++;
            } catch (Throwable $e) {
                $tally['failed']++;

                ProviderSyncFailure::create([
                    'provider_sync_run_id' => $run->id,
                    'provider' => $this->google->key(),
                    'provider_id' => $place->id,
                    'stage' => 'rating_match',
                    'message' => Str::limit($e->getMessage(), 500),
                    'context' => ['place' => $place->name],
                ]);
            }
        }

        $run->update([
            'status' => $tally['failed'] > 0 ? 'completed_with_failures' : 'completed',
            'finished_at' => CarbonImmutable::now(),
            'records_seen' => $places->count(),
            'records_written' => $tally['matched'],
            'failures' => $tally['failed'],
        ]);

        return array_merge(['run' => $run->fresh()], $tally);
    }

    private function placesNeedingRatings(
        Destination $destination,
        int $limit,
        bool $force,
        int $staleAfterDays,
        bool $publishedOnly = false,
    ) {
        return Place::query()
            ->where('destination_id', $destination->id)
            /* Every request costs money, so it should go first to places a
               traveller can actually be shown. A memorial sitting in
               needs_content gains nothing from a rating it will never display. */
            ->when($publishedOnly, fn ($q) => $q->whereExists(
                fn ($sub) => $sub->selectRaw('1')
                    ->from('experiences')
                    ->whereColumn('experiences.place_id', 'places.id')
                    ->where('experiences.status', 'published'),
            ))
            /* Anything missing either of the two things Google can supply, or
               whose rating has gone stale. */
            ->when(! $force, fn ($q) => $q->where(function ($q) use ($staleAfterDays) {
                $q->whereNull('rating')
                    ->orWhereNull('opening_hours')
                    ->orWhere('rating_verified_at', '<', CarbonImmutable::now()->subDays($staleAfterDays));
            }))
            /* Places attached to something a traveller could actually be shown
               are worth paying for first. */
            ->orderByDesc(fn ($q) => $q->selectRaw('exists (select 1 from experiences where experiences.place_id = places.id)'))
            ->limit($limit)
            ->get();
    }

    private function findMatch(Place $place): ?PlaceCandidate
    {
        /* Already mapped: one cheap lookup by id rather than a text search. */
        $mapping = ExternalEntity::where('provider', $this->google->key())
            ->where('entity_type', 'place')
            ->where('entity_id', $place->id)
            ->first();

        if ($mapping !== null) {
            return $this->google->getPlace($mapping->provider_id);
        }

        $point = new GeoPoint((float) $place->lat, (float) $place->lng);
        $candidates = $this->google->findByText($place->name, $point, 300, 5);

        $best = null;

        foreach ($candidates as $candidate) {
            $distance = $point->distanceTo($candidate->point);

            if ($distance > self::MATCH_RADIUS_METRES) {
                continue;
            }

            similar_text($place->normalised_name, $candidate->normalisedName(), $percent);
            $similarity = $percent / 100;

            if ($similarity < self::NAME_SIMILARITY) {
                continue;
            }

            if ($best === null || $similarity > $best['similarity']) {
                $best = ['candidate' => $candidate, 'similarity' => $similarity];
            }
        }

        return $best['candidate'] ?? null;
    }

    private function apply(Place $place, PlaceCandidate $match): void
    {
        $now = CarbonImmutable::now();

        $place->update(array_filter([
            'rating' => $match->rating,
            'rating_count' => $match->ratingCount,
            'rating_source' => $match->rating === null ? null : $this->google->key(),
            'rating_verified_at' => $match->rating === null ? null : $now,

            /* A venue maintains its own Google listing; its OSM hours were
               typed by a passing mapper, possibly years ago. When Google has
               hours they are the better source, and the provenance recorded
               alongside makes it clear which answer the traveller is seeing. */
            'opening_hours' => $match->openingHours,
            'opening_hours_source' => $match->openingHours === null ? null : $this->google->key(),
            'opening_hours_verified_at' => $match->openingHours === null ? null : $now,
            'accessibility' => $match->accessibility === []
                ? null
                : array_merge((array) $place->accessibility, $match->accessibility),
            'accessibility_source' => $match->accessibility === [] ? null : $this->google->key(),
        ], fn ($v) => $v !== null));

        ExternalEntity::updateOrCreate(
            [
                'provider' => $this->google->key(),
                'provider_id' => $match->providerId,
                'entity_type' => 'place',
            ],
            [
                'entity_id' => $place->id,
                'metadata' => ['matched_on' => 'name_and_proximity'],
                'confidence' => 0.95,
                'last_synced_at' => CarbonImmutable::now(),
            ],
        );
    }
}
