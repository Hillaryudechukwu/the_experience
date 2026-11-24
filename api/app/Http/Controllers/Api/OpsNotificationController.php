<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Billing\Services\GooglePubSubVerifier;
use App\Domains\Ops\Services\CloudBudgetAlertHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operator webhooks and readouts that are not traveller-facing.
 *
 * The budget push is public (Google calls it) and verifies its own OIDC
 * token the same way Play RTDN does. The status readout is admin-gated.
 */
class OpsNotificationController extends ApiController
{
    public function __construct(
        private readonly GooglePubSubVerifier $googleVerifier,
        private readonly CloudBudgetAlertHandler $budgetAlerts,
    ) {}

    public function googleBudget(Request $request): JsonResponse
    {
        $audience = (string) config('experience.ops.google_budget.pubsub_audience');
        $account = (string) config('experience.ops.google_budget.pubsub_service_account');

        if (! $this->googleVerifier->verify($request, $audience !== '' ? $audience : null, $account !== '' ? $account : null)) {
            return response()->json(['code' => 'unverified'], Response::HTTP_UNAUTHORIZED);
        }

        $encoded = (string) $request->input('message.data', '');
        $decoded = $encoded === '' ? null : base64_decode($encoded, true);
        $payload = $decoded === false || $decoded === null ? null : json_decode($decoded, true);

        if (! is_array($payload)) {
            return response()->json(['code' => 'malformed'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->budgetAlerts->handle($payload);

        /* Always 200 once verified: Cloud Budget retries on errors, and a
           "threshold not high enough" or "already emailed" is not a failure. */
        return response()->json(['code' => $result]);
    }

    public function budgetAlertStatus(): JsonResponse
    {
        return response()->json([
            'data' => [
                'remaining_alert_fraction' => (float) config('experience.ops.budget_remaining_alert_fraction', 0.10),
                'recipients' => $this->budgetAlerts->recipients(),
                'last_alert' => $this->budgetAlerts->lastAlert(),
            ],
        ]);
    }
}
