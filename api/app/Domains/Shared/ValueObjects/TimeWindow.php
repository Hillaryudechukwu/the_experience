<?php

declare(strict_types=1);

namespace App\Domains\Shared\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class TimeWindow
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public static function of(CarbonImmutable $start, int $minutes): self
    {
        return new self($start, $start->addMinutes($minutes));
    }

    public function minutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function contains(self $other): bool
    {
        return $other->start >= $this->start && $other->end <= $this->end;
    }

    public function shrinkStartTo(CarbonImmutable $start): self
    {
        return new self($start->max($this->start), $this->end);
    }

    public function isPositive(): bool
    {
        return $this->end > $this->start;
    }

    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'minutes' => $this->minutes(),
        ];
    }
}
