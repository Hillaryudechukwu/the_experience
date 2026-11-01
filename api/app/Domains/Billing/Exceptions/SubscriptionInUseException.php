<?php

declare(strict_types=1);

namespace App\Domains\Billing\Exceptions;

use RuntimeException;

/**
 * A store original transaction already belongs to another live account
 * (Subscription JEM §11 P4 — restore must not clone access).
 */
final class SubscriptionInUseException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This subscription is already linked to another account.');
    }
}
