<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalSources;

use App\Domains\ExternalSources\Providers\OsrmRoutingProvider;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Backing off has to mean degrading, not failing.
 *
 * One discovery request routes every candidate inside the search radius —
 * around forty-five in London — so a traveller who shares their location
 * exhausts a sixty-a-minute budget on their second pull-to-refresh.
 * OutboundHttp refuses the call by throwing, which is correct, but nothing
 * caught it, so the refusal came out of the request as a 500 and the screen
 * broke the moment location was granted.
 */
class RoutingDegradationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('outbound:osrm');

        config([
            'experience.routing.driver' => 'osrm',
            'experience.http.rate_limits.osrm' => 1,
        ]);
    }

    public function test_a_rate_limited_route_degrades_to_an_estimate_instead_of_throwing(): void
    {
        Http::fake(['*' => Http::response($this->route())]);

        $provider = app(OsrmRoutingProvider::class);
        $from = new GeoPoint(51.5055, -0.0910);

        /* The first call spends the entire budget. */
        $first = $provider->estimate($from, new GeoPoint(51.5081, -0.0759));
        $this->assertSame('osrm', $first->source);

        /* The second is refused before it reaches the network. It must still
           answer — with a labelled estimate, not an exception. */
        $second = $provider->estimate($from, new GeoPoint(51.5194, -0.1270));

        $this->assertNotNull($second);
        $this->assertGreaterThan(0, $second->minutes);
        $this->assertSame('estimate', $second->confidence);
    }

    /** The whole point of the confidence field is that the drop is visible. */
    public function test_the_degraded_answer_does_not_claim_to_be_routed(): void
    {
        Http::fake(['*' => Http::response($this->route())]);

        $provider = app(OsrmRoutingProvider::class);
        $from = new GeoPoint(51.5055, -0.0910);

        $provider->estimate($from, new GeoPoint(51.5081, -0.0759));
        $degraded = $provider->estimate($from, new GeoPoint(51.5194, -0.1270));

        $this->assertNotSame('live', $degraded->confidence);
        $this->assertNotSame('routed', $degraded->confidence);
    }

    /** @return array<string, mixed> */
    private function route(): array
    {
        return [
            'code' => 'Ok',
            'routes' => [['duration' => 900.0, 'distance' => 1100.0]],
        ];
    }
}
