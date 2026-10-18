<?php

declare(strict_types=1);

namespace App\Domains\Billing;

/**
 * Subscription JEM §6 — the states, and which of them are a month in place.
 *
 * Two of these allow use. `ActiveUntilPeriodEnd` is the one that carries the
 * product principle: asking to pause or cancel never shortens the month
 * already paid for, so it keeps working until `paid_until` passes.
 */
enum SubscriptionState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case ActiveUntilPeriodEnd = 'active_until_period_end';
    case Paused = 'paused';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Inactive = 'inactive';

    /** Whether this state permits use, given a paid period that still covers now. */
    public function allowsUse(): bool
    {
        return $this === self::Active || $this === self::ActiveUntilPeriodEnd;
    }
}
