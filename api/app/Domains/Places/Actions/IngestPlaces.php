<?php

declare(strict_types=1);

namespace App\Domains\Places\Actions;

use App\Domains\Destinations\Models\Destination;
use App\Domains\ExternalSources\Contracts\PlaceDataProvider;
use App\Domains\ExternalSources\Contracts\PlaceEnricher;
use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\ExternalSources\Models\ProviderSyncFailure;
use App\Domains\ExternalSources\Models\ProviderSyncRun;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Experiences\Models\ExperienceCategory;
use App\Domains\Places\Models\Place;
use App\Domains\Places\Services\ExperienceDraftFactory;
use App\Domains\Places\Services\PlaceResolution;
use App\Domains\Places\Services\PlaceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pulls real places into the canonical catalogue.
 *
 * The merge policy matters more than the fetching. When an ingested place
 * matches something we already hold:
 *
 *   - Facts from the provider (opening hours, website, phone, accessibility,
 *     rating) are written, because the provider is a better source for those
 *     than anything we wrote by hand.
 *   - Editorial content is not. If an experience already has a description
 *     someone wrote, a Wikipedia extract does not replace it; enrichment only
 *     fills gaps.
 *
 * Every failure is recorded against the run rather than aborting it. One
 * unparseable record should not cost you the other two hundred.
 */
class IngestPlaces
{
    public function __construct(
        private readonly PlaceDataProvider $places,
        private readonly PlaceResolver $resolver,
        private readonly ExperienceDraftFactory $drafts,
        private readonly ?PlaceEnricher $enricher = null,
    ) {}

    /**
     * @return array{run: ProviderSyncRun, created: int, matched: int, needs_review: int, enriched: int, failed: int}
     */
    public function run(Destination $destination, array $options = []): array
    {
        $radius = (int) ($options['radius'] ?? $destination->default_radius_m ?? 8000);
        $kinds = (array) ($options['kinds'] ?? []);
        $limit = (int) ($options['limit'] ?? 120);
        $enrich = (bool) ($options['enrich'] ?? true);
        $refreshDerived = (bool) ($options['refresh_derived'] ?? false);

        $run = ProviderSyncRun::create([
            'provider' => $this->places->key(),
            'kind' => 'places:' . $destination->slug,
            'started_at' => CarbonImmutable::now(),
            'status' => 'running',
        ]);

        $tally = ['created' => 0, 'matched' => 0, 'needs_review' => 0, 'enriched' => 0, 'failed' => 0];

        try {
            $candidates = $this->places->searchNearby($destination->point(), $radius, $kinds, $limit);
        } catch (Throwable $e) {
            $this->recordFailure($run, null, 'fetch', $e);
            $run->update(['status' => 'failed', 'finished_at' => CarbonImmutable::now(), 'failures' => 1]);

            throw $e;
        }

        $run->update(['records_seen' => $candidates->count()]);

        foreach ($candidates as $candidate) {
            try {
                /*
                 * Each record gets its own transaction — a savepoint when the
                 * caller already has one open. Without this, a single failed
                 * insert aborts the surrounding transaction on PostgreSQL and
                 * every subsequent statement fails too, including the attempt
                 * to write down what went wrong. One unmappable record would
                 * then cost the entire run *and* the explanation for it.
                 */
                $outcome = DB::transaction(function () use ($candidate, $destination, $enrich, $refreshDerived) {
                    $resolution = $this->resolver->resolve($candidate, $destination);

                    if ($resolution->decision === PlaceResolution::NEEDS_REVIEW) {
                        return ['needs_review' => true];
                    }

                    $place = $resolution->isMatch()
                        ? $this->updatePlace($resolution->place, $candidate)
                        : $this->createPlace($destination, $candidate);

                    $this->mapExternalIds($place, $candidate, $resolution->confidence);

                    return [
                        'matched' => $resolution->isMatch(),
                        'enriched' => $this->upsertExperience($destination, $place, $candidate, $enrich, $refreshDerived),
                    ];
                });

                if ($outcome['needs_review'] ?? false) {
                    $tally['needs_review']++;

                    continue;
                }

                $outcome['matched'] ? $tally['matched']++ : $tally['created']++;

                if ($outcome['enriched']) {
                    $tally['enriched']++;
                }
            } catch (Throwable $e) {
                $tally['failed']++;
                $this->recordFailure($run, $candidate, 'map', $e);
            }
        }

        $run->update([
            'status' => $tally['failed'] > 0 ? 'completed_with_failures' : 'completed',
            'finished_at' => CarbonImmutable::now(),
            'records_written' => $tally['created'] + $tally['matched'],
            'failures' => $tally['failed'],
        ]);

        return array_merge(['run' => $run->fresh()], $tally);
    }

    private function createPlace(Destination $destination, PlaceCandidate $candidate): Place
    {
        return Place::create([
            'destination_id' => $destination->id,
            'slug' => $this->slug($destination, $candidate),
            'name' => $candidate->name,
            'normalised_name' => $candidate->normalisedName(),
            'kind' => $candidate->kind,
            'address' => $candidate->address,
            'lat' => $candidate->point->lat,
            'lng' => $candidate->point->lng,
            'timezone' => $destination->timezone,
            'phone' => $candidate->phone,
            'website' => $candidate->website,
            'opening_hours' => $candidate->openingHours,
            'opening_hours_source' => $candidate->openingHours === null ? null : $candidate->provider,
            'opening_hours_verified_at' => $candidate->openingHours === null ? null : CarbonImmutable::now(),
            'rating' => $candidate->rating,
            'rating_count' => $candidate->ratingCount,
            'rating_source' => $candidate->rating === null ? null : $candidate->provider,
            'rating_verified_at' => $candidate->rating === null ? null : CarbonImmutable::now(),
            'accessibility' => $candidate->accessibility,
            'accessibility_source' => $candidate->accessibility === [] ? null : $candidate->provider,
            'canonical_confidence' => 100,
            'resolution_status' => 'confirmed',
            'data_source' => $candidate->provider,
            'attribution' => $this->places->attribution(),
        ]);
    }

    /** Provider facts win; nothing else is touched. */
    private function updatePlace(Place $place, PlaceCandidate $candidate): Place
    {
        $now = CarbonImmutable::now();
        $updates = array_filter([
            'website' => $candidate->website,
            'phone' => $candidate->phone,
            'address' => $candidate->address,
            /* The kind is a provider fact too. It changes when our tag mapping
               improves, and everything derived downstream keys off it. */
            'kind' => $candidate->kind,
        ], fn ($v) => $v !== null);

        if ($candidate->openingHours !== null) {
            $updates['opening_hours'] = $candidate->openingHours;
            $updates['opening_hours_source'] = $candidate->provider;
            $updates['opening_hours_verified_at'] = $now;
        }

        if ($candidate->rating !== null) {
            $updates['rating'] = $candidate->rating;
            $updates['rating_count'] = $candidate->ratingCount;
            $updates['rating_source'] = $candidate->provider;
            $updates['rating_verified_at'] = $now;
        }

        if ($candidate->accessibility !== []) {
            $updates['accessibility'] = array_merge((array) $place->accessibility, $candidate->accessibility);
            $updates['accessibility_source'] = $candidate->provider;
        }

        $place->update($updates);

        return $place->fresh();
    }

    private function mapExternalIds(Place $place, PlaceCandidate $candidate, float $confidence): void
    {
        ExternalEntity::updateOrCreate(
            ['provider' => $candidate->provider, 'provider_id' => $candidate->providerId, 'entity_type' => 'place'],
            [
                'entity_id' => $place->id,
                'metadata' => $candidate->toArray(),
                'confidence' => $confidence,
                'last_synced_at' => CarbonImmutable::now(),
            ],
        );

        /* Wikidata is stored as its own mapping so a future provider carrying
           the same identity resolves instantly instead of guessing. */
        if (isset($candidate->externalRefs['wikidata'])) {
            ExternalEntity::updateOrCreate(
                ['provider' => 'wikidata', 'provider_id' => $candidate->externalRefs['wikidata'], 'entity_type' => 'place'],
                [
                    'entity_id' => $place->id,
                    'external_url' => 'https://www.wikidata.org/wiki/' . $candidate->externalRefs['wikidata'],
                    'confidence' => 1.0,
                    'last_synced_at' => CarbonImmutable::now(),
                ],
            );
        }
    }

    private function upsertExperience(
        Destination $destination,
        Place $place,
        PlaceCandidate $candidate,
        bool $enrich,
        bool $refreshDerived = false,
    ): bool {
        $existing = Experience::where('place_id', $place->id)->first();
        $enrichment = $enrich ? $this->enricher?->enrich($candidate) : null;

        if ($existing !== null) {
            /* Never overwrite editorial. Fill only what is missing. */
            $fill = [];

            /*
             * Derived values are the system's own guesses — prominence,
             * duration, exposure, interest affinity. When the heuristic that
             * produced them changes, the records made under the old one are
             * stale and will rank wrongly until they are recomputed. This
             * refresh only ever touches rows the system generated; anything
             * with editorial provenance is left exactly as written.
             */
            if ($refreshDerived && $this->isProviderDerived($existing)) {
                $draft = $this->drafts->build($candidate, $enrichment, $destination->currency);

                $fill = array_merge($fill, collect($draft)->only([
                    'min_duration_minutes', 'expected_duration_minutes', 'max_duration_minutes',
                    'is_free', 'weather_exposure', 'energy_level', 'iconic_weight', 'uniqueness',
                    'tourist_concentration', 'queue_risk', 'child_friendly_score', 'romance_score',
                    'social_score', 'interest_affinity',
                ])->all());

                $this->attachCategories($existing, $candidate);
            }

            if ($existing->image_url === null && $enrichment?->hasImage()) {
                $fill['image_url'] = $enrichment->imageUrl;
                $fill['image_attribution'] = $enrichment->imageAttribution();
            }

            if (($existing->why_it_matters === null || $existing->why_it_matters === '') && $enrichment?->summary !== null) {
                $fill['why_it_matters'] = $enrichment->summary;
                $fill['content_source_name'] = $enrichment->sourceName;
                $fill['content_source_url'] = $enrichment->sourceUrl;

                /* A draft is held back from discovery until it has something to
                   say about the place. Once a description arrives, that reason
                   has gone, so the draft is released. */
                if ($existing->status === 'needs_content') {
                    $fill['status'] = 'published';
                    $fill['summary'] = $this->drafts->summarise($enrichment->summary);
                }
            }

            if ($fill !== []) {
                $existing->update($fill);

                return true;
            }

            return false;
        }

        $draft = $this->drafts->build($candidate, $enrichment, $destination->currency);

        $experience = Experience::create(array_merge($draft, [
            'destination_id' => $destination->id,
            'place_id' => $place->id,
            'neighbourhood_id' => $place->neighbourhood_id,
            'slug' => $this->slug($destination, $candidate),
            'data_source' => $candidate->provider . ($enrichment !== null ? '+wikimedia' : ''),
            'verified_at' => CarbonImmutable::now(),
        ]));

        $this->attachCategories($experience, $candidate);

        return $enrichment !== null;
    }

    /** True only for rows this pipeline generated, never for editorial content. */
    private function isProviderDerived(Experience $experience): bool
    {
        return $experience->data_source !== null
            && ! str_starts_with($experience->data_source, 'seed_')
            && $experience->data_source !== 'editorial';
    }

    private function attachCategories(Experience $experience, PlaceCandidate $candidate): void
    {
        $map = [
            'museum' => ['culture', 'half_day'],
            'gallery' => ['culture'],
            'historic' => ['culture', 'iconic'],
            'memorial' => ['quick_experience', 'free'],
            'viewpoint' => ['quick_experience', 'romantic'],
            'artwork' => ['quick_experience', 'free'],
            'park' => ['nature', 'free', 'weather_dependent'],
            'market' => ['food_experience', 'local_favourite', 'free'],
            'worship' => ['culture', 'free'],
            'theatre' => ['culture', 'book_ahead'],
            'zoo' => ['family', 'half_day'],
            'theme_park' => ['family', 'full_day'],
            'nightlife' => ['nightlife'],
            'food' => ['food_experience'],
            'attraction' => ['iconic'],
        ];

        $keys = $map[$candidate->kind] ?? [];

        if ($experience->is_free) {
            $keys[] = 'free';
        }

        $ids = ExperienceCategory::whereIn('key', array_unique($keys))->pluck('id')->all();

        if ($ids !== []) {
            $experience->categories()->sync($ids);
        }
    }

    private function slug(Destination $destination, PlaceCandidate $candidate): string
    {
        $base = $destination->slug . '-' . Str::slug($candidate->name);
        $suffix = substr(md5($candidate->provider . $candidate->providerId), 0, 6);

        return Str::limit($base, 80, '') . '-' . $suffix;
    }

    private function recordFailure(ProviderSyncRun $run, ?PlaceCandidate $candidate, string $stage, Throwable $e): void
    {
        ProviderSyncFailure::create([
            'provider_sync_run_id' => $run->id,
            'provider' => $this->places->key(),
            'provider_id' => $candidate?->providerId,
            'stage' => $stage,
            'message' => Str::limit($e->getMessage(), 500),
            'context' => ['candidate' => $candidate?->toArray()],
        ]);
    }
}
