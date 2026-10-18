<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Billing\Services\EntitlementService;
use App\Domains\Shared\ValueObjects\Actor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The product is closed unless a paid month covers now (Subscription JEM §11 P2).
 *
 * This runs before the controller, so no provider is called and no
 * recommendation set is written for a request that is not entitled — the
 * whole point is that discovery, the guide and activation spend real money at
 * Google, Viator and Anthropic.
 *
 * 402 rather than 403: the traveller is not forbidden, they have not paid.
 * The client turns this specific code into the paywall, so it has to be
 * distinguishable from an authorisation failure.
 */
class RequireSubscription
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        /* Off until the stores can sell the month. See experience.billing. */
        if (! config('experience.billing.enforced')) {
            return $next($request);
        }

        $actor = $request->attributes->get('actor') ?? new Actor;

        if ($this->entitlements->canUse($actor)) {
            return $next($request);
        }

        return response()->json([
            'code' => 'subscription_required',
            'message' => 'WAYOA needs an active month. Subscribe to carry on.',
        ], Response::HTTP_PAYMENT_REQUIRED);
    }
}
