<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\StoreNotification;
use App\Domains\Billing\SubscriptionState;
use Carbon\CarbonImmutable;

/**
 * App Store Server Notifications V2, in the terms of §6.
 *
 * The subtleties are all about what does *not* count:
 *
 * `DID_FAIL_TO_RENEW` arrives when a charge fails, and Apple keeps serving an
 * expiry through the retry window. That expiry is not a month anyone paid
 * for, so nothing is written to `paid_until` and the period already recorded
 * runs out on its own.
 *
 * `DID_CHANGE_RENEWAL_STATUS` with `AUTO_RENEW_DISABLED` is somebody
 * cancelling. It must not shorten anything — the month keeps working, which
 * is the whole of the product's promise about pausing.
 *
 * Apple has no pause. A traveller who stops renewal comes back by buying
 * again, which is why nothing here ever produces `Paused`.
 */
class AppleNotificationMapper
{
    public function map(array $payload): ?StoreNotification
    {
        $type = (string) ($payload['notificationType'] ?? '');
        $subtype = (string) ($payload['subtype'] ?? '');
        $data = $payload['data'] ?? [];
        $transaction = $data['signedTransactionInfo'] ?? [];
        $renewal = $data['signedRenewalInfo'] ?? [];

        $originalTransactionId = (string) ($transaction['originalTransactionId'] ?? '');

        if ($originalTransactionId === '') {
            return null;
        }

        $expiry = isset($transaction['expiresDate'])
            ? CarbonImmutable::createFromTimestampMs((int) $transaction['expiresDate'])
            : null;

        [$state, $paidUntil, $autoRenew] = match (true) {
            /* Money changed hands. These are the only branches that move the
               period, and the expiry they carry is the one that was paid for. */
            $type === 'SUBSCRIBED',
            $type === 'DID_RENEW',
            $type === 'OFFER_REDEEMED' => [SubscriptionState::Active, $expiry, true],

            /* Cancelled, or re-enabled, without the period moving either way. */
            $type === 'DID_CHANGE_RENEWAL_STATUS' && $subtype === 'AUTO_RENEW_DISABLED'
                => [SubscriptionState::ActiveUntilPeriodEnd, null, false],
            $type === 'DID_CHANGE_RENEWAL_STATUS'
                => [SubscriptionState::Active, null, true],

            /* The retry window. Apple's expiry moves; ours does not. */
            $type === 'DID_FAIL_TO_RENEW' => [SubscriptionState::ActiveUntilPeriodEnd, null, false],

            $type === 'EXPIRED', $type === 'GRACE_PERIOD_EXPIRED'
                => [SubscriptionState::Expired, null, false],

            $type === 'REFUND', $type === 'REVOKE'
                => [SubscriptionState::Revoked, null, false],

            default => [null, null, null],
        };

        if ($state === null) {
            return null;
        }

        return new StoreNotification(
            store: 'apple',
            originalTransactionId: $originalTransactionId,
            state: $state,
            productId: isset($transaction['productId']) ? (string) $transaction['productId'] : null,
            paidUntil: $paidUntil,
            autoRenew: $autoRenew ?? (isset($renewal['autoRenewStatus']) ? (bool) $renewal['autoRenewStatus'] : null),
            environment: strtolower((string) ($data['environment'] ?? 'production')) === 'sandbox'
                ? 'sandbox'
                : 'production',
            notificationId: isset($payload['notificationUUID']) ? (string) $payload['notificationUUID'] : null,
            type: $type.($subtype !== '' ? '.'.$subtype : ''),
            payload: $payload,
        );
    }
}
