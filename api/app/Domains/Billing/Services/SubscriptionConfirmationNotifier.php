<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Mail\SubscriptionConfirmedMail;
use App\Domains\Billing\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * One confirmation email per paid period that opens access.
 *
 * Purchase and restore both go through claim; a second restore of the same
 * month must not send again. `confirmation_mailed_for` on the subscription
 * remembers which period was confirmed, and is claimed with a conditional
 * update so two refreshes racing cannot both send.
 */
class SubscriptionConfirmationNotifier
{
    /** @return bool Whether a confirmation was queued by this call. */
    public function notifyIfNewlyEntitled(Subscription $subscription): bool
    {
        if (! $subscription->canUse()) {
            return false;
        }

        $paidUntil = $subscription->paid_until;
        if ($paidUntil === null) {
            return false;
        }

        $user = User::query()->whereKey($subscription->user_id)->first();
        if ($user === null || blank($user->email)) {
            return false;
        }

        $storeLabel = $subscription->store === 'apple' ? 'App Store' : 'Google Play';

        /*
         * The mark and the queued job commit together: on the database queue
         * the job row is written inside this transaction, so a push that fails
         * rolls the mark back and the next refresh tries again, rather than a
         * period marked as emailed that never was.
         */
        return DB::transaction(function () use ($subscription, $paidUntil, $user, $storeLabel): bool {
            $claimed = Subscription::query()
                ->whereKey($subscription->id)
                ->where(fn ($query) => $query
                    ->whereNull('confirmation_mailed_for')
                    ->orWhere('confirmation_mailed_for', '!=', $paidUntil))
                ->update(['confirmation_mailed_for' => $paidUntil]);

            if ($claimed === 0) {
                return false;
            }

            Mail::to($user->email)->send(new SubscriptionConfirmedMail(
                travellerName: (string) ($user->name ?? ''),
                storeLabel: $storeLabel,
                paidUntilLabel: $paidUntil->timezone(config('app.timezone'))->format('j F Y'),
            ));

            return true;
        });
    }
}
