<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Bookings\BookingState;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Services\BookingStateMachine;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Notifications\Mail\TravellerNotificationMail;
use App\Domains\Notifications\Models\DevicePushToken;
use App\Domains\Notifications\Models\NotificationOutbox;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Notifications\Services\NotificationEnqueuer;
use App\Domains\Shared\ValueObjects\Actor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_a_booking_enqueues_one_row_even_if_asked_twice(): void
    {
        $user = User::factory()->create();
        $booking = Booking::query()->create([
            'reference' => 'NT-'.Str::upper(Str::random(6)),
            'user_id' => $user->id,
            'state' => BookingState::Processing,
            'provider' => 'sandbox',
            'fulfilment' => 'native',
        ]);

        app(BookingStateMachine::class)->transition($booking, BookingState::Confirmed, 'provider');
        app(NotificationEnqueuer::class)->bookingConfirmed($booking->fresh());

        $this->assertSame(1, NotificationOutbox::query()->where('kind', 'booking_confirmed')->count());
        $this->assertDatabaseHas('notifications_outbox', [
            'idempotency_key' => 'booking_confirmed:'.$booking->id,
            'user_id' => $user->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_flush_emails_a_registered_traveller_once(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $enqueuer = app(NotificationEnqueuer::class);
        $enqueuer->enqueue(
            new Actor(userId: $user->id),
            'booking_confirmed',
            'booking_confirmed:once',
            'Booking confirmed',
            'Your booking NT-1 is confirmed.',
            ['reference' => 'NT-1'],
        );

        $dispatcher = app(NotificationDispatcher::class);
        $this->assertSame(1, $dispatcher->flush());
        $this->assertSame(0, $dispatcher->flush());

        Mail::assertSent(TravellerNotificationMail::class, 1);
        $this->assertDatabaseHas('notifications_outbox', [
            'idempotency_key' => 'booking_confirmed:once',
            'status' => 'sent',
        ]);
    }

    public function test_a_failed_push_retries_without_sending_the_email_again(): void
    {
        Mail::fake();
        Http::fake([
            'https://exp.host/*' => Http::response('unavailable', 500),
        ]);

        $user = User::factory()->create();
        DevicePushToken::query()->create([
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[test]',
            'platform' => 'ios',
        ]);

        app(NotificationEnqueuer::class)->enqueue(
            new Actor(userId: $user->id),
            'replan_proposed',
            'replan_proposed:one',
            'A change to your plan',
            'Nothing moves until you accept it.',
        );

        app(NotificationDispatcher::class)->flush();

        $row = NotificationOutbox::query()->where('idempotency_key', 'replan_proposed:one')->first();
        $this->assertNotNull($row);
        $this->assertSame('scheduled', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertTrue($row->scheduled_for->greaterThan(CarbonImmutable::now()));
        $this->assertContains('email', $row->payload['sent_channels']);
        Mail::assertSent(TravellerNotificationMail::class, 1);

        $row->update(['scheduled_for' => CarbonImmutable::now()->subMinute()]);
        app(NotificationDispatcher::class)->flush();

        Mail::assertSent(TravellerNotificationMail::class, 1);
        $row->refresh();
        $this->assertSame(2, $row->attempts);
        $this->assertSame('scheduled', $row->status);
    }

    public function test_a_guest_without_a_device_is_kept_in_app(): void
    {
        Mail::fake();

        $guest = GuestSession::query()->create(['token' => Str::random(40)]);

        app(NotificationEnqueuer::class)->enqueue(
            new Actor(guestSessionId: $guest->id),
            'destination_import_ready',
            'destination_import_ready:city',
            'Reykjavík is ready',
            'Reykjavík has recommendations you can use now.',
        );

        app(NotificationDispatcher::class)->flush();

        Mail::assertNothingSent();
        $row = NotificationOutbox::query()->first();
        $this->assertSame('sent', $row->status);
        $this->assertSame('in_app', $row->payload['channel']);
    }

    public function test_a_configured_twilio_account_texts_the_operator_list_once(): void
    {
        Mail::fake();
        Http::fake([
            'https://api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201),
        ]);
        config([
            'services.twilio.account_sid' => 'ACexampleaccountsid',
            'services.twilio.auth_token' => 'test-token',
            'services.twilio.messaging_service_sid' => 'MGexamplemessageservice',
            'services.twilio.from' => null,
            'services.twilio.alert_recipients' => ['+447700900123'],
        ]);

        $user = User::factory()->create();
        app(NotificationEnqueuer::class)->enqueue(
            new Actor(userId: $user->id),
            'booking_confirmed',
            'booking_confirmed:sms',
            'Booking confirmed',
            'Your booking NT-1 is confirmed.',
        );

        $this->assertSame(1, app(NotificationDispatcher::class)->flush());
        $this->assertSame(0, app(NotificationDispatcher::class)->flush());

        Http::assertSentCount(1);
        $row = NotificationOutbox::query()->where('idempotency_key', 'booking_confirmed:sms')->first();
        $this->assertContains('sms', $row->payload['sent_channels']);
        $this->assertSame(['+447700900123'], $row->payload['sent_sms']);
    }

    public function test_a_device_can_register_a_push_token(): void
    {
        $guest = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        $this->withHeader('X-Guest-Token', $guest)
            ->postJson('/api/notifications/push-token', [
                'token' => 'ExponentPushToken[device]',
                'platform' => 'android',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('device_push_tokens', [
            'token' => 'ExponentPushToken[device]',
            'platform' => 'android',
        ]);
    }

    public function test_failing_a_booking_enqueues_a_failed_notice(): void
    {
        $user = User::factory()->create();
        $booking = Booking::query()->create([
            'reference' => 'NT-'.Str::upper(Str::random(6)),
            'user_id' => $user->id,
            'state' => BookingState::Processing,
            'provider' => 'sandbox',
            'fulfilment' => 'native',
        ]);

        app(BookingStateMachine::class)->transition($booking, BookingState::Failed, 'provider', 'Supplier declined');

        $this->assertDatabaseHas('notifications_outbox', [
            'idempotency_key' => 'booking_failed:'.$booking->id,
            'kind' => 'booking_failed',
            'user_id' => $user->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_confirming_a_booking_schedules_future_reminders(): void
    {
        $user = User::factory()->create();
        $booking = Booking::query()->create([
            'reference' => 'NT-'.Str::upper(Str::random(6)),
            'user_id' => $user->id,
            'state' => BookingState::Processing,
            'provider' => 'sandbox',
            'fulfilment' => 'native',
        ]);
        $booking->items()->create([
            'title' => 'Harbour walk',
            'quantity' => 1,
            'starts_at' => CarbonImmutable::now()->addDays(3),
        ]);

        app(BookingStateMachine::class)->transition($booking, BookingState::Confirmed, 'provider');

        $this->assertDatabaseHas('notifications_outbox', [
            'idempotency_key' => 'booking_reminder:'.$booking->id.':t1d',
            'kind' => 'booking_reminder',
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('notifications_outbox', [
            'idempotency_key' => 'booking_reminder:'.$booking->id.':t2h',
            'kind' => 'booking_reminder',
            'status' => 'scheduled',
        ]);

        $dayBefore = NotificationOutbox::query()
            ->where('idempotency_key', 'booking_reminder:'.$booking->id.':t1d')
            ->first();
        $this->assertTrue($dayBefore->scheduled_for->greaterThan(CarbonImmutable::now()->addDay()));
    }

    public function test_a_future_reminder_is_not_flushed_early(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        app(NotificationEnqueuer::class)->enqueue(
            new Actor(userId: $user->id),
            'booking_reminder',
            'booking_reminder:later',
            'Starting soon',
            'Your booking starts soon.',
            [],
            CarbonImmutable::now()->addHours(3),
        );

        $this->assertSame(0, app(NotificationDispatcher::class)->flush());
        $this->assertDatabaseHas('notifications_outbox', [
            'idempotency_key' => 'booking_reminder:later',
            'status' => 'scheduled',
        ]);
        Mail::assertNothingSent();
    }

    public function test_the_inbox_lists_payload_and_can_mark_read(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $row = app(NotificationEnqueuer::class)->enqueue(
            new Actor(userId: $user->id),
            'booking_confirmed',
            'booking_confirmed:inbox',
            'Booking confirmed',
            'Your booking NT-9 is confirmed.',
            ['booking_id' => 'b-1', 'reference' => 'NT-9'],
        );

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $row->id)
            ->assertJsonPath('data.0.payload.reference', 'NT-9')
            ->assertJsonPath('data.0.payload.path', '/(tabs)/bookings')
            ->assertJsonPath('data.0.read_at', null);

        $marked = $this->postJson('/api/notifications/'.$row->id.'/read')->assertOk();
        $this->assertNotNull($marked->json('data.read_at'));
        $this->assertNotNull($row->fresh()->read_at);
    }

    public function test_push_payload_includes_a_deep_link_path(): void
    {
        Mail::fake();
        Http::fake([
            'https://exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't1']]], 200),
        ]);

        $user = User::factory()->create();
        DevicePushToken::query()->create([
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[path]',
            'platform' => 'ios',
        ]);

        app(NotificationEnqueuer::class)->enqueue(
            new Actor(userId: $user->id),
            'replan_proposed',
            'replan_proposed:path',
            'A change to your plan',
            'Nothing moves until you accept it.',
            ['trip_id' => 'trip-1'],
        );

        app(NotificationDispatcher::class)->flush();

        Http::assertSent(function ($request) {
            $data = $request['data'] ?? [];

            return $request->url() === 'https://exp.host/--/api/v2/push/send'
                && ($data['path'] ?? null) === '/(tabs)/trip'
                && ($data['kind'] ?? null) === 'replan_proposed'
                && ($data['trip_id'] ?? null) === 'trip-1';
        });
    }
}
