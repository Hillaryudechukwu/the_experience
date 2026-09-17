<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Contracts;

use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Collection;

/**
 * A source of real-world place data (spec s9.2, s11).
 *
 * Deliberately separate from ExperienceProvider: knowing that the Tower of
 * London exists is a different concern from selling a ticket to it, and the two
 * have different reliability, licensing and refresh characteristics.
 */
interface PlaceDataProvider
{
    public function key(): string;

    public function isConfigured(): bool;

    /** Attribution this provider's licence requires us to display. */
    public function attribution(): string;

    /**
     * @param  list<string>  $kinds  our own kind vocabulary, e.g. ['museum', 'viewpoint']
     * @return Collection<int, PlaceCandidate>
     */
    public function searchNearby(GeoPoint $centre, int $radiusMetres, array $kinds = [], int $limit = 60): Collection;

    public function getPlace(string $providerId): ?PlaceCandidate;
}
