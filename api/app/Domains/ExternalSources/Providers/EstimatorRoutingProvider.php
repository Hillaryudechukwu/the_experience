<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\RoutingProvider;
use App\Domains\ExternalSources\DTO\TravelEstimate;
use App\Domains\Shared\ValueObjects\GeoPoint;

/**
 * Travel-time estimator.
 *
 * Straight-line distance corrected by a street-network detour factor, switching
 * to a transit model past a threshold. Results are always labelled
 * confidence=estimate so nothing downstream presents them as a live routing
 * answer; swap in a routing API by binding RoutingProvider elsewhere.
 */
class EstimatorRoutingProvider implements RoutingProvider
{
    public function key(): string
    {
        return 'estimator';
    }

    public function attribution(): string
    {
        return 'Travel times estimated, not routed';
    }

    public function estimate(GeoPoint $from, GeoPoint $to, string $walkingTolerance = 'medium'): TravelEstimate
    {
        $config = config('experience.routing');
        $straight = $from->distanceTo($to);
        $metres = (int) round($straight * $config['walking_detour_factor']);
        $km = $metres / 1000;

        $walkMinutes = (int) ceil(($km / $config['walking_kmh']) * 60);
        $maxWalk = $config['max_walk_minutes'][$walkingTolerance] ?? 25;

        if ($km <= $config['transit_threshold_km'] && $walkMinutes <= $maxWalk) {
            return new TravelEstimate($walkMinutes, $metres, 'walk', 'estimate', $this->key());
        }

        $transitMinutes = (int) ceil((($km / $config['transit_kmh']) * 60) + $config['transit_access_minutes']);

        return $transitMinutes < $walkMinutes
            ? new TravelEstimate($transitMinutes, $metres, 'transit', 'estimate', $this->key())
            : new TravelEstimate($walkMinutes, $metres, 'walk', 'estimate', $this->key());
    }
}
