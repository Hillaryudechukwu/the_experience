<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Models\DevicePushToken;
use App\Domains\Notifications\Models\NotificationOutbox;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One dead device must not re-notify the live ones.
 *
 * Expo answers a multi-token push with one ticket per token, and the common
 * error is DeviceNotRegistered — the traveller uninstalled the app from one
 * of their devices. Treating that as a failure of the whole send re-queued
 * the row, and since 'push' was only recorded in sent_channels after the call
 * returned, each retry pushed to the entire list again.
 */
class PushFanoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_a_dead_token_does_not_re_push_to_the_live_ones(): void
    {
        $user = User::factory()->create();
        $this->token($user, 'ExponentPushToken[phone]');
        $this->token($user, 'ExponentPushToken[tablet]');

        Http::fake(['exp.host/*' => Http::response(['data' => [
            ['status' => 'ok', 'id' => 'ticket-1'],
            ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
        ]])]);

        $row = $this->outbox($user);

        app(NotificationDispatcher::class)->flush();

        $row->refresh();

        /* Delivered, not retried: the phone got it. */
        $this->assertSame('sent', $row->status);
        $this->assertSame(0, $row->attempts);
        $this->assertContains('push', $row->payload['sent_channels'] ?? []);

        /* Exactly one call to Expo, so the phone was notified once. */
        Http::assertSentCount(1);
    }

    /** A token Expo says is gone should stop being carried forward. */
    public function test_a_device_that_unregistered_is_forgotten(): void
    {
        $user = User::factory()->create();
        $this->token($user, 'ExponentPushToken[phone]');
        $this->token($user, 'ExponentPushToken[tablet]');

        Http::fake(['exp.host/*' => Http::response(['data' => [
            ['status' => 'ok', 'id' => 'ticket-1'],
            ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
        ]])]);

        $this->outbox($user);
        app(NotificationDispatcher::class)->flush();

        $this->assertDatabaseHas('device_push_tokens', ['token' => 'ExponentPushToken[phone]']);
        $this->assertDatabaseMissing('device_push_tokens', ['token' => 'ExponentPushToken[tablet]']);
    }

    /**
     * When nothing was delivered there is something to retry, and the row must
     * still go back on the queue.
     */
    public function test_every_token_failing_is_still_retried(): void
    {
        $user = User::factory()->create();
        $this->token($user, 'ExponentPushToken[phone]');

        Http::fake(['exp.host/*' => Http::response(['data' => [
            ['status' => 'error', 'message' => 'upstream wobble', 'details' => ['error' => 'MessageRateExceeded']],
        ]])]);

        $row = $this->outbox($user);

        app(NotificationDispatcher::class)->flush();

        $row->refresh();

        $this->assertSame('scheduled', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertNotContains('push', $row->payload['sent_channels'] ?? []);
    }

    private function token(User $user, string $token): void
    {
        DevicePushToken::query()->create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'token' => $token,
            'platform' => 'ios',
        ]);
    }

    private function outbox(User $user): NotificationOutbox
    {
        return NotificationOutbox::query()->create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'kind' => 'booking_confirmed',
            'title' => 'Your booking is confirmed',
            'body' => 'Tower of London, tomorrow at 09:30.',
            'status' => 'scheduled',
            'scheduled_for' => CarbonImmutable::now()->subMinute(),
        ]);
    }
}
