<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionEvent;
use App\Domains\Billing\Services\AppleNotificationMapper;
use App\Domains\Billing\Services\GoogleNotificationMapper;
use App\Domains\Billing\Services\SubscriptionWriter;
use App\Domains\Billing\SubscriptionState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Subscription JEM §11 P3 — the entitlement moves only when a store says so,
 * and only once per thing it says.
 */
class StoreNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TXN = 'original-txn-1';

    private function subscribed(array $attributes = []): Subscription
    {
        return Subscription::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'store' => 'apple',
            'product_id' => 'wayoa.monthly',
            'original_transaction_id' => self::TXN,
            'state' => SubscriptionState::Active,
            'paid_until' => CarbonImmutable::parse('2026-11-01T00:00:00Z'),
            'auto_renew' => true,
            'environment' => 'sandbox',
        ], $attributes));
    }

    private function apple(string $type, array $overrides = [], string $subtype = ''): array
    {
        return array_replace_recursive([
            'notificationType' => $type,
            'subtype' => $subtype,
            'notificationUUID' => 'uuid-'.$type.$subtype,
            'data' => [
                'environment' => 'Sandbox',
                'signedTransactionInfo' => [
                    'originalTransactionId' => self::TXN,
                    'productId' => 'wayoa.monthly',
                    'expiresDate' => CarbonImmutable::parse('2026-12-01T00:00:00Z')->getTimestampMs(),
                ],
            ],
        ], $overrides);
    }

    private function applyApple(array $payload): ?Subscription
    {
        $notification = app(AppleNotificationMapper::class)->map($payload);
        $this->assertNotNull($notification, 'The mapper should understand this notification.');

        return app(SubscriptionWriter::class)->apply($notification);
    }

    /** @return array<string, array{0: string, 1: SubscriptionState, 2: bool}> */
    public static function appleTypes(): array
    {
        return [
            // type, resulting state, whether the period moves
            'renewal' => ['DID_RENEW', SubscriptionState::Active, true],
            'first purchase' => ['SUBSCRIBED', SubscriptionState::Active, true],
            'expiry' => ['EXPIRED', SubscriptionState::Expired, false],
            'refund' => ['REFUND', SubscriptionState::Revoked, false],
            'revoke' => ['REVOKE', SubscriptionState::Revoked, false],
            'failed renewal' => ['DID_FAIL_TO_RENEW', SubscriptionState::ActiveUntilPeriodEnd, false],
            'grace expired' => ['GRACE_PERIOD_EXPIRED', SubscriptionState::Expired, false],
        ];
    }

    #[DataProvider('appleTypes')]
    public function test_each_apple_type_lands_once_however_often_it_is_delivered(
        string $type,
        SubscriptionState $expected,
        bool $movesPeriod,
    ): void {
        $subscription = $this->subscribed();
        $before = $subscription->paid_until;

        $this->applyApple($this->apple($type));
        $this->applyApple($this->apple($type));

        $subscription->refresh();

        $this->assertSame($expected, $subscription->state);
        $this->assertSame(1, SubscriptionEvent::query()->where('type', 'LIKE', $type.'%')->count());

        if ($movesPeriod) {
            $this->assertTrue($subscription->paid_until->greaterThan($before));
        } else {
            $this->assertTrue($subscription->paid_until->equalTo($before), 'Only a paid charge moves the period.');
        }
    }

    /** Cancelling must never shorten the month already bought. */
    public function test_apple_cancellation_keeps_the_period_and_stops_the_renewal(): void
    {
        $subscription = $this->subscribed();
        $before = $subscription->paid_until;

        $this->applyApple($this->apple('DID_CHANGE_RENEWAL_STATUS', subtype: 'AUTO_RENEW_DISABLED'));

        $subscription->refresh();

        $this->assertSame(SubscriptionState::ActiveUntilPeriodEnd, $subscription->state);
        $this->assertFalse($subscription->auto_renew);
        $this->assertTrue($subscription->paid_until->equalTo($before));
        $this->assertTrue($subscription->canUse(CarbonImmutable::parse('2026-10-20T00:00:00Z')));
    }

    /** A late delivery must not pull a period backwards. */
    public function test_an_older_expiry_never_replaces_a_newer_one(): void
    {
        $subscription = $this->subscribed(['paid_until' => CarbonImmutable::parse('2026-12-01T00:00:00Z')]);

        $this->applyApple($this->apple('DID_RENEW', [
            'notificationUUID' => 'uuid-late',
            'data' => ['signedTransactionInfo' => [
                'expiresDate' => CarbonImmutable::parse('2026-11-01T00:00:00Z')->getTimestampMs(),
            ]],
        ]));

        $this->assertTrue(
            $subscription->refresh()->paid_until->equalTo(CarbonImmutable::parse('2026-12-01T00:00:00Z')),
        );
    }

    /** Google's two pause events mean different things and must not be merged. */
    public function test_google_schedules_a_pause_without_closing_the_month(): void
    {
        $subscription = $this->subscribed(['store' => 'google', 'original_transaction_id' => 'token-1']);
        $before = $subscription->paid_until;

        $mapped = app(GoogleNotificationMapper::class)->map([
            'subscriptionNotification' => ['notificationType' => 11, 'purchaseToken' => 'token-1'],
        ]);
        app(SubscriptionWriter::class)->apply($mapped);

        $subscription->refresh();
        $this->assertSame(SubscriptionState::ActiveUntilPeriodEnd, $subscription->state);
        $this->assertTrue($subscription->paid_until->equalTo($before));
        $this->assertTrue($subscription->canUse(CarbonImmutable::parse('2026-10-20T00:00:00Z')));
    }

    public function test_google_pause_in_effect_closes_the_product(): void
    {
        $subscription = $this->subscribed(['store' => 'google', 'original_transaction_id' => 'token-1']);

        $mapped = app(GoogleNotificationMapper::class)->map([
            'subscriptionNotification' => ['notificationType' => 10, 'purchaseToken' => 'token-1'],
        ]);
        app(SubscriptionWriter::class)->apply($mapped);

        $subscription->refresh();
        $this->assertSame(SubscriptionState::Paused, $subscription->state);
        $this->assertFalse($subscription->canUse(CarbonImmutable::parse('2026-10-20T00:00:00Z')));
    }

    /** Play keeps serving an expiry through grace. It was not paid for. */
    public function test_google_grace_does_not_extend_the_period(): void
    {
        $subscription = $this->subscribed(['store' => 'google', 'original_transaction_id' => 'token-1']);
        $before = $subscription->paid_until;

        $mapped = app(GoogleNotificationMapper::class)->map(
            ['subscriptionNotification' => ['notificationType' => 6, 'purchaseToken' => 'token-1']],
            ['expiryTimeMillis' => (string) CarbonImmutable::parse('2027-01-01T00:00:00Z')->getTimestampMs()],
        );
        app(SubscriptionWriter::class)->apply($mapped);

        $this->assertTrue($subscription->refresh()->paid_until->equalTo($before));
    }

    /** A redelivered Pub/Sub message carries a new id but the same body. */
    public function test_a_repeated_google_delivery_is_recorded_once(): void
    {
        $this->subscribed(['store' => 'google', 'original_transaction_id' => 'token-1']);

        $payload = ['subscriptionNotification' => ['notificationType' => 2, 'purchaseToken' => 'token-1']];

        foreach ([1, 2] as $_) {
            app(SubscriptionWriter::class)->apply(app(GoogleNotificationMapper::class)->map($payload));
        }

        $this->assertSame(1, SubscriptionEvent::query()->where('store', 'google')->count());
    }

    /** An unclaimed transaction is remembered, not applied to somebody else. */
    public function test_a_notification_for_an_unknown_transaction_changes_nothing(): void
    {
        $mine = $this->subscribed();

        $this->applyApple($this->apple('EXPIRED', [
            'notificationUUID' => 'uuid-stranger',
            'data' => ['signedTransactionInfo' => ['originalTransactionId' => 'someone-elses']],
        ]));

        $this->assertSame(SubscriptionState::Active, $mine->refresh()->state);
        $this->assertSame(1, SubscriptionEvent::query()->whereNull('subscription_id')->count());
    }

    /* ── The endpoints refuse what they cannot verify ─────────────────── */

    public function test_the_apple_endpoint_refuses_an_unsigned_body(): void
    {
        config(['experience.billing.apple.root_certificate' => null]);
        $subscription = $this->subscribed();

        $this->postJson('/api/billing/apple/notifications', [
            'signedPayload' => 'not.a.jws',
        ])->assertStatus(401)->assertJsonPath('code', 'unverified');

        $this->assertSame(SubscriptionState::Active, $subscription->refresh()->state);
        $this->assertSame(0, SubscriptionEvent::query()->count());
    }

    public function test_the_google_endpoint_refuses_a_push_without_a_configured_audience(): void
    {
        config(['experience.billing.google.pubsub_audience' => null]);
        $subscription = $this->subscribed(['store' => 'google', 'original_transaction_id' => 'token-1']);

        $this->postJson('/api/billing/google/notifications', [
            'message' => ['data' => base64_encode(json_encode([
                'subscriptionNotification' => ['notificationType' => 13, 'purchaseToken' => 'token-1'],
            ]))],
        ])->assertStatus(401)->assertJsonPath('code', 'unverified');

        $this->assertSame(SubscriptionState::Active, $subscription->refresh()->state);
        $this->assertSame(0, SubscriptionEvent::query()->count());
    }
}
