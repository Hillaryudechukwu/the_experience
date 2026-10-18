<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireSubscription;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Which routes the paywall covers, written down (Subscription JEM §11 P2, P6).
 *
 * The suite runs with enforcement off, because the gate ships long before the
 * stores can sell a month and turning it on by default would mean every
 * product test asserting a 402 instead of the behaviour it was written for.
 * The cost of that choice is that nothing else would notice a product route
 * added without the gate — it would simply work, in tests and in development,
 * and the omission would surface as a free feature in production.
 *
 * So the boundary is an assertion rather than a convention. Adding a route
 * fails this test until someone decides, deliberately, which side it is on.
 */
class SubscriptionGateCoverageTest extends TestCase
{
    /** Everything that spends money at a provider, or reads what that bought. */
    private const GATED = [
        'api/assistant/conversations/{conversation}',
        'api/assistant/message',
        'api/bookings',
        'api/bookings/{booking}',
        'api/bookings/{booking}/cancel',
        'api/destination-imports/{import}/retry',
        'api/destinations/activate',
        'api/discovery/intent',
        'api/discovery/mood',
        'api/discovery/now',
        'api/discovery/search',
        'api/discovery/surprise-me',
        'api/discovery/time-boxed',
        'api/experiences/saved',
        'api/experiences/{experience}',
        'api/experiences/{experience}/availability',
        'api/experiences/{experience}/complete',
        'api/experiences/{experience}/offers',
        'api/experiences/{experience}/save',
        'api/itineraries/{itinerary}/items',
        'api/itinerary-items/{item}',
        'api/trips',
        'api/trips/{trip}',
        'api/trips/{trip}/generate-itinerary',
        'api/trips/{trip}/itineraries/{itinerary}/accept',
        'api/trips/{trip}/replan',
    ];

    /**
     * Setup, identity, the law, and the way out.
     *
     * Each of these is open for a reason that survives the paywall. A
     * traveller with no month still has to be able to ask whether they have
     * one, read what they are being asked to agree to, and delete their
     * account — the last two are rejections on both stores if they are not.
     */
    private const MUST_STAY_OPEN = [
        'api/auth/account',
        'api/auth/login',
        'api/auth/register',
        'api/billing/entitlement',
        'api/health',
        'api/journeys',
        'api/privacy/export',
        'api/traveller/profile',
    ];

    /** @return list<string> */
    private function urisGatedBy(bool $gated): array
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            /* The route keeps the alias it was written with; only the kernel
               knows the class behind it. Accept either spelling so this does
               not quietly pass by matching nothing. */
            $middleware = $route->gatherMiddleware();
            $carries = in_array(RequireSubscription::class, $middleware, true)
                || in_array('subscribed', $middleware, true);

            if ($carries === $gated && str_starts_with($route->uri(), 'api/')) {
                $found[$route->uri()] = true;
            }
        }

        $uris = array_keys($found);
        sort($uris);

        return $uris;
    }

    public function test_the_paywall_covers_exactly_the_product(): void
    {
        $gated = $this->urisGatedBy(true);
        $expected = self::GATED;
        sort($expected);

        $this->assertSame(
            $expected,
            $gated,
            "The set of routes behind the paywall has changed.\n".
            "A new product route needs the gate; a route that should be free needs removing from this list.\n".
            'Either way it is a decision, not an oversight.',
        );
    }

    public function test_the_ways_in_and_out_are_never_behind_the_paywall(): void
    {
        $gated = $this->urisGatedBy(true);

        foreach (self::MUST_STAY_OPEN as $uri) {
            $this->assertNotContains(
                $uri,
                $gated,
                $uri.' must stay reachable without a paid month.',
            );
        }
    }

    /** The gate is wired at all — a guard that is never reached guards nothing. */
    public function test_the_middleware_is_registered(): void
    {
        $this->assertNotEmpty($this->urisGatedBy(true));
    }
}
