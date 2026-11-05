<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Contracts;

use App\Domains\ExternalSources\DTO\LiveEvent;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;

/**
 * Dated things to go to: gigs, matches, shows.
 *
 * Experiences are evergreen and live in our catalogue; events are not, and
 * are read from the provider on demand rather than imported.
 */
interface EventProvider
{
    public function key(): string;

    public function isConfigured(): bool;

    /** @return list<LiveEvent> */
    public function near(GeoPoint $point, int $radiusKm, CarbonImmutable $from, CarbonImmutable $to, int $limit): array;
}
