<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Services\AppleTransactionVerifier;
use App\Domains\Billing\Services\GooglePlaySubscriptionReader;
use App\Domains\Billing\Services\GooglePubSubVerifier;
use App\Domains\Billing\SubscriptionState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Subscription JEM §11 P3/P4 — device refresh binds a verified receipt to an account.
 */
class BillingRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['experience.billing.enforced' => true]);
    }

    public function test_apple_refresh_claims_a_verified_transaction_for_the_signed_in_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $expires = CarbonImmutable::now()->addMonth();

        $this->mock(AppleTransactionVerifier::class, function ($mock) use ($expires) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('signed.jws.here')
                ->andReturn([
                    'originalTransactionId' => 'apple-txn-1',
                    'transactionId' => 'apple-txn-1-current',
                    'productId' => 'wayoa.monthly',
                    'expiresDate' => $expires->getTimestampMs(),
                    'environment' => 'Sandbox',
                    'bundleId' => 'uk.co.synteric.experience',
                ]);
        });

        $this->postJson('/api/billing/apple/refresh', [
            'signed_transaction' => 'signed.jws.here',
        ])
            ->assertOk()
            ->assertJsonPath('data.can_use', true)
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.store', 'apple');

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'store' => 'apple',
            'original_transaction_id' => 'apple-txn-1',
            'state' => SubscriptionState::Active->value,
        ]);
    }

    public function test_apple_refresh_returns_409_when_another_live_account_owns_the_transaction(): void
    {
        $owner = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $owner->id,
            'store' => 'apple',
            'product_id' => 'wayoa.monthly',
            'original_transaction_id' => 'apple-txn-shared',
            'state' => SubscriptionState::Active,
            'paid_until' => CarbonImmutable::now()->addDays(5),
            'auto_renew' => true,
            'environment' => 'sandbox',
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->mock(AppleTransactionVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')->once()->andReturn([
                'originalTransactionId' => 'apple-txn-shared',
                'transactionId' => 'apple-txn-shared-2',
                'productId' => 'wayoa.monthly',
                'expiresDate' => CarbonImmutable::now()->addMonth()->getTimestampMs(),
                'environment' => 'Sandbox',
            ]);
        });

        $this->postJson('/api/billing/apple/refresh', [
            'signed_transaction' => 'signed.jws.here',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'subscription_in_use');
    }

    public function test_google_refresh_reads_play_and_opens_the_product(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $expires = CarbonImmutable::now()->addDays(28);

        $this->mock(GooglePlaySubscriptionReader::class, function ($mock) use ($expires) {
            $mock->shouldReceive('read')
                ->once()
                ->with('play-token-1', 'wayoa_monthly')
                ->andReturn([
                    'expiryTimeMillis' => $expires->getTimestampMs(),
                    'productId' => 'wayoa_monthly',
                    'autoRenewing' => true,
                    'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
                ]);
        });

        $this->postJson('/api/billing/google/refresh', [
            'purchase_token' => 'play-token-1',
            'product_id' => 'wayoa_monthly',
        ])
            ->assertOk()
            ->assertJsonPath('data.can_use', true)
            ->assertJsonPath('data.store', 'google');
    }

    public function test_google_refresh_fails_closed_when_play_cannot_be_read(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->mock(GooglePlaySubscriptionReader::class, function ($mock) {
            $mock->shouldReceive('read')->once()->andReturn(null);
        });

        $this->postJson('/api/billing/google/refresh', [
            'purchase_token' => 'unknown-token',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'incomplete_receipt');
    }

    public function test_apple_refresh_refuses_a_refunded_transaction(): void
    {
        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'store' => 'apple',
            'product_id' => 'wayoa.monthly',
            'original_transaction_id' => 'apple-txn-refunded',
            'state' => SubscriptionState::Revoked,
            'paid_until' => CarbonImmutable::now()->addDays(20),
            'auto_renew' => false,
            'environment' => 'sandbox',
        ]);
        Sanctum::actingAs($user);

        $this->mock(AppleTransactionVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')->once()->andReturn([
                'originalTransactionId' => 'apple-txn-refunded',
                'transactionId' => 'apple-txn-refunded-1',
                'productId' => 'wayoa.monthly',
                'expiresDate' => CarbonImmutable::now()->addDays(20)->getTimestampMs(),
                'revocationDate' => CarbonImmutable::now()->subDay()->getTimestampMs(),
                'environment' => 'Sandbox',
            ]);
        });

        $this->postJson('/api/billing/apple/refresh', ['signed_transaction' => 'signed.jws.here'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'revoked');

        $this->assertSame(
            SubscriptionState::Revoked,
            Subscription::query()->where('original_transaction_id', 'apple-txn-refunded')->sole()->state,
        );
        $this->getJson('/api/billing/entitlement')->assertJsonPath('data.can_use', false);
    }

    public function test_apple_refresh_refuses_a_lapsed_transaction(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->mock(AppleTransactionVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')->once()->andReturn([
                'originalTransactionId' => 'apple-txn-old',
                'transactionId' => 'apple-txn-old-1',
                'productId' => 'wayoa.monthly',
                'expiresDate' => CarbonImmutable::now()->subDay()->getTimestampMs(),
                'environment' => 'Sandbox',
            ]);
        });

        $this->postJson('/api/billing/apple/refresh', ['signed_transaction' => 'signed.jws.here'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'expired');

        $this->assertDatabaseMissing('subscriptions', ['original_transaction_id' => 'apple-txn-old']);
    }

    public function test_a_resubscribe_reusing_a_revoked_id_gets_its_own_row(): void
    {
        $previous = User::factory()->create();
        $revoked = Subscription::query()->create([
            'user_id' => $previous->id,
            'store' => 'apple',
            'product_id' => 'wayoa.monthly',
            'original_transaction_id' => 'apple-txn-reused',
            'state' => SubscriptionState::Revoked,
            'paid_until' => CarbonImmutable::now()->subDays(3),
            'auto_renew' => false,
            'environment' => 'sandbox',
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->mock(AppleTransactionVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')->once()->andReturn([
                'originalTransactionId' => 'apple-txn-reused',
                'transactionId' => 'apple-txn-reused-2',
                'productId' => 'wayoa.monthly',
                'expiresDate' => CarbonImmutable::now()->addMonth()->getTimestampMs(),
                'environment' => 'Sandbox',
            ]);
        });

        $this->postJson('/api/billing/apple/refresh', ['signed_transaction' => 'signed.jws.here'])
            ->assertOk()
            ->assertJsonPath('data.can_use', true);

        $revoked->refresh();
        $this->assertSame($previous->id, $revoked->user_id);
        $this->assertSame(SubscriptionState::Revoked, $revoked->state);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'original_transaction_id' => 'apple-txn-reused',
            'state' => SubscriptionState::Active->value,
        ]);
    }

    public function test_google_refresh_in_grace_does_not_open_the_product(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->mock(GooglePlaySubscriptionReader::class, function ($mock) {
            $mock->shouldReceive('read')->once()->andReturn([
                'expiryTimeMillis' => CarbonImmutable::now()->addDays(7)->getTimestampMs(),
                'productId' => 'wayoa_monthly',
                'autoRenewing' => true,
                'subscriptionState' => 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD',
            ]);
        });

        $this->postJson('/api/billing/google/refresh', ['purchase_token' => 'play-token-grace'])
            ->assertOk()
            ->assertJsonPath('data.can_use', false);

        $this->assertNull(
            Subscription::query()->where('original_transaction_id', 'play-token-grace')->sole()->paid_until,
        );
    }

    public function test_google_refresh_keeps_a_cancelled_but_paid_month(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->mock(GooglePlaySubscriptionReader::class, function ($mock) {
            $mock->shouldReceive('read')->once()->andReturn([
                'expiryTimeMillis' => CarbonImmutable::now()->addDays(10)->getTimestampMs(),
                'productId' => 'wayoa_monthly',
                'subscriptionState' => 'SUBSCRIPTION_STATE_CANCELED',
            ]);
        });

        $this->postJson('/api/billing/google/refresh', ['purchase_token' => 'play-token-cancelled'])
            ->assertOk()
            ->assertJsonPath('data.can_use', true)
            ->assertJsonPath('data.state', SubscriptionState::ActiveUntilPeriodEnd->value)
            ->assertJsonPath('data.auto_renew', false);
    }

    public function test_google_refresh_refuses_an_unpaid_v1_read(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->mock(GooglePlaySubscriptionReader::class, function ($mock) {
            $mock->shouldReceive('read')->once()->andReturn([
                'expiryTimeMillis' => CarbonImmutable::now()->addDays(7)->getTimestampMs(),
                'productId' => 'wayoa_monthly',
                'paymentState' => 0,
            ]);
        });

        $this->postJson('/api/billing/google/refresh', ['purchase_token' => 'play-token-v1-pending'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'incomplete_receipt');
    }

    public function test_apple_restore_of_a_cancelled_month_does_not_turn_renewal_back_on(): void
    {
        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'store' => 'apple',
            'product_id' => 'wayoa.monthly',
            'original_transaction_id' => 'apple-txn-cancelled',
            'state' => SubscriptionState::ActiveUntilPeriodEnd,
            'paid_until' => CarbonImmutable::now()->addDays(12),
            'auto_renew' => false,
            'environment' => 'sandbox',
        ]);
        Sanctum::actingAs($user);

        $this->mock(AppleTransactionVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')->once()->andReturn([
                'originalTransactionId' => 'apple-txn-cancelled',
                'transactionId' => 'apple-txn-cancelled-1',
                'productId' => 'wayoa.monthly',
                'expiresDate' => CarbonImmutable::now()->addDays(12)->getTimestampMs(),
                'environment' => 'Sandbox',
            ]);
        });

        $this->postJson('/api/billing/apple/refresh', ['signed_transaction' => 'signed.jws.here'])
            ->assertOk()
            ->assertJsonPath('data.can_use', true)
            ->assertJsonPath('data.auto_renew', false);
    }

    public function test_google_refresh_refuses_a_v1_free_trial(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->mock(GooglePlaySubscriptionReader::class, function ($mock) {
            $mock->shouldReceive('read')->once()->andReturn([
                'expiryTimeMillis' => CarbonImmutable::now()->addDays(7)->getTimestampMs(),
                'productId' => 'wayoa_monthly',
                'paymentState' => 2,
            ]);
        });

        $this->postJson('/api/billing/google/refresh', ['purchase_token' => 'play-token-trial'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'incomplete_receipt');

        $this->assertDatabaseMissing('subscriptions', ['original_transaction_id' => 'play-token-trial']);
    }

    public function test_google_refresh_refuses_a_paid_period_that_has_ended(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->mock(GooglePlaySubscriptionReader::class, function ($mock) {
            $mock->shouldReceive('read')->once()->andReturn([
                'expiryTimeMillis' => CarbonImmutable::now()->subHour()->getTimestampMs(),
                'productId' => 'wayoa_monthly',
                'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
            ]);
        });

        $this->postJson('/api/billing/google/refresh', ['purchase_token' => 'play-token-stale'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'expired');

        $this->assertDatabaseMissing('subscriptions', ['original_transaction_id' => 'play-token-stale']);
    }

    public function test_refresh_requires_a_signed_in_user(): void
    {
        $this->postJson('/api/billing/apple/refresh', [
            'signed_transaction' => 'x',
        ])->assertUnauthorized();
    }

    public function test_google_notification_uses_play_detail_for_paid_until(): void
    {
        config([
            'experience.billing.google.pubsub_audience' => 'https://example.test/push',
            'experience.billing.google.pubsub_service_account' => 'notifier@example.iam.gserviceaccount.com',
        ]);

        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'store' => 'google',
            'product_id' => 'wayoa_monthly',
            'original_transaction_id' => 'play-token-renew',
            'state' => SubscriptionState::Active,
            'paid_until' => CarbonImmutable::parse('2026-11-01T00:00:00Z'),
            'auto_renew' => true,
            'environment' => 'sandbox',
        ]);

        $newExpiry = CarbonImmutable::parse('2026-12-01T00:00:00Z');

        $this->mock(GooglePlaySubscriptionReader::class, function ($mock) use ($newExpiry) {
            $mock->shouldReceive('read')
                ->once()
                ->with('play-token-renew', 'wayoa_monthly')
                ->andReturn([
                    'expiryTimeMillis' => $newExpiry->getTimestampMs(),
                    'autoRenewing' => true,
                    'productId' => 'wayoa_monthly',
                ]);
        });

        $this->mock(GooglePubSubVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')->once()->andReturn(true);
        });

        $payload = [
            'subscriptionNotification' => [
                'notificationType' => 2,
                'purchaseToken' => 'play-token-renew',
                'subscriptionId' => 'wayoa_monthly',
            ],
        ];

        $this->postJson('/api/billing/google/notifications', [
            'message' => [
                'data' => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ],
        ])->assertOk()->assertJsonPath('code', 'applied');

        $this->assertTrue(
            Subscription::query()->where('original_transaction_id', 'play-token-renew')->first()
                ->paid_until->equalTo($newExpiry)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
