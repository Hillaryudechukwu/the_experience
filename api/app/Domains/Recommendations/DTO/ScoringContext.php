<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\DTO;

use App\Domains\ExternalSources\DTO\WeatherSnapshot;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Journeys\Models\JourneyAnchor;
use App\Domains\Shared\ValueObjects\GeoPoint;
use App\Domains\Shared\ValueObjects\TimeWindow;
use App\Domains\TravellerProfile\Models\TravellerProfile;
use Carbon\CarbonImmutable;

/**
 * Everything the scorer is allowed to reason about.
 *
 * Built once per request by ContextEngine and persisted as a
 * journey_context_snapshot so a past recommendation can be explained later
 * (spec s19.2).
 */
final readonly class ScoringContext
{
    /**
     * @param list<string> $completedExperienceIds
     * @param list<string> $savedExperienceIds
     * @param array<string,int> $interestVector
     * @param array<string,float> $missionGoalWeights
     */
    public function __construct(
        public CarbonImmutable $now,
        public ?GeoPoint $location,
        public string $locationPrecision,
        public ?TravellerProfile $profile,
        public ?Journey $journey,
        public array $interestVector,
        public array $missionGoalWeights,
        public ?WeatherSnapshot $weather,
        public ?TimeWindow $availableWindow,
        public ?JourneyAnchor $nextAnchor,
        public ?int $budgetRemainingMinor,
        public string $currency,
        public array $completedExperienceIds,
        public array $savedExperienceIds,
        public ?string $mood,
        public string $surface,
        public string $familiarity,
        public int $adults,
        public int $children,
        public array $childAges,
        public bool $accessibilityMode,
        public string $walkingTolerance,
        public array $weights,
    ) {}

    public function reason(): string
    {
        return $this->journey?->reason ?? 'other';
    }

    public function playbook(): array
    {
        return config("experience.playbooks.{$this->reason()}", config('experience.playbooks.other'));
    }

    public function hasChildren(): bool
    {
        return $this->children > 0;
    }

    public function windowMinutes(): ?int
    {
        return $this->availableWindow?->minutes();
    }

    /** A copy of this context positioned at a different moment, place and window. */
    public function at(
        CarbonImmutable $now,
        ?GeoPoint $location = null,
        ?TimeWindow $window = null,
        ?int $budgetRemainingMinor = null,
    ): self {
        return new self(
            now: $now,
            location: $location ?? $this->location,
            locationPrecision: $this->locationPrecision,
            profile: $this->profile,
            journey: $this->journey,
            interestVector: $this->interestVector,
            missionGoalWeights: $this->missionGoalWeights,
            weather: $this->weather,
            availableWindow: $window ?? $this->availableWindow,
            nextAnchor: $this->nextAnchor,
            budgetRemainingMinor: $budgetRemainingMinor ?? $this->budgetRemainingMinor,
            currency: $this->currency,
            completedExperienceIds: $this->completedExperienceIds,
            savedExperienceIds: $this->savedExperienceIds,
            mood: $this->mood,
            surface: $this->surface,
            familiarity: $this->familiarity,
            adults: $this->adults,
            children: $this->children,
            childAges: $this->childAges,
            accessibilityMode: $this->accessibilityMode,
            walkingTolerance: $this->walkingTolerance,
            weights: $this->weights,
        );
    }

    /** A copy with a different next anchor (used when planning day by day). */
    public function withAnchor(?JourneyAnchor $anchor): self
    {
        $clone = $this->at($this->now);

        return new self(
            now: $clone->now,
            location: $clone->location,
            locationPrecision: $clone->locationPrecision,
            profile: $clone->profile,
            journey: $clone->journey,
            interestVector: $clone->interestVector,
            missionGoalWeights: $clone->missionGoalWeights,
            weather: $clone->weather,
            availableWindow: $clone->availableWindow,
            nextAnchor: $anchor,
            budgetRemainingMinor: $clone->budgetRemainingMinor,
            currency: $clone->currency,
            completedExperienceIds: $clone->completedExperienceIds,
            savedExperienceIds: $clone->savedExperienceIds,
            mood: $clone->mood,
            surface: $clone->surface,
            familiarity: $clone->familiarity,
            adults: $clone->adults,
            children: $clone->children,
            childAges: $clone->childAges,
            accessibilityMode: $clone->accessibilityMode,
            walkingTolerance: $clone->walkingTolerance,
            weights: $clone->weights,
        );
    }

    /** Stable key for the score cache — same inputs, same answer. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->now->format('Y-m-d-H') . ':' . intdiv((int) $this->now->format('i'), 15),
            $this->location ? [round($this->location->lat, 3), round($this->location->lng, 3)] : null,
            $this->profile?->id,
            $this->journey?->id,
            $this->journey?->updated_at?->toIso8601String(),
            $this->interestVector,
            $this->missionGoalWeights,
            $this->weather?->condition,
            $this->windowMinutes(),
            $this->nextAnchor?->id,
            $this->budgetRemainingMinor,
            $this->mood,
            $this->familiarity,
            $this->adults,
            $this->children,
            $this->accessibilityMode,
            $this->weights,
            config('experience.engine_version'),
        ], JSON_THROW_ON_ERROR));
    }
}
