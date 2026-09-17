<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

final readonly class TravelEstimate
{
    public function __construct(
        public int $minutes,
        public int $metres,
        public string $mode,          // walk|transit|mixed
        public string $confidence,    // estimate|live
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
