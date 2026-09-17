<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalSources;

use App\Domains\ExternalSources\Providers\FrankfurterCurrencyProvider;
use App\Domains\ExternalSources\Providers\OsrmRoutingProvider;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoutingAndCurrencyTest extends TestCase
{
    public function test_a_walking_paced_route_is_trusted_as_live(): void
    {
        /* 1150m in 15 minutes is 4.6 km/h — a real foot profile. */
        Http::fake(['*' => Http::response(['code' => 'Ok', 'routes' => [['duration' => 900.0, 'distance' => 1150.0]]])]);

        $estimate = app(OsrmRoutingProvider::class)->estimate(
            new GeoPoint(51.5074, -0.1278),
            new GeoPoint(51.5085, -0.1180),
        );

        $this->assertSame('live', $estimate->confidence);
        $this->assertSame('osrm', $estimate->source);
        $this->assertSame(15, $estimate->minutes);
        $this->assertSame(1150, $estimate->metres);
    }

    public function test_a_driving_paced_duration_is_not_passed_off_as_a_walk(): void
    {
        /*
         * The public OSRM demo answers /foot/ from its car profile. 1796m in
         * six minutes is 18 km/h. We keep the real routed distance but derive
         * the time from walking speed, and say so in the confidence field.
         */
        Http::fake(['*' => Http::response(['code' => 'Ok', 'routes' => [['duration' => 360.0, 'distance' => 1796.0]]])]);

        $estimate = app(OsrmRoutingProvider::class)->estimate(
            new GeoPoint(51.5074, -0.1278),
            new GeoPoint(51.5085, -0.1180),
            'high',
        );

        $this->assertSame('routed', $estimate->confidence);
        $this->assertSame(1796, $estimate->metres, 'The measured distance is still worth having.');
        $this->assertSame(24, $estimate->minutes, 'Time comes from our walking speed, not the car profile.');
    }

    public function test_a_routed_walk_differs_from_the_straight_line_it_replaces(): void
    {
        /* The point of routing at all: a river, a rail cutting or a park makes
           walking distance materially longer than crow-flight, and the
           itinerary planner schedules on these numbers. */
        Http::fake(['*' => Http::response(['code' => 'Ok', 'routes' => [['duration' => 1800.0, 'distance' => 2300.0]]])]);

        $from = new GeoPoint(51.5074, -0.1278);
        $to = new GeoPoint(51.5140, -0.1100);

        $routed = app(OsrmRoutingProvider::class)->estimate($from, $to, 'high');
        $straightLine = (int) round($from->distanceTo($to));

        $this->assertGreaterThan($straightLine, $routed->metres);
    }

    public function test_an_unreachable_router_falls_back_and_says_so(): void
    {
        Http::fake(['*' => Http::response('gateway timeout', 504)]);

        $estimate = app(OsrmRoutingProvider::class)->estimate(
            new GeoPoint(51.5074, -0.1278),
            new GeoPoint(51.5085, -0.1180),
        );

        $this->assertSame('estimate', $estimate->confidence, 'The distinction must survive the failure.');
        $this->assertSame('estimator', $estimate->source);
        $this->assertGreaterThan(0, $estimate->minutes);
    }

    public function test_journeys_beyond_walking_range_do_not_ask_a_foot_router(): void
    {
        Http::fake();

        $estimate = app(OsrmRoutingProvider::class)->estimate(
            new GeoPoint(51.5074, -0.1278),
            new GeoPoint(51.4769, -0.0005),      // Greenwich, ~9km — well past walking
        );

        Http::assertNothingSent();
        $this->assertSame('estimate', $estimate->confidence);
        $this->assertSame('transit', $estimate->mode);
    }

    public function test_an_exchange_rate_is_fetched_with_the_date_it_applies_to(): void
    {
        Http::fake(['*' => Http::response(['base' => 'GBP', 'date' => '2026-09-16', 'rates' => ['EUR' => 1.1663]])]);

        $rate = app(FrankfurterCurrencyProvider::class)->rate('GBP', 'EUR');

        $this->assertSame(1.1663, $rate['rate']);
        $this->assertSame('2026-09-16', $rate['as_of']);
        $this->assertSame('frankfurter', $rate['source']);
    }

    public function test_converting_a_currency_to_itself_needs_no_request(): void
    {
        Http::fake();

        $this->assertSame(1.0, app(FrankfurterCurrencyProvider::class)->rate('GBP', 'GBP')['rate']);
        Http::assertNothingSent();
    }
}
