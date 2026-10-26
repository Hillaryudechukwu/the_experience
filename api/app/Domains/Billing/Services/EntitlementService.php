<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\SubscriptionState;
use App\Domains\Shared\ValueObjects\Actor;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * "Can this account use WAYOA right now?" (Subscription JEM §6).
 *
 * The one place that answers it. The phone renders a paywall from this and
 * the middleware refuses from this, so the two can never disagree about who
 * is allowed in — the client gate exists to show a paywall instead of an
 * error, not to make the decision.
 *
 * Guests are never entitled. Not because a guest is suspicious, but because
 * a receipt belongs to an account: there is nothing to restore onto, and
 * nothing to stop a second device claiming the same month.
 */
class EntitlementService
{
    /** The row that decides, or null. Revoked rows are history, not access. */
    public function current(Actor $actor): ?Subscription
    {
        if ($actor->userId === null) {
            return null;
        }

        /*
         * The furthest-reaching paid period, and a row that has none can
         * never be it.
         *
         * `orderByDesc` alone was not portable: PostgreSQL sorts NULLs first
         * on a descending order and MySQL sorts them last, so a traveller
         * holding both a pending row and an active one resolved to a
         * different subscription on the database we test against than on the
         * one we deploy to — and on PostgreSQL that meant refusing somebody
         * who had paid. Excluding the rows with no period removes the
         * question rather than answering it per driver.
         */
        return Subscription::query()
            ->where('user_id', $actor->userId)
            ->where('state', '!=', SubscriptionState::Revoked)
            ->whereNotNull('paid_until')
            ->orderByDesc('paid_until')
            ->first()
            /* Nothing paid for yet, but there may still be a purchase in
               flight, and the paywall says "still confirming" from it. */
            ?? Subscription::query()
                ->where('user_id', $actor->userId)
                ->where('state', '!=', SubscriptionState::Revoked)
                ->orderByDesc('created_at')
                ->first();
    }

    public function canUse(Actor $actor, ?CarbonImmutable $now = null): bool
    {
        return $this->isTester($actor) || ($this->current($actor)?->canUse($now) ?? false);
    }

    /**
     * Accounts let in without a purchase, for testing on real devices before
     * the store can sell the month (experience.billing.tester_emails).
     *
     * Matched on the signed-in account's email, so a guest never qualifies.
     */
    public function isTester(Actor $actor): bool
    {
        $testers = (array) config('experience.billing.tester_emails', []);

        if ($actor->userId === null || $testers === []) {
            return false;
        }

        $email = User::query()->whereKey($actor->userId)->value('email');

        return is_string($email) && in_array(mb_strtolower(trim($email)), $testers, true);
    }

    /**
     * What the entitlement route and the paywall both read.
     *
     * @return array{can_use: bool, state: string, paid_until: ?string, auto_renew: bool, pause_resumes_at: ?string, store: ?string}
     */
    public function present(Actor $actor, ?CarbonImmutable $now = null): array
    {
        $subscription = $this->current($actor);
        $accessIsOpen = ! config('experience.billing.enforced') || $this->isTester($actor);

        if ($subscription === null) {
            return [
                /* The API gate is deliberately open until store billing is
                   enabled. Report the same effective access decision to the
                   client so it does not impose a paywall the server disabled. */
                'can_use' => $accessIsOpen,
                'state' => SubscriptionState::Inactive->value,
                'paid_until' => null,
                'auto_renew' => false,
                'pause_resumes_at' => null,
                'store' => null,
            ];
        }

        return [
            'can_use' => $accessIsOpen || $subscription->canUse($now),
            'state' => $subscription->state->value,
            'paid_until' => $subscription->paid_until?->toIso8601String(),
            'auto_renew' => (bool) $subscription->auto_renew,
            'pause_resumes_at' => $subscription->pause_resumes_at?->toIso8601String(),
            'store' => $subscription->store,
        ];
    }
}
