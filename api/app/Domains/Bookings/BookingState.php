<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

/**
 * Booking lifecycle (spec s9.4).
 *
 * A booking is never represented as a boolean: every move is an explicit,
 * validated transition that is written to booking_transitions.
 */
enum BookingState: string
{
    case Draft = 'draft';
    case Pricing = 'pricing';
    case AvailabilityConfirmed = 'availability_confirmed';
    case AwaitingPayment = 'awaiting_payment';
    case Processing = 'processing';
    case Confirmed = 'confirmed';
    case PartiallyConfirmed = 'partially_confirmed';
    case Failed = 'failed';
    case CancelRequested = 'cancel_requested';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /** @return array<string, list<string>> */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::Pricing->value, self::Failed->value, self::Cancelled->value],
            self::Pricing->value => [self::AvailabilityConfirmed->value, self::Failed->value, self::Cancelled->value],
            self::AvailabilityConfirmed->value => [self::AwaitingPayment->value, self::Failed->value, self::Cancelled->value],
            self::AwaitingPayment->value => [self::Processing->value, self::Failed->value, self::Cancelled->value],
            self::Processing->value => [self::Confirmed->value, self::PartiallyConfirmed->value, self::Failed->value],
            self::Confirmed->value => [self::CancelRequested->value],
            self::PartiallyConfirmed->value => [self::Confirmed->value, self::CancelRequested->value, self::Failed->value],
            self::CancelRequested->value => [self::Cancelled->value, self::Confirmed->value],
            self::Cancelled->value => [self::Refunded->value, self::PartiallyRefunded->value],
            self::Failed->value => [self::Draft->value],
            self::Refunded->value => [],
            self::PartiallyRefunded->value => [self::Refunded->value],
        ];
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next->value, self::transitions()[$this->value], true);
    }

    public function isTerminal(): bool
    {
        return self::transitions()[$this->value] === [];
    }

    public function isLocked(): bool
    {
        /* Paid or confirmed bookings are never rearranged silently (spec s8.2). */
        return in_array($this, [self::Confirmed, self::PartiallyConfirmed, self::Processing], true);
    }
}
