<?php

declare(strict_types=1);

namespace App\Domains\Places\Actions;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\Neighbourhood;
use App\Domains\Experiences\Models\Experience;
use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\ExternalSources\Models\ProviderSyncFailure;
use App\Domains\ExternalSources\Models\ProviderSyncRun;
use App\Domains\ExternalSources\Providers\GooglePlacesProvider;
use App\Domains\ExternalSources\Providers\WikimediaEnricher;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gives the catalogue the photographs the interface is designed around.
 *
 * The product leads with imagery on almost every surface — the destination
 * header, the recommendation cards, the neighbourhood rail — so a record with
 * no picture does not degrade gracefully, it reads as a broken page. Most of
 * the ingested catalogue arrived without one, because OpenStreetMap only knows
 * about a photograph when a mapper happened to add a Wikidata tag.
 *
 * Two sources, in the order of what we can say about them:
 *
 *  1. Wikimedia Commons, where the licence and the photographer are published
 *     alongside the file, so the credit under the picture is verifiable.
 *  2. Google Places photos, which cover the ordinary cafés and viewpoints that
 *     no encyclopaedia has an article about. Google's media endpoint is asked
 *     to skip its redirect so the stored URL is the final image rather than
 *     one carrying our API key.
 *
 * Nothing is ever invented: a place with no photograph from either source
 * keeps its null, and the interface falls back to a drawn card rather than to
 * a picture of somewhere else.
 */
class BackfillImagery
{
    private const MATCH_RADIUS_METRES = 200;

    private const NAME_SIMILARITY = 0.60;

    public function __construct(
        private readonly GooglePlacesProvider $google,
        private readonly WikimediaEnricher $wikimedia,
    ) {}

    /**
     * @return array{run: ProviderSyncRun, experiences: int, destinations: int, neighbourhoods: int, missed: int, failed: int}
     */
    public function run(Destination $destination, array $options = []): array
    {
        $limit = (int) ($options['limit'] ?? 60);
        $publishedOnly = (bool) ($options['published_only'] ?? true);

        $run = ProviderSyncRun::create([
            'provider' => 'imagery',
            'kind' => 'imagery:' . $destination->slug,
            'started_at' => CarbonImmutable::now(),
            'status' => 'running',
        ]);

        $tally = ['experiences' => 0, 'destinations' => 0, 'neighbourhoods' => 0, 'missed' => 0, 'failed' => 0];
        $seen = 0;

        foreach ($this->experiencesNeedingImages($destination, $limit, $publishedOnly) as $experience) {
            $seen++;

            try {
                $image = $this->imageForExperience($experience);

                if ($image === null) {
                    $tally['missed']++;

                    continue;
                }

                $experience->forceFill([
                    'image_url' => $image['url'],
                    'image_attribution' => $this->attribution($image),
                ])->save();

                $tally['experiences']++;
            } catch (Throwable $e) {
                $tally['failed']++;
                $this->recordFailure($run, $experience->id, 'experience_image', $e, ['name' => $experience->title]);
            }
        }

        /* The city header comes last so it can borrow from what the pass above
           just found: a photograph of this city's best-scoring experience is a
           truer header than a generic skyline, and it is already credited. */
        try {
            if ($this->applyDestinationHero($destination)) {
                $tally['destinations']++;
            }
        } catch (Throwable $e) {
            $tally['failed']++;
            $this->recordFailure($run, $destination->id, 'destination_hero', $e, ['name' => $destination->name]);
        }

        foreach (Neighbourhood::where('destination_id', $destination->id)->whereNull('image_url')->get() as $area) {
            try {
                if ($this->applyNeighbourhoodImage($area, $destination)) {
                    $tally['neighbourhoods']++;
                }
            } catch (Throwable $e) {
                $tally['failed']++;
                $this->recordFailure($run, $area->id, 'neighbourhood_image', $e, ['name' => $area->name]);
            }
        }

        $run->update([
            'status' => $tally['failed'] > 0 ? 'completed_with_failures' : 'completed',
            'finished_at' => CarbonImmutable::now(),
            'records_seen' => $seen,
            'records_written' => $tally['experiences'] + $tally['destinations'] + $tally['neighbourhoods'],
            'failures' => $tally['failed'],
        ]);

        return array_merge(['run' => $run->fresh()], $tally);
    }

    /** @return \Illuminate\Support\Collection<int, Experience> */
    private function experiencesNeedingImages(Destination $destination, int $limit, bool $publishedOnly)
    {
        return Experience::query()
            ->with('place')
            ->where('destination_id', $destination->id)
            ->whereNull('image_url')
            ->when($publishedOnly, fn ($q) => $q->where('status', 'published'))
            /* Spend the request budget on what a traveller is most likely to
               be shown rather than on the tail of the catalogue. */
            ->orderByDesc('iconic_weight')
            ->limit($limit)
            ->get();
    }

    /** @return array{url:string,licence:?string,licence_url:?string,creator:?string,creator_url:?string,source_url:?string}|null */
    private function imageForExperience(Experience $experience): ?array
    {
        $place = $experience->place;

        /* Freely licensed first, wherever OSM left us a reference to follow. */
        $refs = is_array($place?->external_refs) ? $place->external_refs : [];
        $wikidata = $refs['wikidata'] ?? null;

        if (is_string($wikidata) && $wikidata !== '') {
            $image = $this->wikimedia->imageForWikidata($wikidata);

            if ($image !== null) {
                return $image;
            }
        }

        if ($place === null || ! $this->google->isConfigured()) {
            return null;
        }

        $providerId = $this->googleIdFor($place->id, $place->name, (float) $place->lat, (float) $place->lng, $place->normalised_name);

        if ($providerId === null) {
            return null;
        }

        $photo = $this->google->photoFor($providerId);

        return $photo === null ? null : [
            'url' => $photo['url'],
            'licence' => 'Google',
            'licence_url' => 'https://www.google.com/intl/en/policies/terms/',
            'creator' => $photo['creator'],
            'creator_url' => $photo['creator_url'],
            'source_url' => $photo['creator_url'],
        ];
    }

    /**
     * The Google place id for one of ours: the recorded mapping if the ratings
     * pass already found it, otherwise a text search held to the same distance
     * and name thresholds, so a photograph never lands on the wrong building.
     */
    private function googleIdFor(string $placeId, string $name, float $lat, float $lng, ?string $normalised): ?string
    {
        $mapping = ExternalEntity::where('provider', $this->google->key())
            ->where('entity_type', 'place')
            ->where('entity_id', $placeId)
            ->first();

        if ($mapping !== null) {
            return $mapping->provider_id;
        }

        $point = new GeoPoint($lat, $lng);
        $best = null;

        foreach ($this->google->findByText($name, $point, 300, 5) as $candidate) {
            if ($point->distanceTo($candidate->point) > self::MATCH_RADIUS_METRES) {
                continue;
            }

            similar_text((string) ($normalised ?? Str::lower($name)), $candidate->normalisedName(), $percent);

            if ($percent / 100 < self::NAME_SIMILARITY) {
                continue;
            }

            if ($best === null || $percent > $best['score']) {
                $best = ['id' => $candidate->providerId, 'score' => $percent];
            }
        }

        return $best['id'] ?? null;
    }

    private function applyDestinationHero(Destination $destination): bool
    {
        if ($destination->hero_image_url !== null && $destination->hero_image_attribution !== null) {
            return false;
        }

        $image = $this->wikimedia->cityImage($destination->name, $destination->country);

        if ($image === null) {
            /* Borrow the city's strongest experience photograph. It is a real
               picture of this city, taken of somewhere the traveller can
               actually go, which is a better header than nothing at all. */
            $best = Experience::where('destination_id', $destination->id)
                ->where('status', 'published')
                ->whereNotNull('image_url')
                ->orderByDesc('iconic_weight')
                ->first();

            if ($best === null) {
                return false;
            }

            $destination->forceFill([
                'hero_image_url' => $best->image_url,
                'hero_image_attribution' => $best->image_attribution,
            ])->save();

            return true;
        }

        $destination->forceFill([
            'hero_image_url' => $image['url'],
            'hero_image_attribution' => $this->attribution($image),
        ])->save();

        return true;
    }

    private function applyNeighbourhoodImage(Neighbourhood $area, Destination $destination): bool
    {
        $image = $this->wikimedia->cityImage($area->name . ', ' . $destination->name, $destination->country);

        if ($image !== null) {
            $area->forceFill([
                'image_url' => $image['url'],
                'image_attribution' => $this->attribution($image),
            ])->save();

            return true;
        }

        $best = Experience::where('neighbourhood_id', $area->id)
            ->where('status', 'published')
            ->whereNotNull('image_url')
            ->orderByDesc('iconic_weight')
            ->first();

        if ($best === null) {
            return false;
        }

        $area->forceFill([
            'image_url' => $best->image_url,
            'image_attribution' => $best->image_attribution,
        ])->save();

        return true;
    }

    /** @param array<string, mixed> $image */
    private function attribution(array $image): array
    {
        return array_filter([
            'creator' => $image['creator'] ?? null,
            'licence' => $image['licence'] ?? null,
            'licence_url' => $image['licence_url'] ?? null,
            'source_url' => $image['source_url'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function recordFailure(ProviderSyncRun $run, string $id, string $stage, Throwable $e, array $context): void
    {
        ProviderSyncFailure::create([
            'provider_sync_run_id' => $run->id,
            'provider' => 'imagery',
            'provider_id' => $id,
            'stage' => $stage,
            'message' => Str::limit($e->getMessage(), 500),
            'context' => $context,
        ]);
    }
}
