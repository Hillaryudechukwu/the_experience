<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Services\AppleNotificationMapper;
use App\Domains\Billing\Services\GoogleNotificationMapper;
use App\Domains\Billing\Services\SubscriptionWriter;
use App\Domains\Billing\SubscriptionState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Subscription JEM §11 P5 — the exit criterion, as far as it can be asserted
 * without a store: scheduling a pause or a cancellation leaves the month
 * running to its end, and closes only once that end has passed. Neither path
 * shortens what was paid for.
 */
class SubscriptionPauseTest extends TestCase
{
    use RefreshDatabase;

    private const ENDS = '2026-11-01T00:00:00Z';

    private function subscribed(string $store, string $transaction): Subscription
    {
        return Subscription::query()->create([
            'user_id' => User::factory()->create()->id,
            'store' => $store,
            'product_id' => $store === 'apple' ? 'wayoa.monthly' : 'wayoa_monthly',
            'original_transaction_id' => $transaction,
            'state' => SubscriptionState::Active,
            'paid_until' => CarbonImmutable::parse(self::ENDS),
            'auto_renew' => true,
            'environment' => 'sandbox',
        ]);
    }

    /** Play's pause: asked for now, in effect at the period boundary. */
    public function test_a_scheduled_play_pause_runs_the_month_out_and_then_closes(): void
    {
        $subscription = $this->subscribed('google', 'token-pause');

        app(SubscriptionWriter::class)->apply(
            app(GoogleNotificationMapper::class)->map([
                'subscriptionNotification' => ['notificationType' => 11, 'purchaseToken' => 'token-pause'],
            ]),
        );

        $subscription->refresh();

        $this->assertTrue(
            $subscription->paid_until->equalTo(CarbonImmutable::parse(self::ENDS)),
            'Asking to pause must never shorten the month already paid for.',
        );

        $this->assertTrue($subscription->canUse(CarbonImmutable::parse('2026-10-31T23:59:00Z')));
        $this->assertFalse($subscription->canUse(CarbonImmutable::parse('2026-11-01T00:01:00Z')));
    }

    /** Apple has no pause; stopping renewal has to behave the same way. */
    public function test_stopping_the_apple_renewal_runs_the_month_out_and_then_closes(): void
    {
        $subscription = $this->subscribed('apple', 'txn-cancel');

        app(SubscriptionWriter::class)->apply(
            app(AppleNotificationMapper::class)->map([
                'notificationType' => 'DID_CHANGE_RENEWAL_STATUS',
                'subtype' => 'AUTO_RENEW_DISABLED',
                'notificationUUID' => 'uuid-cancel',
                'data' => [
                    'environment' => 'Sandbox',
                    'signedTransactionInfo' => [
                        'originalTransactionId' => 'txn-cancel',
                        'productId' => 'wayoa.monthly',
                        'expiresDate' => CarbonImmutable::parse(self::ENDS)->getTimestampMs(),
                    ],
                ],
            ]),
        );

        $subscription->refresh();

        $this->assertSame(SubscriptionState::ActiveUntilPeriodEnd, $subscription->state);
        $this->assertFalse($subscription->auto_renew);
        $this->assertTrue($subscription->paid_until->equalTo(CarbonImmutable::parse(self::ENDS)));

        $this->assertTrue($subscription->canUse(CarbonImmutable::parse('2026-10-31T23:59:00Z')));
        $this->assertFalse($subscription->canUse(CarbonImmutable::parse('2026-11-01T00:01:00Z')));
    }

    /** Play resumes by itself, but only a charge that lands reopens the app. */
    public function test_a_resumed_play_subscription_reopens_only_when_the_charge_succeeds(): void
    {
        $subscription = $this->subscribed('google', 'token-resume');
        $later = CarbonImmutable::parse('2026-12-01T00:00:00Z');

        /* The pause takes effect: closed. */
        app(SubscriptionWriter::class)->apply(
            app(GoogleNotificationMapper::class)->map([
                'subscriptionNotification' => ['notificationType' => 10, 'purchaseToken' => 'token-resume'],
            ]),
        );
        $this->assertFalse($subscription->refresh()->canUse(CarbonImmutable::parse('2026-10-20T00:00:00Z')));

        /* It resumes and the charge fails: still closed, and the period has
           not moved. */
        app(SubscriptionWriter::class)->apply(
            app(GoogleNotificationMapper::class)->map([
                'subscriptionNotification' => ['notificationType' => 5, 'purchaseToken' => 'token-resume'],
            ]),
        );
        $subscription->refresh();
        $this->assertSame(SubscriptionState::Expired, $subscription->state);
        $this->assertTrue($subscription->paid_until->equalTo(CarbonImmutable::parse(self::ENDS)));

        /* The charge lands: a new period, and the app opens again. */
        app(SubscriptionWriter::class)->apply(
            app(GoogleNotificationMapper::class)->map(
                ['subscriptionNotification' => ['notificationType' => 1, 'purchaseToken' => 'token-resume']],
                ['expiryTimeMillis' => (string) $later->getTimestampMs()],
            ),
        );
        $subscription->refresh();
        $this->assertSame(SubscriptionState::Active, $subscription->state);
        $this->assertTrue($subscription->canUse(CarbonImmutable::parse('2026-11-15T00:00:00Z')));
    }
}
