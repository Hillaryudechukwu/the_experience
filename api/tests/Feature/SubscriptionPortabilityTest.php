<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Services\EntitlementService;
use App\Domains\Billing\SubscriptionState;
use App\Domains\Shared\ValueObjects\Actor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two places where billing depended on which database it was talking to.
 *
 * Both engines run this suite, and both have to agree: production is MariaDB
 * and development and CI are PostgreSQL, so a rule that holds on one of them
 * is a rule that is tested somewhere other than where it runs.
 */
class SubscriptionPortabilityTest extends TestCase
{
    use RefreshDatabase;

    private function row(User $user, array $attributes): Subscription
    {
        return Subscription::query()->create(array_merge([
            'user_id' => $user->id,
            'store' => 'apple',
            'product_id' => 'wayoa.monthly',
            'original_transaction_id' => 'txn-'.uniqid(),
            'state' => SubscriptionState::Active,
            'auto_renew' => true,
            'environment' => 'sandbox',
        ], $attributes));
    }

    /**
     * NULLs sort first on PostgreSQL and last on MySQL, so ordering by a
     * nullable period alone chose a different row per engine.
     */
    public function test_a_pending_row_never_outranks_a_paid_one(): void
    {
        $user = User::factory()->create();

        $this->row($user, [
            'state' => SubscriptionState::Pending,
            'paid_until' => null,
            'original_transaction_id' => 'txn-pending',
        ]);
        $this->row($user, [
            'state' => SubscriptionState::Active,
            'paid_until' => CarbonImmutable::now()->addDays(20),
            'original_transaction_id' => 'txn-paid',
        ]);

        $current = app(EntitlementService::class)->current(new Actor(userId: $user->id));

        $this->assertSame('txn-paid', $current?->original_transaction_id);
        $this->assertTrue(app(EntitlementService::class)->canUse(new Actor(userId: $user->id)));
    }

    /**
     * With nothing paid for, the pending row is still what the paywall reads.
     *
     * Enforcement is pinned on, because this is a test of the entitlement
     * rule rather than of the switch. With it off, present() deliberately
     * reports can_use true whatever the subscription says, so that the client
     * does not raise a paywall the server has already decided not to apply —
     * and this assertion would be measuring that instead.
     */
    public function test_a_lone_pending_row_is_still_found(): void
    {
        config(['experience.billing.enforced' => true]);

        $user = User::factory()->create();
        $this->row($user, ['state' => SubscriptionState::Pending, 'paid_until' => null]);

        $presented = app(EntitlementService::class)->present(new Actor(userId: $user->id));

        $this->assertFalse($presented['can_use']);
        $this->assertSame('pending', $presented['state']);
    }

    /** A refund must not bar the traveller from ever buying again. */
    public function test_a_revoked_row_does_not_reserve_its_transaction_id(): void
    {
        $user = User::factory()->create();

        $this->row($user, [
            'state' => SubscriptionState::Revoked,
            'paid_until' => CarbonImmutable::now()->subMonth(),
            'original_transaction_id' => 'txn-reused',
        ]);

        $again = $this->row($user, [
            'state' => SubscriptionState::Active,
            'paid_until' => CarbonImmutable::now()->addMonth(),
            'original_transaction_id' => 'txn-reused',
        ]);

        $this->assertTrue($again->exists);
        $this->assertTrue(app(EntitlementService::class)->canUse(new Actor(userId: $user->id)));
    }

    /** One live subscription per transaction, still. */
    public function test_two_live_rows_for_one_transaction_are_refused(): void
    {
        $user = User::factory()->create();

        $this->row($user, [
            'paid_until' => CarbonImmutable::now()->addMonth(),
            'original_transaction_id' => 'txn-live',
        ]);

        $this->expectException(QueryException::class);

        $this->row($user, [
            'paid_until' => CarbonImmutable::now()->addMonth(),
            'original_transaction_id' => 'txn-live',
        ]);
    }
}
