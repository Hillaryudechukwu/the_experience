<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionEvent;
use App\Domains\Billing\StoreNotification;
use App\Domains\Billing\SubscriptionState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that moves an entitlement (Subscription JEM §11 P3).
 *
 * Two rules do most of the work here.
 *
 * **A delivery is applied once.** Both stores retry, and Google in particular
 * will send the same Pub/Sub message again if the acknowledgement is slow. A
 * second copy of "renewed" that moved the period again would hand out a free
 * month for being briefly unreachable, so the store's own notification id —
 * or a hash of the payload when it gives none — makes the repeat a no-op.
 *
 * **Only a successful charge moves `paid_until`.** Grace periods and billing
 * retries extend the store's expiry without money changing hands; the JEM is
 * explicit that neither is a month in place. A mapper signals "this was paid"
 * by setting `paidUntil`, and leaves it null otherwise.
 */
class SubscriptionWriter
{
    /** @return Subscription|null The row after the event, or null if it was not ours to apply. */
    public function apply(StoreNotification $notification): ?Subscription
    {
        return DB::transaction(function () use ($notification) {
            $hash = hash('sha256', json_encode([
                $notification->store,
                $notification->originalTransactionId,
                $notification->type,
                $notification->payload,
            ], JSON_THROW_ON_ERROR));

            if ($this->alreadySeen($notification, $hash)) {
                return $this->find($notification);
            }

            $subscription = $this->find($notification);

            /*
             * A notification for a transaction nobody has claimed yet.
             *
             * It is recorded but not applied: the row is created by the
             * device posting its receipt, because that is the only moment we
             * learn which account the month belongs to. Apple and Google know
             * the transaction; neither knows who signed in here.
             */
            if ($subscription !== null) {
                $subscription->fill($this->changes($subscription, $notification))->save();
            }

            SubscriptionEvent::query()->create([
                'subscription_id' => $subscription?->id,
                'store' => $notification->store,
                'notification_id' => $notification->notificationId,
                'type' => $notification->type,
                'payload_hash' => $hash,
                'payload' => $notification->payload,
                'received_at' => CarbonImmutable::now(),
            ]);

            return $subscription;
        });
    }

    /** @return array<string, mixed> */
    private function changes(Subscription $subscription, StoreNotification $notification): array
    {
        $changes = ['state' => $notification->state];

        /*
         * Never backwards. Stores deliver out of order — a renewal and the
         * cancellation scheduled against it can arrive either way round — and
         * an older expiry overwriting a newer one would end a month early.
         */
        if ($notification->paidUntil !== null) {
            $current = $subscription->paid_until;

            if ($current === null || $notification->paidUntil->greaterThan($current)) {
                $changes['paid_until'] = $notification->paidUntil;
            }
        }

        if ($notification->autoRenew !== null) {
            $changes['auto_renew'] = $notification->autoRenew;
        }

        if ($notification->productId !== null) {
            $changes['product_id'] = $notification->productId;
        }

        /* Cleared on anything that is not a scheduled pause, so a resumed
           subscription does not keep advertising a date that has passed. */
        $changes['pause_resumes_at'] = $notification->pauseResumesAt;

        return $changes;
    }

    private function find(StoreNotification $notification): ?Subscription
    {
        return Subscription::query()
            ->where('store', $notification->store)
            ->where('original_transaction_id', $notification->originalTransactionId)
            ->first();
    }

    private function alreadySeen(StoreNotification $notification, string $hash): bool
    {
        $query = SubscriptionEvent::query()->where('store', $notification->store);

        /* The id is the store's own and is the better key. The hash catches
           the deliveries that carry none, and a replay of an identical body. */
        return $notification->notificationId !== null
            ? (clone $query)->where('notification_id', $notification->notificationId)->exists()
            : $query->where('payload_hash', $hash)->exists();
    }

    /** The state a row should hold when nothing has been heard yet. */
    public function pendingState(): SubscriptionState
    {
        return SubscriptionState::Pending;
    }
}
