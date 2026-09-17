<?php

declare(strict_types=1);

namespace App\Domains\Experiences\Services;

use App\Domains\ExternalSources\Services\OfferService;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Experiences\Models\ExperienceRelationship;
use App\Domains\Places\Services\OpeningHours;
use App\Domains\Recommendations\DTO\ScoredExperience;
use Carbon\CarbonImmutable;

/**
 * API shape for an experience.
 *
 * The payload is split deliberately: `descriptive` never changes much and can be
 * cached hard; `dynamic` carries its own provenance and staleness so the client
 * can show the traveller what is current and what is merely last known
 * (acceptance 57).
 */
class ExperiencePresenter
{
    public function __construct(private readonly OfferService $offers) {}

    public function card(Experience $experience, ?ScoredExperience $scored = null): array
    {
        $place = $experience->place;

        return [
            'id' => $experience->id,
            'slug' => $experience->slug,
            'title' => $experience->title,
            'summary' => $experience->summary,
            'image_url' => $experience->image_url,
            'categories' => $experience->relationLoaded('categories')
                ? $experience->categories->map(fn ($c) => ['key' => $c->key, 'label' => $c->label])->all()
                : [],
            'duration_minutes' => $experience->expected_duration_minutes,
            'is_free' => $experience->is_free,
            'price_from' => $experience->priceFrom()?->toArray(),
            'price_freshness' => $experience->priceFreshness()->toArray(),
            'weather_exposure' => $experience->weather_exposure,
            'location' => $place === null ? null : [
                'lat' => $place->lat,
                'lng' => $place->lng,
                'name' => $place->name,
                'neighbourhood' => $place->neighbourhood?->name,
            ],
            'rating' => $place?->rating === null ? null : [
                'value' => (float) $place->rating,
                'count' => (int) $place->rating_count,
                'freshness' => $place->ratingFreshness()->toArray(),
            ],
            'experience_score' => $scored?->score,
            'travel' => $scored?->candidate->travel?->toArray(),
            'why' => $scored === null ? [] : array_map(fn ($r) => $r->message, $scored->reasons('positive')),
            'caveats' => $scored === null ? [] : array_map(fn ($r) => $r->message, $scored->reasons('negative')),
        ];
    }

    public function detail(Experience $experience, ?ScoredExperience $scored = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $place = $experience->place;
        $hours = new OpeningHours($place?->opening_hours);

        return [
            'id' => $experience->id,
            'slug' => $experience->slug,
            'title' => $experience->title,

            /* Stable, descriptive content. */
            'descriptive' => [
                'summary' => $experience->summary,
                'why_it_matters' => $experience->why_it_matters,
                'best_for' => $experience->best_for,
                'what_to_wear' => $experience->what_to_wear,
                'traveller_tip' => $experience->traveller_tip,
                'know_before_you_go' => $experience->know_before_you_go,
                'expected_duration_minutes' => $experience->expected_duration_minutes,
                'duration_range_minutes' => [$experience->min_duration_minutes, $experience->max_duration_minutes],
                'weather_exposure' => $experience->weather_exposure,
                'energy_level' => $experience->energy_level,
                'best_time_of_day' => $experience->best_time_of_day,
                'image_url' => $experience->image_url,
                'categories' => $experience->categories->map(fn ($c) => ['key' => $c->key, 'label' => $c->label])->all(),
                'destination' => $experience->destination?->name,
                'neighbourhood' => $place?->neighbourhood?->name,
            ],

            /* Facts that change, each with where it came from and when. */
            'dynamic' => [
                'price_from' => [
                    'value' => $experience->priceFrom()?->toArray(),
                    'is_free' => $experience->is_free,
                    'freshness' => $experience->priceFreshness()->toArray(),
                ],
                'opening_hours' => [
                    'known' => $hours->isKnown(),
                    'schedule' => $hours->toArray(),
                    'open_now' => $hours->isOpenAt($now),
                    'closes_at' => $hours->closesAt($now)?->toIso8601String(),
                    'freshness' => ($place?->openingHoursFreshness() ?? \App\Domains\Shared\ValueObjects\Freshness::unknown())->toArray(),
                ],
                'rating' => $place?->rating === null ? null : [
                    'value' => (float) $place->rating,
                    'count' => (int) $place->rating_count,
                    'freshness' => $place->ratingFreshness()->toArray(),
                ],
                'offers' => $this->offers->offersFor($experience),
                'requires_booking' => $experience->requires_booking,
                'booking_lead_time_hours' => $experience->booking_lead_time_hours,
            ],

            'location' => $place === null ? null : [
                'name' => $place->name,
                'address' => $place->address,
                'lat' => $place->lat,
                'lng' => $place->lng,
                'website' => $place->website,
                'phone' => $place->phone,
                'directions_url' => sprintf('https://www.google.com/maps/dir/?api=1&destination=%s,%s', $place->lat, $place->lng),
            ],

            'accessibility' => [
                'claims' => $experience->accessibility ?: ($place?->accessibility ?? []),
                'source' => $place?->accessibility_source,
                'note' => 'Accessibility details come from the venue or provider. Confirm with the venue before travelling.',
            ],

            /* Transparent signals instead of a "tourist trap" label (spec s6.4). */
            'signals' => [
                'uniqueness' => $experience->uniqueness,
                'tourist_concentration' => $experience->tourist_concentration,
                'value_for_money' => $experience->value_signal,
                'queue_risk' => $experience->queue_risk,
            ],

            'experience_score' => $scored?->score,
            'explanation' => $scored?->explanation(),
            'related' => $this->related($experience),
            'data_source' => $experience->data_source,
        ];
    }

    /** Experience Graph neighbours, grouped by relationship type (spec s10). */
    private function related(Experience $experience): array
    {
        $relationships = ExperienceRelationship::with('to.place')
            ->where('from_experience_id', $experience->id)
            ->orderByDesc('weight')
            ->limit(24)
            ->get();

        $grouped = [];
        foreach ($relationships as $relationship) {
            if ($relationship->to === null) {
                continue;
            }
            $grouped[$relationship->type][] = [
                'id' => $relationship->to->id,
                'title' => $relationship->to->title,
                'image_url' => $relationship->to->image_url,
                'weight' => $relationship->weight,
                'note' => $relationship->note,
            ];
        }

        return $grouped;
    }
}
