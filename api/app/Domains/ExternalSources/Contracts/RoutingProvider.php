<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Contracts;

use App\Domains\ExternalSources\DTO\TravelEstimate;
use App\Domains\Shared\ValueObjects\GeoPoint;

interface RoutingProvider
{
    public function key(): string;

    public function estimate(GeoPoint $from, GeoPoint $to, string $walkingTolerance = 'medium'): TravelEstimate;
}
