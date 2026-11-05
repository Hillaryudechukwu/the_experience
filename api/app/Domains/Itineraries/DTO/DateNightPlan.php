<?php

declare(strict_types=1);

namespace App\Domains\Itineraries\DTO;

use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Models\RecommendationSet;
use App\Domains\Shared\ValueObjects\TimeWindow;

final readonly class DateNightPlan
{
    /** @param list<DateNightStop> $stops */
    public function __construct(
        public ScoringContext $context,
        public TimeWindow $evening,
        public array $stops,
        /** Null when nothing fitted: an empty evening is not a recommendation. */
        public ?RecommendationSet $set,
        public ?string $notice,
        public int $candidatesConsidered = 0,
    ) {}

    public function hasDinner(): bool
    {
        foreach ($this->stops as $stop) {
            if ($stop->role === 'dinner') {
                return true;
            }
        }

        return false;
    }
}
