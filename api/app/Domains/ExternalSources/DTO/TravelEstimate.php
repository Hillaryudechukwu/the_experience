<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

final readonly class TravelEstimate
{
    public function __construct(
        public int $minutes,
        public int $metres,
        public string $mode,          // walk|transit|mixed
        /*
         * estimate  straight-line distance with a detour factor; time modelled
         * routed    real street-network distance; walking time modelled
         * live      real route and a trustworthy walking duration
         */
        public string $confidence,
        public string $source,
    ) {}

    public function toArray(): array
    {
        return [
            'minutes' => $this->minutes,
            'metres' => $this->metres,
            'mode' => $this->mode,
            'confidence' => $this->confidence,
            'source' => $this->source,
        ];
    }
}
