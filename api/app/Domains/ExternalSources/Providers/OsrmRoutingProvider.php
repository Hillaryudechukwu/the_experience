<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\RoutingProvider;
use App\Domains\ExternalSources\DTO\TravelEstimate;
use App\Domains\ExternalSources\Services\OutboundHttp;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real walking routes from OSRM.
 *
 * This replaces the straight-line estimator with an actual street-network
 * route, which matters more than it sounds: in a city with a river, a rail
 * cutting or a park, the difference between crow-flight and walking distance is
 * routinely a factor of two, and the itinerary planner schedules on these
 * numbers.
 *
 * Results are returned with confidence "live". If OSRM is unreachable the call
 * falls back to the estimator and the confidence drops back to "estimate", so
 * the distinction survives the failure rather than being papered over.
 */
class OsrmRoutingProvider implements RoutingProvider
{
    public function __construct(
        private readonly OutboundHttp $http,
        private readonly EstimatorRoutingProvider $fallback,
    ) {}

    public function key(): string
    {
        return 'osrm';
    }

    public function attribution(): string
    {
        return 'Routing © OpenStreetMap contributors (ODbL)';
    }

    /**
     * The routed leg, cached.
     *
     * Separated so the caller can catch a refusal without wrapping half the
     * method in a try block. Returns null when OSRM answers but cannot route
     * it; throws when we could not ask at all — two different things, and the
     * caller treats them the same only because the traveller-facing answer is
     * the same.
     *
     * @return array{duration: float, distance: float}|null
     */
    private function lookup(string $cacheKey, GeoPoint $from, GeoPoint $to): ?array
    {
        return Cache::remember(
            $cacheKey,
            (int) config('experience.routing.cache_seconds', 86400),
            function () use ($from, $to) {
                $url = sprintf(
                    '%s/route/v1/foot/%.6f,%.6f;%.6f,%.6f',
                    rtrim((string) config('experience.routing.osrm.base_url'), '/'),
                    $from->lng,
                    $from->lat,
                    $to->lng,
                    $to->lat,
                );

                $response = $this->http->for($this->key(), 8)->get($url, ['overview' => 'false']);

                if ($response->failed() || $response->json('code') !== 'Ok') {
                    return null;
                }

                return [
                    'duration' => (float) $response->json('routes.0.duration'),
                    'distance' => (float) $response->json('routes.0.distance'),
                ];
            },
        );
    }

    public function estimate(GeoPoint $from, GeoPoint $to, string $walkingTolerance = 'medium'): TravelEstimate
    {
        $straightLine = $from->distanceTo($to);

        /* Far enough that nobody is walking it. The public OSRM instance has
           no transit graph, so the estimator's transit model is the honest
           answer out here — and it is labelled as an estimate. */
        if ($straightLine > (float) config('experience.routing.max_route_metres', 5000)) {
            return $this->fallback->estimate($from, $to, $walkingTolerance);
        }

        $cacheKey = sprintf('osrm:foot:%.4f,%.4f:%.4f,%.4f', $from->lat, $from->lng, $to->lat, $to->lng);

        try {
            $route = $this->lookup($cacheKey, $from, $to);
        } catch (Throwable $e) {
            /*
             * Backing off must mean degrading, not failing.
             *
             * OutboundHttp refuses a call that would breach the per-provider
             * rate limit by throwing, which is right — a ban is worse than a
             * slow answer. But nothing caught it here, so the refusal
             * travelled all the way out of the discovery request as a 500.
             *
             * One request routes every candidate inside the search radius,
             * which is around forty-five in London, so a traveller sharing
             * their location exhausted a sixty-a-minute budget on their second
             * pull-to-refresh and the screen simply broke. The estimator needs
             * no network at all and is labelled `estimate`, so the honest
             * degradation was available the whole time.
             */
            Log::info('routing.osrm_unavailable', [
                'reason' => $e->getMessage(),
                'fallback' => 'estimator',
            ]);

            return $this->fallback->estimate($from, $to, $walkingTolerance);
        }

        if ($route === null) {
            Log::info('routing.osrm_unavailable', ['fallback' => 'estimator']);

            return $this->fallback->estimate($from, $to, $walkingTolerance);
        }

        $metres = (int) round($route['distance']);
        $impliedKmh = $route['duration'] > 0 ? ($route['distance'] / 1000) / ($route['duration'] / 3600) : 0.0;

        /*
         * The public OSRM demo server only hosts the car profile and answers
         * /foot/ requests from it, so its durations come back at driving speed
         * — 1.8km in six minutes is 18 km/h, which is running, not walking.
         *
         * The distance is still a real street-network route and much better
         * than a straight line with a detour factor applied. So when the
         * implied pace is clearly not walking, we keep the routed distance and
         * derive the time from our own walking speed, and label the result
         * `routed` rather than `live` to say exactly that: the distance is
         * measured, the pace is modelled. A self-hosted foot profile returns a
         * plausible pace and is trusted as `live`.
         */
        $plausibleWalk = $impliedKmh > 0 && $impliedKmh <= 9.0;

        $minutes = $plausibleWalk
            ? max(1, (int) ceil($route['duration'] / 60))
            : max(1, (int) ceil((($metres / 1000) / (float) config('experience.routing.walking_kmh', 4.6)) * 60));

        $maxWalk = (int) (config('experience.routing.max_walk_minutes')[$walkingTolerance] ?? 25);

        /* A real route can still be a walk this traveller would not take. The
           walking tolerance they set is the one place that decision belongs,
           so hand it back to the estimator rather than second-guessing here. */
        if ($minutes > $maxWalk) {
            return $this->fallback->estimate($from, $to, $walkingTolerance);
        }

        return new TravelEstimate(
            minutes: $minutes,
            metres: $metres,
            mode: 'walk',
            confidence: $plausibleWalk ? 'live' : 'routed',
            source: $this->key(),
        );
    }
}
