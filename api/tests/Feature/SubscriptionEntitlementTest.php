<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\SubscriptionState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Subscription JEM §11 P1 and P2 — the month is the entitlement, and the
 * product refuses without one.
 */
class SubscriptionEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        /* These are the tests of the closed door, so they close it. */
        config(['experience.billing.enforced' => true]);
    }

    private function subscribe(User $user, array $attributes = []): Subscription
    {
        return Subscription::query()->create(array_merge([
            'user_id' => $user->id,
            'store' => 'apple',
            'product_id' => 'wayoa.monthly',
            'original_transaction_id' => 'txn-'.$user->id,
            'state' => SubscriptionState::Active,
            'paid_until' => CarbonImmutable::now()->addDays(10),
            'auto_renew' => true,
            'environment' => 'sandbox',
        ], $attributes));
    }

    public function test_disabled_enforcement_keeps_the_product_open_without_an_entitlement(): void
    {
        config(['experience.billing.enforced' => false]);
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        $this->getJson('/api/billing/entitlement', ['X-Guest-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.can_use', true)
            ->assertJsonPath('data.state', 'inactive');

        $this->postJson('/api/discovery/now', [], ['X-Guest-Token' => $token])
            ->assertOk();
    }

    /** Real-device testing before the store can sell: listed accounts get in, nobody else does. */
    public function test_a_listed_tester_gets_in_without_a_purchase_and_others_do_not(): void
    {
        config(['experience.billing.tester_emails' => ['tester@example.com']]);

        $tester = User::factory()->create(['email' => 'Tester@Example.com']);
        Sanctum::actingAs($tester);

        $this->getJson('/api/billing/entitlement')->assertOk()->assertJsonPath('data.can_use', true);
        $this->postJson('/api/discovery/now')->assertOk();

        Sanctum::actingAs(User::factory()->create(['email' => 'someone@example.com']));

        $this->getJson('/api/billing/entitlement')->assertOk()->assertJsonPath('data.can_use', false);
        $this->postJson('/api/discovery/now')->assertStatus(402);
    }

    public function test_a_guest_is_never_a_tester(): void
    {
        config(['experience.billing.tester_emails' => ['tester@example.com']]);
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        $this->postJson('/api/discovery/now', [], ['X-Guest-Token' => $token])->assertStatus(402);
    }

    public function test_a_paid_period_that_covers_now_opens_the_product(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        Sanctum::actingAs($user);

        $this->getJson('/api/billing/entitlement')
            ->assertOk()
            ->assertJsonPath('data.can_use', true)
            ->assertJsonPath('data.state', 'active');

        $this->postJson('/api/discovery/now')->assertOk();
    }

    /** The product principle: asking to pause never shortens the month bought. */
    public function test_a_cancelled_subscription_still_works_until_the_period_ends(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, [
            'state' => SubscriptionState::ActiveUntilPeriodEnd,
            'auto_renew' => false,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/billing/entitlement')->assertJsonPath('data.can_use', true);
        $this->postJson('/api/discovery/now')->assertOk();
    }

    /** Renewal still scheduled is not a month in place. */
    public function test_an_elapsed_period_closes_the_product_even_with_auto_renew_on(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, [
            'paid_until' => CarbonImmutable::now()->subMinute(),
            'auto_renew' => true,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/billing/entitlement')
            ->assertOk()
            ->assertJsonPath('data.can_use', false)
            ->assertJsonPath('data.auto_renew', true);

        $this->postJson('/api/discovery/now')
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required');
    }

    public function test_a_paused_subscription_is_closed_even_inside_a_paid_period(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, ['state' => SubscriptionState::Paused]);
        Sanctum::actingAs($user);

        $this->getJson('/api/billing/entitlement')->assertJsonPath('data.can_use', false);
        $this->postJson('/api/discovery/now')->assertStatus(402);
    }

    public function test_a_guest_is_told_they_are_inactive_rather_than_refused_the_question(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        $this->getJson('/api/billing/entitlement', ['X-Guest-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.can_use', false)
            ->assertJsonPath('data.state', 'inactive');
    }

    /** No provider is called and no recommendation set is written. */
    public function test_an_unentitled_guest_is_refused_the_product(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        $this->postJson('/api/discovery/now', [], ['X-Guest-Token' => $token])
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required');

        $this->assertDatabaseCount('recommendation_sets', 0);
    }

    /** Setup stays free, and so does the way out. */
    public function test_onboarding_and_account_deletion_stay_open_without_a_subscription(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        $this->patchJson('/api/traveller/profile', [
            'travel_pace' => 'slow',
        ], ['X-Guest-Token' => $token])->assertOk();

        $destinationId = $this->getJson('/api/destinations?q=london')->json('data.0.id');
        $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'holiday',
        ], ['X-Guest-Token' => $token])->assertCreated();

        $this->deleteJson('/api/auth/account', [], ['X-Guest-Token' => $token])->assertOk();
    }
}
