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

        /* A refunded transaction still carries a valid signature. It is
           not a month, and posting it again must not undo the refund. */
        if (isset($claims['revocationDate'])) {
            return response()->json(['code' => 'revoked'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $paidUntil = CarbonImmutable::createFromTimestampMs((int) $expiresMs);

        /* A lapsed transaction says nothing about now. Applying it would
           mark a row Active (or overwrite a renewal's state) for a period
           that has ended. */
        if (! $paidUntil->isFuture()) {
            return response()->json(['code' => 'expired'], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            paidUntil: $paidUntil,
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

        $period = $this->googlePeriod($detail);

        if ($period === null) {
            return response()->json(['code' => 'incomplete_receipt'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        [$state, $paid] = $period;
        $expiry = CarbonImmutable::createFromTimestampMs((int) $detail['expiryTimeMillis']);

        $environment = app()->environment('production') ? 'production' : 'sandbox';

        $notification = new StoreNotification(
            store: 'google',
            originalTransactionId: $validated['purchase_token'],
            state: $state,
            productId: isset($detail['productId']) ? (string) $detail['productId'] : $productId,
            paidUntil: $paid ? $expiry : null,
            autoRenew: isset($detail['autoRenewing']) ? (bool) $detail['autoRenewing'] : $state === SubscriptionState::Active,
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

    /**
     * Play's own state for the token, in the terms GoogleNotificationMapper
     * uses for the same events: only a charge that went through moves
     * `paid_until`. In grace or on hold Play keeps serving an expiry nobody
     * paid for, so that expiry is not a month.
     *
     * @param  array<string, mixed>  $detail
     * @return array{0: SubscriptionState, 1: bool}|null State and whether the expiry was paid for; null to refuse.
     */
    private function googlePeriod(array $detail): ?array
    {
        if (isset($detail['subscriptionState'])) {
            return match ($detail['subscriptionState']) {
                'SUBSCRIPTION_STATE_ACTIVE' => [SubscriptionState::Active, true],
                /* Cancelled but paid to the end: the month keeps working. */
                'SUBSCRIPTION_STATE_CANCELED' => [SubscriptionState::ActiveUntilPeriodEnd, true],
                'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => [SubscriptionState::ActiveUntilPeriodEnd, false],
                'SUBSCRIPTION_STATE_ON_HOLD', 'SUBSCRIPTION_STATE_EXPIRED' => [SubscriptionState::Expired, false],
                'SUBSCRIPTION_STATE_PAUSED' => [SubscriptionState::Paused, false],
                'SUBSCRIPTION_STATE_PENDING' => [SubscriptionState::Pending, false],
                default => null,
            };
        }

        /* v1 fallback has no state, only whether the period was paid:
           1 received, 2 free trial. Anything else is not a month. */
        return in_array($detail['paymentState'] ?? null, [1, 2], true)
            ? [SubscriptionState::Active, true]
            : null;
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
