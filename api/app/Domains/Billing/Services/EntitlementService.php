<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\SubscriptionState;
use App\Domains\Shared\ValueObjects\Actor;
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

        return Subscription::query()
            ->where('user_id', $actor->userId)
            ->where('state', '!=', SubscriptionState::Revoked)
            ->orderByDesc('paid_until')
            ->first();
    }

    public function canUse(Actor $actor, ?CarbonImmutable $now = null): bool
    {
        return $this->current($actor)?->canUse($now) ?? false;
    }

    /**
     * What the entitlement route and the paywall both read.
     *
     * @return array{can_use: bool, state: string, paid_until: ?string, auto_renew: bool, pause_resumes_at: ?string, store: ?string}
     */
    public function present(Actor $actor, ?CarbonImmutable $now = null): array
    {
        $subscription = $this->current($actor);

        if ($subscription === null) {
            return [
                'can_use' => false,
                'state' => SubscriptionState::Inactive->value,
                'paid_until' => null,
                'auto_renew' => false,
                'pause_resumes_at' => null,
                'store' => null,
            ];
        }

        return [
            'can_use' => $subscription->canUse($now),
            'state' => $subscription->state->value,
            'paid_until' => $subscription->paid_until?->toIso8601String(),
            'auto_renew' => (bool) $subscription->auto_renew,
            'pause_resumes_at' => $subscription->pause_resumes_at?->toIso8601String(),
            'store' => $subscription->store,
        ];
    }
}
