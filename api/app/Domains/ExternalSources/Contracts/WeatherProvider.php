<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Contracts;

use App\Domains\ExternalSources\DTO\WeatherSnapshot;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;

interface WeatherProvider
{
    public function key(): string;

    public function forecast(GeoPoint $point, CarbonImmutable $from, int $hours = 24): WeatherSnapshot;
}
