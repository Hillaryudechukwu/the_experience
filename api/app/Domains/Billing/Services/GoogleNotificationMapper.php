<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\StoreNotification;
use App\Domains\Billing\SubscriptionState;
use Carbon\CarbonImmutable;

/**
 * Play real-time developer notifications, in the terms of §6.
 *
 * Google has a real pause, and it arrives as two separate events that mean
 * very different things. `PAUSE_SCHEDULE_CHANGED` is somebody *asking* — the
 * month they have paid for keeps working, so it maps to
 * `active_until_period_end`. `PAUSED` is the pause actually starting, which
 * is the moment access ends.
 *
 * `ON_HOLD` and `IN_GRACE_PERIOD` are both failed payments. Neither extends
 * anything: in grace Play keeps serving an expiry nobody paid for, and on
 * hold there is no access at all.
 *
 * The period itself comes from the Play Developer API rather than the
 * notification, which carries only a purchase token. Until those credentials
 * exist, a renewal is recorded as active without moving `paid_until`, which
 * fails closed — a month that cannot be read is not a month in place.
 */
class GoogleNotificationMapper
{
    /** Play's numeric notification types. */
    private const RECOVERED = 1;
    private const RENEWED = 2;
    private const CANCELED = 3;
    private const PURCHASED = 4;
    private const ON_HOLD = 5;
    private const IN_GRACE_PERIOD = 6;
    private const RESTARTED = 7;
    private const PAUSED = 10;
    private const PAUSE_SCHEDULE_CHANGED = 11;
    private const REVOKED = 12;
    private const EXPIRED = 13;

    public function map(array $payload, ?array $subscriptionDetail = null): ?StoreNotification
    {
        $notification = $payload['subscriptionNotification'] ?? null;

        if (! is_array($notification)) {
            return null;
        }

        $token = (string) ($notification['purchaseToken'] ?? '');

        if ($token === '') {
            return null;
        }

        $type = (int) ($notification['notificationType'] ?? 0);

        /* The store's own expiry, when the Play API could be read for it. */
        $expiry = isset($subscriptionDetail['expiryTimeMillis'])
            ? CarbonImmutable::createFromTimestampMs((int) $subscriptionDetail['expiryTimeMillis'])
            : null;

        [$state, $paid, $autoRenew] = match ($type) {
            self::PURCHASED, self::RENEWED, self::RECOVERED, self::RESTARTED
                => [SubscriptionState::Active, $expiry, true],

            /* Asked for, not yet in effect. The month keeps working. */
            self::CANCELED, self::PAUSE_SCHEDULE_CHANGED
                => [SubscriptionState::ActiveUntilPeriodEnd, null, false],

            /* In effect. Access ends here. */
            self::PAUSED => [SubscriptionState::Paused, null, false],

            /* Payment failed. Play's expiry moves; ours does not. */
            self::IN_GRACE_PERIOD => [SubscriptionState::ActiveUntilPeriodEnd, null, false],
            self::ON_HOLD => [SubscriptionState::Expired, null, false],

            self::EXPIRED => [SubscriptionState::Expired, null, false],
            self::REVOKED => [SubscriptionState::Revoked, null, false],

            default => [null, null, null],
        };

        if ($state === null) {
            return null;
        }

        $resumesAt = isset($subscriptionDetail['autoResumeTimeMillis'])
            ? CarbonImmutable::createFromTimestampMs((int) $subscriptionDetail['autoResumeTimeMillis'])
            : null;

        return new StoreNotification(
            store: 'google',
            originalTransactionId: $token,
            state: $state,
            productId: isset($notification['subscriptionId']) ? (string) $notification['subscriptionId'] : null,
            paidUntil: $paid,
            autoRenew: $autoRenew,
            pauseResumesAt: $state === SubscriptionState::Paused || $type === self::PAUSE_SCHEDULE_CHANGED
                ? $resumesAt
                : null,
            environment: ($payload['testNotification'] ?? null) !== null ? 'sandbox' : 'production',
            /* Pub/Sub message ids are unique per delivery attempt, so the
               body is what identifies a repeat. */
            notificationId: null,
            type: 'google.'.$type,
            payload: $payload,
        );
    }
}
