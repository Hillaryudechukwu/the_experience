<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Mail\SubscriptionConfirmedMail;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * One confirmation email per paid period that opens access.
 *
 * Purchase and restore both go through claim; a second restore of the same
 * month must not send again. The event row is the idempotency key.
 */
class SubscriptionConfirmationNotifier
{
    public function notifyIfNewlyEntitled(Subscription $subscription): void
    {
        if (! $subscription->canUse()) {
            return;
        }

        $paidUntil = $subscription->paid_until;
        if ($paidUntil === null) {
            return;
        }

        $user = User::query()->whereKey($subscription->user_id)->first();
        if ($user === null || blank($user->email)) {
            return;
        }

        $notificationId = 'mail:subscription_confirmed:'.$subscription->id.':'.$paidUntil->toIso8601String();

        if (SubscriptionEvent::query()
            ->where('store', $subscription->store)
            ->where('notification_id', $notificationId)
            ->exists()) {
            return;
        }

        /* Savepoint so a unique race on Postgres does not abort the request. */
        try {
            DB::transaction(function () use ($subscription, $notificationId) {
                SubscriptionEvent::query()->create([
                    'subscription_id' => $subscription->id,
                    'store' => $subscription->store,
                    'notification_id' => $notificationId,
                    'type' => 'confirmation_mail',
                    'payload_hash' => hash('sha256', $notificationId),
                    'payload' => ['source' => 'confirmation_mail'],
                    'received_at' => CarbonImmutable::now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $storeLabel = $subscription->store === 'apple' ? 'App Store' : 'Google Play';
        $price = (string) config('experience.legal.subscription_price', '£1.99');

        Mail::to($user->email)->send(new SubscriptionConfirmedMail(
            travellerName: (string) ($user->name ?? ''),
            priceLabel: $price,
            storeLabel: $storeLabel,
            paidUntilLabel: $paidUntil->timezone(config('app.timezone'))->format('j F Y'),
        ));
    }
}
