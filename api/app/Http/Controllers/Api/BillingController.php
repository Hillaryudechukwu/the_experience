<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Billing\Exceptions\SubscriptionInUseException;
use App\Domains\Billing\Services\AppleTransactionVerifier;
use App\Domains\Billing\Services\EntitlementService;
use App\Domains\Billing\Services\GooglePlaySubscriptionReader;
use App\Domains\Billing\Services\SubscriptionWriter;
use App\Domains\Billing\StoreNotification;
use App\Domains\Billing\SubscriptionState;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class BillingController extends ApiController
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly SubscriptionWriter $writer,
        private readonly AppleTransactionVerifier $appleTransactions,
        private readonly GooglePlaySubscriptionReader $playSubscriptions,
    ) {}

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

    /**
     * Device posts a verified StoreKit 2 transaction after purchase or restore
     * (Subscription JEM §11 P3 / P4).
     */
    public function refreshApple(Request $request): JsonResponse
    {
        $userId = $this->requireSignedInUserId($request);

        $validated = $request->validate([
            'signed_transaction' => ['required', 'string'],
        ]);

        $claims = $this->appleTransactions->verify($validated['signed_transaction']);

        if ($claims === null) {
            return response()->json(['code' => 'unverified'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $expiresMs = $claims['expiresDate'] ?? null;

        if ($expiresMs === null) {
            return response()->json(['code' => 'incomplete_receipt'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $environment = strtolower((string) ($claims['environment'] ?? 'Production')) === 'sandbox'
            ? 'sandbox'
            : 'production';

        if (app()->isProduction() && $environment === 'sandbox') {
            Log::warning('Apple sandbox receipt refused in production.');

            return response()->json(['code' => 'sandbox_not_accepted'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $notification = new StoreNotification(
            store: 'apple',
            originalTransactionId: (string) $claims['originalTransactionId'],
            state: SubscriptionState::Active,
            productId: isset($claims['productId']) ? (string) $claims['productId'] : null,
            paidUntil: CarbonImmutable::createFromTimestampMs((int) $expiresMs),
            autoRenew: true,
            environment: $environment,
            notificationId: isset($claims['transactionId'])
                ? 'refresh:apple:'.(string) $claims['transactionId']
                : null,
            type: 'refresh',
            payload: ['source' => 'refresh', 'transaction' => $claims],
        );

        return $this->claimAndPresent($request, $userId, $notification);
    }

    /**
     * Device posts a Play purchase token after purchase or restore.
     */
    public function refreshGoogle(Request $request): JsonResponse
    {
        $userId = $this->requireSignedInUserId($request);

        $validated = $request->validate([
            'purchase_token' => ['required', 'string'],
            'product_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $productId = $validated['product_id']
            ?? (string) config('experience.billing.products.google');

        $detail = $this->playSubscriptions->read(
            $validated['purchase_token'],
            is_string($productId) ? $productId : null,
        );

        if ($detail === null || ! isset($detail['expiryTimeMillis'])) {
            return response()->json(['code' => 'incomplete_receipt'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $environment = app()->environment('production') ? 'production' : 'sandbox';

        $notification = new StoreNotification(
            store: 'google',
            originalTransactionId: $validated['purchase_token'],
            state: SubscriptionState::Active,
            productId: isset($detail['productId']) ? (string) $detail['productId'] : $productId,
            paidUntil: CarbonImmutable::createFromTimestampMs((int) $detail['expiryTimeMillis']),
            autoRenew: isset($detail['autoRenewing']) ? (bool) $detail['autoRenewing'] : true,
            pauseResumesAt: isset($detail['autoResumeTimeMillis'])
                ? CarbonImmutable::createFromTimestampMs((int) $detail['autoResumeTimeMillis'])
                : null,
            environment: $environment,
            notificationId: 'refresh:google:'.hash('sha256', $validated['purchase_token'].':'.(string) $detail['expiryTimeMillis']),
            type: 'refresh',
            payload: ['source' => 'refresh', 'detail' => $detail],
        );

        return $this->claimAndPresent($request, $userId, $notification);
    }

    private function claimAndPresent(Request $request, int $userId, StoreNotification $notification): JsonResponse
    {
        try {
            $this->writer->claim($userId, $notification);
        } catch (SubscriptionInUseException) {
            return response()->json([
                'code' => 'subscription_in_use',
                'message' => 'This subscription is already linked to another account.',
            ], Response::HTTP_CONFLICT);
        }

        Log::info('Billing refresh applied.', [
            'store' => $notification->store,
            'user_id' => $userId,
            'result' => 'applied',
        ]);

        return response()->json(['data' => $this->entitlements->present($this->actor($request))]);
    }

    private function requireSignedInUserId(Request $request): int
    {
        $userId = $this->actor($request)->userId;

        if ($userId === null) {
            abort(Response::HTTP_UNAUTHORIZED, 'Sign in before refreshing a subscription.');
        }

        return $userId;
    }
}
