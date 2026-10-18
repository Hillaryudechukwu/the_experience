<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Billing\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends ApiController
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * What the paywall reads (Subscription JEM §11 P1).
     *
     * Deliberately outside the product gate. A traveller with no paid month
     * has to be able to ask whether they have one — gating this behind having
     * one is a locked door with the key inside.
     */
    public function entitlement(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->entitlements->present($this->actor($request))]);
    }
}
