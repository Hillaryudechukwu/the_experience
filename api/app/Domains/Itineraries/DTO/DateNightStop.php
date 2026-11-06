<?php

declare(strict_types=1);

namespace App\Domains\Itineraries\DTO;

use App\Domains\Recommendations\DTO\ScoredExperience;
use Carbon\CarbonImmutable;

/** One timed stop in a date night: where, when, and how far from the last. */
final readonly class DateNightStop
{
    /**
     * @param  list<ScoredExperience>  $alternatives  Feasible swaps for the same slot, best first.
     * @param  list<string>  $options  The slot's choices in the planner's own order, the shown one
     *                                 included. It does not move when a swap is pinned, so stepping
     *                                 through it visits every choice once.
     */
    public function __construct(
        public string $role,
        public ScoredExperience $scored,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?int $travelMinutesFromPrevious,
        public ?string $travelMode,
        public array $alternatives = [],
        public array $options = [],
    ) {}
}
