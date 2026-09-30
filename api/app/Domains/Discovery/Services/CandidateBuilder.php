<?php

declare(strict_types=1);

namespace App\Domains\Discovery\Services;

use App\Domains\ExternalSources\Contracts\RoutingProvider;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Places\Services\GeospatialRepository;
use App\Domains\Places\Services\OpeningHours;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a context into the set of experiences worth scoring.
 *
 * Hard constraints are applied here, not in the scorer: an option that cannot
 * physically work is removed rather than given a low score (acceptance 55).
 */
class CandidateBuilder
{
    public function __construct(
        private readonly GeospatialRepository $geo,
        private readonly RoutingProvider $routing,
    ) {}

    /** @return list<ExperienceCandidate> */
    public function build(ScoringContext $context, array $options = []): array
    {
        $limit = (int) ($options['limit'] ?? config('experience.scoring.max_candidates', 400));
        $radius = (int) ($options['radius_metres'] ?? 12000);

        $query = Experience::query()
            /* place.neighbourhood because the presenter reads its name for
               every card, which was a query per result. */
            ->with(['place.neighbourhood', 'categories'])
            ->where('experiences.status', 'published');

        if (! empty($options['destination_id'])) {
            $query->where('experiences.destination_id', $options['destination_id']);
        }

        if (! empty($options['categories'])) {
            $categories = (array) $options['categories'];
            $query->whereHas('categories', fn ($q) => $q->whereIn('key', $categories));
        }

        if (! empty($options['exclude_categories'])) {
            $excluded = (array) $options['exclude_categories'];
            $query->whereDoesntHave('categories', fn ($q) => $q->whereIn('key', $excluded));
        }

        if (! empty($options['best_time'])) {
            $query->whereJsonContains('experiences.best_time_of_day', $options['best_time']);
        }

        if (! empty($options['weather_exposure'])) {
            $query->where('experiences.weather_exposure', $options['weather_exposure']);
        }

        if (! empty($options['free_only'])) {
            $query->where('experiences.is_free', true);
        }

        if (! empty($options['max_price_minor'])) {
            $query->where(function ($q) use ($options) {
                $q->where('experiences.is_free', true)
                    ->orWhere('experiences.price_from_minor', '<=', (int) $options['max_price_minor']);
            });
        }

        if (! empty($options['query'])) {
            $term = '%' . mb_strtolower($options['query']) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('lower(experiences.title) like ?', [$term])
                    ->orWhereRaw('lower(experiences.summary) like ?', [$term])
                    ->orWhereRaw('lower(experiences.why_it_matters) like ?', [$term]);
            });
        }

        /* Geo filter runs against the joined canonical place. */
        if ($context->location !== null) {
            $query->join('places', 'places.id', '=', 'experiences.place_id')
                ->select('experiences.*');
            $this->geo->withinRadius($query, 'places', $context->location, $radius);
        }

        /* Hard filters (spec s6, config-driven). */
        $filters = config('experience.scoring.hard_filters');

        if (($filters['exclude_completed'] ?? true) && $context->completedExperienceIds !== []) {
            $query->whereNotIn('experiences.id', $context->completedExperienceIds);
        }

        $candidates = $query->limit($limit)->get();

        if (($filters['respect_avoid_list'] ?? true)) {
            $candidates = $this->applyAvoidList($candidates, $context);
        }

        $built = [];
        foreach ($candidates as $experience) {
            $candidate = $this->toCandidate($experience, $context);

            if (! $this->passesHardFilters($candidate, $context, $filters)) {
                continue;
            }

            $built[] = $candidate;
        }

        return $built;
    }

    public function toCandidate(Experience $experience, ScoringContext $context): ExperienceCandidate
    {
        $point = $experience->place?->point();
        $travel = null;
        $distance = null;

        if ($point !== null && $context->location !== null) {
            $distance = (int) round($context->location->distanceTo($point));
            $travel = $this->routing->estimate($context->location, $point, $context->walkingTolerance);
        }

        return new ExperienceCandidate(
            experience: $experience,
            point: $point,
            categoryKeys: $experience->categories->pluck('key')->all(),
            openingHours: new OpeningHours($experience->place?->opening_hours),
            travel: $travel,
            distanceMetres: $distance,
        );
    }

    private function applyAvoidList(Collection $candidates, ScoringContext $context): Collection
    {
        $avoid = array_filter(array_map('mb_strtolower', (array) ($context->journey?->avoid ?? [])));

        if ($avoid === []) {
            return $candidates;
        }

        return $candidates->reject(function (Experience $experience) use ($avoid) {
            $haystack = mb_strtolower($experience->title . ' ' . $experience->summary . ' ' . $experience->categories->pluck('key')->implode(' '));

            foreach ($avoid as $term) {
                if ($term !== '' && Str::contains($haystack, $term)) {
                    return true;
                }
            }

            return false;
        });
    }

    private function passesHardFilters(ExperienceCandidate $candidate, ScoringContext $context, array $filters): bool
    {
        /* The traveller cannot physically fit it into the window they have. */
        if (($filters['respect_time_window'] ?? true) && $context->availableWindow !== null) {
            if ($candidate->minimumMinutes() > $context->availableWindow->minutes()) {
                return false;
            }
        }

        /* It must not put a fixed commitment at risk. */
        if ($context->nextAnchor !== null) {
            $available = (int) $context->now->diffInMinutes($context->nextAnchor->blockedWindow()->start, false);
            if ($available > 0 && $candidate->minimumMinutes() > $available) {
                return false;
            }
        }

        /* Accessibility claims must be positively confirmed, never assumed. */
        if (($filters['respect_accessibility'] ?? true) && $context->accessibilityMode) {
            $access = (array) $candidate->experience->accessibility;
            if (($access['wheelchair_accessible'] ?? null) === false) {
                return false;
            }
        }

        return true;
    }

    public function point(Experience $experience): ?GeoPoint
    {
        return $experience->place?->point();
    }
}
