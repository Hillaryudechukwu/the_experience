<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

use Carbon\CarbonImmutable;

final readonly class DateRange
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function singleDay(CarbonImmutable $day): self
    {
        return new self($day->startOfDay(), $day->endOfDay());
    }

    /** @return list<CarbonImmutable> */
    public function days(): array
    {
        $days = [];
        for ($d = $this->from->startOfDay(); $d <= $this->to; $d = $d->addDay()) {
            $days[] = $d;
        }

        return $days;
    }
}
