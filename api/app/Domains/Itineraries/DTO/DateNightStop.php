<?php

declare(strict_types=1);

namespace App\Domains\Itineraries\DTO;

use App\Domains\Recommendations\DTO\ScoredExperience;
use Carbon\CarbonImmutable;

/** One timed stop in a date night: where, when, and how far from the last. */
final readonly class DateNightStop
{
    /** @param list<ScoredExperience> $alternatives Feasible swaps for the same slot, best first. */
    public function __construct(
        public string $role,
        public ScoredExperience $scored,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?int $travelMinutesFromPrevious,
        public ?string $travelMode,
        public array $alternatives = [],
    ) {}
}
