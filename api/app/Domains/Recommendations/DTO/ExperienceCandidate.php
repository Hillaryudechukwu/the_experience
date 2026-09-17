<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\DTO;

use App\Domains\ExternalSources\DTO\TravelEstimate;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Places\Services\OpeningHours;
use App\Domains\Shared\ValueObjects\GeoPoint;

final class ExperienceCandidate
{
    public function __construct(
        public readonly Experience $experience,
        public readonly ?GeoPoint $point,
        public readonly array $categoryKeys,
        public readonly OpeningHours $openingHours,
        public ?TravelEstimate $travel = null,
        public ?int $distanceMetres = null,
        public bool $hasLiveAvailability = false,
        public ?int $slotsRemaining = null,
    ) {}

    public function hasCategory(string $key): bool
    {
        return in_array($key, $this->categoryKeys, true);
    }

    public function travelMinutes(): ?int
    {
        return $this->travel?->minutes;
    }

    /** Door-to-door cost of doing this: travel there plus the visit itself. */
    public function totalMinutes(): int
    {
        return ($this->travel?->minutes ?? 0) + $this->experience->expected_duration_minutes;
    }

    public function minimumMinutes(): int
    {
        return ($this->travel?->minutes ?? 0) + $this->experience->min_duration_minutes;
    }
}
