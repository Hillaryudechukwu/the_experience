<?php

declare(strict_types=1);

namespace App\Domains\Itineraries\Services;

use RuntimeException;

/**
 * The evening asked for cannot be planned at all — in the past, too far out,
 * too late tonight, or already taken by a fixed commitment.
 *
 * Carries a stable code so the client can offer the right way out (tomorrow,
 * an earlier start) rather than parsing the message.
 */
final class DateNightUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
