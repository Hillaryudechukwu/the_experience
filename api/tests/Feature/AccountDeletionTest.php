<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionEvent;
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

    /**
     * The subscription goes with the user, but its store notifications are
     * only nulled off it. Their bodies carry the purchase token, so those go;
     * what dedupes a redelivery stays.
     */
    public function test_store_notifications_lose_their_payload_but_keep_their_dedupe_keys(): void
    {
        $token = $this->register();
        $user = User::firstWhere('email', 'traveller@example.com');

        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'store' => 'google',
            'product_id' => 'wayoa_monthly',
            'original_transaction_id' => 'GPA.1234-5678',
            'state' => 'active',
        ]);

        $event = SubscriptionEvent::query()->create([
            'subscription_id' => $subscription->id,
            'store' => 'google',
            'notification_id' => 'msg-1',
            'type' => 'SUBSCRIPTION_RENEWED',
            'payload_hash' => str_repeat('a', 64),
            'payload' => ['purchaseToken' => 'token-that-leads-to-a-person'],
            'received_at' => now(),
        ]);

        /* Someone else's notification is not this deletion's business. */
        $other = SubscriptionEvent::query()->create([
            'subscription_id' => null,
            'store' => 'google',
            'notification_id' => 'msg-2',
            'type' => 'SUBSCRIPTION_RENEWED',
            'payload_hash' => str_repeat('b', 64),
            'payload' => ['purchaseToken' => 'unclaimed'],
            'received_at' => now(),
        ]);

        $this->deleteJson('/api/auth/account', ['password' => 'correct-horse'], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $this->assertDatabaseCount('subscriptions', 0);

        $event->refresh();
        $this->assertSame([], $event->payload);
        $this->assertNull($event->subscription_id);
        $this->assertSame('msg-1', $event->notification_id);
        $this->assertSame(str_repeat('a', 64), $event->payload_hash);

        $this->assertSame(['purchaseToken' => 'unclaimed'], $other->refresh()->payload);
    }

    /** The ledger line outlives the account, but not the retention period. */
    public function test_anonymised_bookings_are_pruned_once_retention_runs_out(): void
    {
        $token = $this->register();
        $user = User::firstWhere('email', 'traveller@example.com');

        $make = fn (string $reference, $createdAt) => tap(Booking::create([
            'reference' => $reference,
            'user_id' => $user->id,
            'state' => BookingState::Confirmed->value,
            'provider' => 'sandbox',
            'fulfilment' => 'native',
            'total_minor' => 4500,
            'currency' => 'GBP',
            'idempotency_key' => $reference,
            'confirmed_at' => $createdAt,
        ]), function (Booking $booking) use ($createdAt) {
            $booking->forceFill(['created_at' => $createdAt])->saveQuietly();
        });

        $expired = $make('EXP-OLD-1', now()->subYears(6)->subDay());
        $recent = $make('EXP-NEW-1', now()->subYears(5));

        $this->deleteJson('/api/auth/account', ['password' => 'correct-horse'], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->assertJsonPath('retained_bookings', 2);

        /* Old, but never anonymised: not the command's to decide about. */
        $untouched = Booking::create([
            'reference' => 'EXP-ORPHAN-1',
            'state' => BookingState::Confirmed->value,
            'provider' => 'sandbox',
            'fulfilment' => 'native',
            'total_minor' => 100,
            'currency' => 'GBP',
            'idempotency_key' => 'orphan-1',
        ]);
        $untouched->forceFill(['created_at' => now()->subYears(10)])->saveQuietly();

        $this->artisan('bookings:prune-anonymised')->assertSuccessful();

        $this->assertDatabaseMissing('bookings', ['id' => $expired->id]);
        $this->assertDatabaseHas('bookings', ['id' => $recent->id]);
        $this->assertDatabaseHas('bookings', ['id' => $untouched->id]);
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
