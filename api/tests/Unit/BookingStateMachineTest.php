<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Bookings\BookingState;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Services\BookingStateMachine;
use App\Domains\Bookings\Services\IdempotencyGuard;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BookingStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legal_transition_is_recorded(): void
    {
        $booking = $this->booking();
        $machine = app(BookingStateMachine::class);

        $machine->transition($booking, BookingState::Pricing, 'system', 'Checking price');

        $this->assertSame(BookingState::Pricing, $booking->fresh()->state);
        $this->assertDatabaseHas('booking_transitions', [
            'booking_id' => $booking->id,
            'from_state' => 'draft',
            'to_state' => 'pricing',
            'reason' => 'Checking price',
        ]);
    }

    public function test_an_illegal_transition_is_refused(): void
    {
        $booking = $this->booking();

        $this->expectException(DomainException::class);
        app(BookingStateMachine::class)->transition($booking, BookingState::Refunded);
    }

    public function test_confirmed_and_processing_bookings_are_locked_against_silent_rescheduling(): void
    {
        $this->assertTrue(BookingState::Confirmed->isLocked());
        $this->assertTrue(BookingState::Processing->isLocked());
        $this->assertFalse(BookingState::Draft->isLocked());
    }

    public function test_the_idempotency_guard_replays_rather_than_repeating(): void
    {
        $guard = app(IdempotencyGuard::class);
        $calls = 0;

        $operation = function () use (&$calls) {
            $calls++;

            return ['booking' => 'first'];
        };

        $first = $guard->run('booking.create', 'key-1', ['a' => 1], $operation);
        $second = $guard->run('booking.create', 'key-1', ['a' => 1], $operation);

        $this->assertSame(1, $calls, 'The operation must only run once for a given key.');
        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame(['booking' => 'first'], $second['response']);
    }

    public function test_reusing_a_key_with_a_different_body_is_an_error(): void
    {
        $guard = app(IdempotencyGuard::class);
        $guard->run('booking.create', 'key-2', ['a' => 1], fn () => ['ok' => true]);

        $this->expectException(RuntimeException::class);
        $guard->run('booking.create', 'key-2', ['a' => 2], fn () => ['ok' => true]);
    }

    private function booking(): Booking
    {
        return Booking::create([
            'reference' => 'EXP-TEST0001',
            'state' => BookingState::Draft,
            'provider' => 'sandbox',
            'currency' => 'GBP',
        ]);
    }
}
