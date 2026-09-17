<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

use App\Domains\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;

final readonly class AvailabilitySlot
{
    public function __construct(
        public CarbonImmutable $date,
        public ?string $startTime,
        public ?int $slotsRemaining,
        public ?Money $price,
    ) {}

    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'start_time' => $this->startTime,
            'slots_remaining' => $this->slotsRemaining,
            'price' => $this->price?->toArray(),
        ];
    }
}
