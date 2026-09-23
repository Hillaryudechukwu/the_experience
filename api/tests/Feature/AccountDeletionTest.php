<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Bookings\BookingState;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingTraveller;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Account deletion (Apple App Store guideline 5.1.1(v)).
 *
 * An app that lets someone create an account has to let them destroy it, and
 * "destroy" has to mean the record and the credentials — not just the content
 * hanging off them, which is what /privacy/data already did.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private string $guestToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->guestToken = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
    }

    public function test_a_registered_traveller_is_erased_along_with_their_tokens(): void
    {
        $token = $this->register();
        $user = User::firstWhere('email', 'traveller@example.com');

        $this->createJourney(['Authorization' => "Bearer {$token}"]);

        $this->assertDatabaseCount('journeys', 1);
        $this->assertSame(1, $user->tokens()->count());

        $this->deleteJson('/api/auth/account', ['password' => 'correct-horse'], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->assertJsonPath('status', 'deleted');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseCount('journeys', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_wrong_password_deletes_nothing(): void
    {
        $token = $this->register();
        $this->createJourney(['Authorization' => "Bearer {$token}"]);

        $this->deleteJson('/api/auth/account', ['password' => 'not-the-password'], [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('journeys', 1);
    }

    public function test_the_password_is_required_rather_than_assumed_from_the_token(): void
    {
        $token = $this->register();

        $this->deleteJson('/api/auth/account', [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_guest_can_erase_themselves_without_a_password(): void
    {
        $this->createJourney(['X-Guest-Token' => $this->guestToken]);

        $this->assertDatabaseCount('journeys', 1);

        $this->deleteJson('/api/auth/account', [], ['X-Guest-Token' => $this->guestToken])
            ->assertOk()
            ->assertJsonPath('status', 'deleted');

        $this->assertDatabaseCount('journeys', 0);
        /* Their session specifically. The helpers in this test make unauthenticated
           calls of their own, each of which mints a session of its own, so a
           bare count would be measuring the test rather than the behaviour. */
        $this->assertDatabaseMissing('guest_sessions', ['token' => $this->guestToken]);
    }

    /**
     * A confirmed booking is a transaction with a supplier, not only our
     * record, so it survives — but nothing left on it may identify who made it.
     */
    public function test_a_confirmed_booking_survives_with_the_person_stripped_off_it(): void
    {
        $token = $this->register();
        $user = User::firstWhere('email', 'traveller@example.com');

        $booking = Booking::create([
            'reference' => 'EXP-KEEP-1',
            'user_id' => $user->id,
            'state' => BookingState::Confirmed->value,
            'provider' => 'sandbox',
            'fulfilment' => 'native',
            'total_minor' => 4500,
            'currency' => 'GBP',
            'idempotency_key' => 'keep-1',
            'confirmed_at' => now(),
        ]);

        BookingTraveller::create([
            'booking_id' => $booking->id,
            'full_name' => 'Alex Traveller',
            'type' => 'adult',
        ]);

        $this->deleteJson('/api/auth/account', ['password' => 'correct-horse'], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->assertJsonPath('retained_bookings', 1);

        $booking->refresh();

        $this->assertSame(BookingState::Confirmed, $booking->state);
        $this->assertSame(4500, (int) $booking->total_minor);
        $this->assertNull($booking->user_id);
        $this->assertNull($booking->guest_session_id);
        $this->assertDatabaseCount('booking_travellers', 0);
    }

    /** A draft never became anyone's record of anything, so it simply goes. */
    public function test_a_booking_that_never_reached_payment_is_deleted_outright(): void
    {
        $token = $this->register();
        $user = User::firstWhere('email', 'traveller@example.com');

        Booking::create([
            'reference' => 'EXP-DRAFT-1',
            'user_id' => $user->id,
            'state' => BookingState::Draft->value,
            'provider' => 'sandbox',
            'fulfilment' => 'native',
            'total_minor' => 0,
            'currency' => 'GBP',
            'idempotency_key' => 'draft-1',
        ]);

        $this->deleteJson('/api/auth/account', ['password' => 'correct-horse'], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->assertJsonPath('retained_bookings', 0);

        $this->assertDatabaseCount('bookings', 0);
    }

    private function register(): string
    {
        return $this->postJson('/api/auth/register', [
            'name' => 'Alex Traveller',
            'email' => 'traveller@example.com',
            'password' => 'correct-horse',
        ], ['X-Guest-Token' => $this->guestToken])->json('token');
    }

    private function createJourney(array $headers): void
    {
        $destinationId = $this->getJson('/api/destinations?q=london')->json('data.0.id');

        $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'holiday',
        ], $headers)->assertSuccessful();
    }
}
