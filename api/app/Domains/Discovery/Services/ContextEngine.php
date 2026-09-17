<?php

declare(strict_types=1);

namespace App\Domains\Discovery\Services;

use App\Domains\Destinations\Models\Destination;
use App\Domains\ExternalSources\Contracts\WeatherProvider;
use App\Domains\JourneyIntelligence\Services\MissionInterpreter;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Journeys\Models\JourneyAnchor;
use App\Domains\Journeys\Models\JourneyContextSnapshot;
use App\Domains\Passport\Models\CompletedExperience;
use App\Domains\Passport\Models\SavedExperience;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\GeoPoint;
use App\Domains\Shared\ValueObjects\TimeWindow;
use App\Domains\TravellerProfile\Models\TravellerProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * "What is true right now?" (spec s4).
 *
 * Assembles the live context for a request and — for auditability — records the
 * inputs that produced a recommendation, without keeping a continuous location
 * history (spec s19.2, s23.2).
 */
class ContextEngine
{
    public function __construct(
        private readonly WeatherProvider $weather,
        private readonly MissionInterpreter $missions,
    ) {}

    public function build(Actor $actor, array $input): ScoringContext
    {
        $journey = $this->resolveJourney($actor, $input);
        $profile = $this->resolveProfile($actor);
        $destination = $journey?->destination ?? $this->resolveDestination($input);

        $timezone = $destination?->timezone ?? 'UTC';
        $now = isset($input['now'])
            ? CarbonImmutable::parse($input['now'])->setTimezone($timezone)
            : CarbonImmutable::now($timezone);

        $location = GeoPoint::fromArray($input) ?? $destination?->point();
        $precision = isset($input['lat'], $input['lng'])
            ? ($input['location_precision'] ?? 'precise')
            : ($destination !== null ? 'city' : 'none');

        /* "Approximate" is honoured, not just recorded: the coordinates are
           coarsened to roughly a kilometre before anything else sees them. */
        if ($precision === 'approximate' && $location !== null) {
            $location = new GeoPoint(round($location->lat, 2), round($location->lng, 2));
        }

        $window = null;
        if (! empty($input['window_minutes'])) {
            $window = TimeWindow::of($now, (int) $input['window_minutes']);
        }

        $nextAnchor = $journey !== null ? $this->nextAnchor($journey, $now) : null;

        /* A later anchor caps the window even if the traveller asked for longer. */
        if ($nextAnchor !== null) {
            $until = $nextAnchor->blockedWindow()->start;
            if ($until > $now && ($window === null || $window->end > $until)) {
                $window = new TimeWindow($now, $until);
            }
        }

        $weather = null;
        if ($location !== null) {
            $weather = rescue(
                fn () => $this->weather->forecast($location, $now, 12),
                function (\Throwable $e) {
                    Log::warning('weather.unavailable', ['message' => $e->getMessage()]);

                    return null;
                },
                report: false,
            );
        }

        $missionGoals = $journey?->mission_goals ?? [];
        if ($missionGoals === [] && $journey !== null) {
            $missionGoals = $this->missions->interpret($journey->mission_text, $journey->reason);
        }

        $context = new ScoringContext(
            now: $now,
            location: $location,
            locationPrecision: $precision,
            profile: $profile,
            journey: $journey,
            interestVector: $this->interestVector($profile, $input),
            missionGoalWeights: $this->missions->weights($missionGoals),
            weather: $weather,
            availableWindow: $window,
            nextAnchor: $nextAnchor,
            budgetRemainingMinor: $input['budget_remaining_minor'] ?? $journey?->daily_budget_minor,
            currency: $journey?->currency ?? $destination?->currency ?? 'GBP',
            completedExperienceIds: $this->completedIds($actor),
            savedExperienceIds: $this->savedIds($actor),
            mood: $input['mood'] ?? null,
            surface: $input['surface'] ?? 'discovery',
            familiarity: $journey?->familiarity ?? ($input['familiarity'] ?? 'never'),
            adults: (int) ($input['adults'] ?? $journey?->adults ?? 1),
            children: (int) ($input['children'] ?? $journey?->children ?? 0),
            childAges: array_map('intval', (array) ($input['child_ages'] ?? $journey?->child_ages ?? [])),
            accessibilityMode: (bool) ($input['accessibility_mode'] ?? $journey?->accessibility_mode ?? false),
            walkingTolerance: $profile?->walking_tolerance ?? 'medium',
            weights: config('experience.scoring.weights'),
        );

        return $context;
    }

    /** Persist the inputs behind a recommendation so it can be explained later. */
    public function snapshot(ScoringContext $context): JourneyContextSnapshot
    {
        return JourneyContextSnapshot::create([
            'journey_id' => $context->journey?->id,
            'traveller_profile_id' => $context->profile?->id,
            'captured_at' => $context->now,
            'local_time' => $context->now->format('Y-m-d H:i'),
            /* Coordinates are rounded: enough to explain a ranking, not a trail. */
            'lat' => $context->location ? round($context->location->lat, 3) : null,
            'lng' => $context->location ? round($context->location->lng, 3) : null,
            'location_precision' => $context->locationPrecision,
            'weather' => $context->weather?->toArray(),
            'budget_remaining_minor' => $context->budgetRemainingMinor,
            'companions' => [
                'adults' => $context->adults,
                'children' => $context->children,
                'child_ages' => $context->childAges,
            ],
            'active_anchor_id' => $context->nextAnchor?->id,
            'window_minutes' => $context->windowMinutes(),
            'surface' => $context->surface,
            'engine_version' => config('experience.engine_version'),
            'weights' => $context->weights,
        ]);
    }

    private function resolveJourney(Actor $actor, array $input): ?Journey
    {
        if (! empty($input['journey_id'])) {
            return Journey::with(['destination', 'anchors'])->ownedBy($actor)->find($input['journey_id']);
        }

        return Journey::with(['destination', 'anchors'])
            ->ownedBy($actor)
            ->where('status', 'active')
            ->latest('updated_at')
            ->first();
    }

    private function resolveProfile(Actor $actor): ?TravellerProfile
    {
        return TravellerProfile::with('interests')->ownedBy($actor)->first();
    }

    private function resolveDestination(array $input): ?Destination
    {
        if (! empty($input['destination_id'])) {
            return Destination::find($input['destination_id']);
        }
        if (! empty($input['destination'])) {
            return Destination::where('slug', $input['destination'])->first();
        }

        return null;
    }

    private function nextAnchor(Journey $journey, CarbonImmutable $now): ?JourneyAnchor
    {
        return $journey->anchors
            ->filter(fn (JourneyAnchor $a) => $a->starts_at > $now && $a->starts_at->diffInHours($now) < 24)
            ->sortBy('starts_at')
            ->first();
    }

    /** @return array<string,int> */
    private function interestVector(?TravellerProfile $profile, array $input): array
    {
        if (! empty($input['interests']) && is_array($input['interests'])) {
            $vector = [];
            foreach ($input['interests'] as $key => $weight) {
                is_int($key) ? $vector[$weight] = 80 : $vector[$key] = (int) $weight;
            }

            return $vector;
        }

        return $profile?->interestVector() ?? [];
    }

    /** @return list<string> */
    private function completedIds(Actor $actor): array
    {
        if ($actor->isAnonymous()) {
            return [];
        }

        return CompletedExperience::ownedBy($actor)->pluck('experience_id')->all();
    }

    /** @return list<string> */
    private function savedIds(Actor $actor): array
    {
        if ($actor->isAnonymous()) {
            return [];
        }

        return SavedExperience::ownedBy($actor)->pluck('experience_id')->all();
    }
}
