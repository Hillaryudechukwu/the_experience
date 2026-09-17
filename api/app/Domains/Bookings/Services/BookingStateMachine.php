<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Services;

use App\Domains\Bookings\BookingState;
use App\Domains\Bookings\Models\Booking;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingStateMachine
{
    public function transition(Booking $booking, BookingState $to, string $actor = 'system', ?string $reason = null): Booking
    {
        $from = $booking->state;

        if ($from === $to) {
            return $booking;
        }

        if (! $from->canTransitionTo($to)) {
            throw new DomainException("Illegal booking transition {$from->value} -> {$to->value}.");
        }

        return DB::transaction(function () use ($booking, $from, $to, $actor, $reason) {
            $booking->state = $to;

            if ($to === BookingState::Confirmed) {
                $booking->confirmed_at = CarbonImmutable::now();
            }
            if ($to === BookingState::Cancelled) {
                $booking->cancelled_at = CarbonImmutable::now();
            }
            if ($to === BookingState::Failed && $reason !== null) {
                $booking->failure_reason = $reason;
            }

            $booking->save();

            $booking->transitions()->create([
                'from_state' => $from->value,
                'to_state' => $to->value,
                'actor' => $actor,
                'reason' => $reason,
                'occurred_at' => CarbonImmutable::now(),
            ]);

            Log::info('booking.transition', [
                'booking_id' => $booking->id,
                'from' => $from->value,
                'to' => $to->value,
                'actor' => $actor,
            ]);

            return $booking;
        });
    }
}
