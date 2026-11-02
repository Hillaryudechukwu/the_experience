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
 * Gate 3 — export and account deletion stay reachable when product routes are gated.
 */
class BillingEnforcedPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['experience.billing.enforced' => true]);
    }

    public function test_privacy_export_works_without_a_paid_month(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/discovery/now')->assertStatus(402);

        $this->getJson('/api/privacy/export')
            ->assertOk()
            ->assertJsonStructure(['data' => ['profile', 'journeys']]);
    }

    public function test_account_deletion_works_without_a_paid_month(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/auth/account', ['password' => 'password'])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_legal_pages_remain_public_with_enforcement_on(): void
    {
        $this->get('/legal/privacy')->assertOk();
        $this->get('/legal/terms')->assertOk();
    }

    public function test_a_paid_user_can_still_export(): void
    {
        $user = User::factory()->create();
        Subscription::query()->create([
            'user_id' => $user->id,
            'store' => 'apple',
            'product_id' => 'wayoa.monthly',
            'original_transaction_id' => 'txn-export',
            'state' => SubscriptionState::Active,
            'paid_until' => CarbonImmutable::now()->addDays(5),
            'auto_renew' => true,
            'environment' => 'sandbox',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/privacy/export')->assertOk();
    }
}
