<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Experiences\Models\Experience;
use App\Domains\ExternalSources\Models\ProviderProduct;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance 58: at least one provider can supply bookable offers or a compliant
 * redirect flow.
 * Acceptance 63 (partial): bookings are recorded as behavioural events.
 */
class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $guestToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        /* A real client keeps its guest token across requests; the tests must too,
           otherwise every call looks like a different traveller. */
        $this->guestToken = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
    }

    /** @param array<string,string> $extra */
    private function headers(array $extra = []): array
    {
        return array_merge(['X-Guest-Token' => $this->guestToken], $extra);
    }

    public function test_offers_list_each_supplier_with_its_capabilities_and_fulfilment(): void
    {
        $experience = Experience::where('slug', 'london-tower-of-london')->firstOrFail();

        $offers = $this->getJson("/api/experiences/{$experience->id}/offers")->json('data');

        $this->assertCount(2, $offers);

        $native = collect($offers)->firstWhere('provider', 'sandbox');
        $redirect = collect($offers)->firstWhere('provider', 'deeplink');

        $this->assertSame('native', $native['fulfilment']);
        $this->assertSame('redirect', $redirect['fulfilment']);
        $this->assertContains('live_availability', $native['capabilities']);
        $this->assertNotContains('live_availability', $redirect['capabilities']);
    }

    public function test_a_supplier_without_live_inventory_says_so_rather_than_guessing(): void
    {
        $experience = Experience::where('slug', 'london-tower-of-london')->firstOrFail();

        $availability = collect($this->getJson("/api/experiences/{$experience->id}/availability")->json('data'));

        $redirect = $availability->firstWhere('provider', 'deeplink');

        $this->assertFalse($redirect['is_live']);
        $this->assertSame([], $redirect['slots']);
        $this->assertStringContainsString('does not publish live inventory', $redirect['unavailable_reason']);

        $native = $availability->firstWhere('provider', 'sandbox');
        $this->assertTrue($native['is_live']);
        $this->assertNotEmpty($native['slots']);
    }

    /** At 16:00 where the experience is, this morning's entry times are gone. */
    public function test_slots_that_have_already_started_are_not_offered(): void
    {
        $experience = Experience::where('slug', 'london-tower-of-london')->firstOrFail();
        $now = CarbonImmutable::now('Europe/London')->setTime(16, 0);
        CarbonImmutable::setTestNow($now);

        try {
            $availability = collect($this->getJson("/api/experiences/{$experience->id}/availability")->json('data'));
            $slots = collect($availability->firstWhere('provider', 'sandbox')['slots']);

            $startedToday = $slots->filter(
                fn (array $slot) => $slot['date'] === $now->toDateString() && $slot['start_time'] !== null && $slot['start_time'] < '16:00',
            );

            $this->assertNotEmpty($slots);
            $this->assertCount(0, $startedToday);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_a_booking_cannot_start_in_the_past(): void
    {
        $product = ProviderProduct::where('provider', 'sandbox')->firstOrFail();

        $this->postJson('/api/bookings', [
            'provider' => 'sandbox',
            'provider_product_id' => $product->provider_product_id,
            'quantity' => 1,
            'starts_at' => CarbonImmutable::now()->subHour()->toIso8601String(),
        ], $this->headers(['Idempotency-Key' => 'past-slot']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        /* The 422 must not consume the key — a later slot under it still books. */
        $this->postJson('/api/bookings', [
            'provider' => 'sandbox',
            'provider_product_id' => $product->provider_product_id,
            'quantity' => 1,
            'starts_at' => CarbonImmutable::now()->addDays(2)->setTime(11, 30)->toIso8601String(),
        ], $this->headers(['Idempotency-Key' => 'past-slot']))
            ->assertCreated();
    }

    /** "11:30" on a London slot list means 11:30 in London, not in UTC. */
    public function test_a_slot_time_is_read_in_the_experiences_own_time_zone(): void
    {
        $product = ProviderProduct::where('provider', 'sandbox')
            ->whereHas('experience', fn ($q) => $q->where('slug', 'london-tower-of-london'))
            ->firstOrFail();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-14 12:00', 'UTC'));

        try {
            $startsAt = $this->postJson('/api/bookings', [
                'provider' => 'sandbox',
                'provider_product_id' => $product->provider_product_id,
                'quantity' => 1,
                'starts_at' => '2026-07-15T11:30:00',
            ], $this->headers(['Idempotency-Key' => 'local-slot']))->assertCreated()->json('data.items.0.starts_at');

            $this->assertTrue(CarbonImmutable::parse($startsAt)->equalTo(CarbonImmutable::parse('2026-07-15 11:30', 'Europe/London')));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_a_native_booking_walks_the_state_machine_to_confirmed(): void
    {
        $product = ProviderProduct::where('provider', 'sandbox')->firstOrFail();

        $response = $this->postJson('/api/bookings', [
            'provider' => 'sandbox',
            'provider_product_id' => $product->provider_product_id,
            'quantity' => 2,
            'starts_at' => CarbonImmutable::now()->addDays(3)->setTime(11, 30)->toIso8601String(),
        ], $this->headers(['Idempotency-Key' => 'test-key-1']));

        $response->assertCreated();
        $this->assertSame('confirmed', $response->json('data.state'));
        $this->assertNotNull($response->json('data.provider_booking_id'));

        $history = $this->getJson('/api/bookings/'.$response->json('data.id'), $this->headers())->json('data.history');
        $states = array_column($history, 'to');

        $this->assertSame(
            ['pricing', 'availability_confirmed', 'awaiting_payment', 'processing', 'confirmed'],
            $states,
        );

        $this->assertDatabaseHas('behavioural_events', ['type' => 'book']);
    }

    public function test_a_redirect_booking_hands_the_traveller_to_the_merchant(): void
    {
        $product = ProviderProduct::where('provider', 'deeplink')->firstOrFail();

        $response = $this->postJson('/api/bookings', [
            'provider' => 'deeplink',
            'provider_product_id' => $product->provider_product_id,
            'quantity' => 1,
        ], $this->headers(['Idempotency-Key' => 'test-key-2']));

        $response->assertCreated();
        $this->assertSame('awaiting_payment', $response->json('data.state'));
        $this->assertStringContainsString('partner=', $response->json('data.redirect_url'));
        $this->assertSame('redirect', $response->json('data.fulfilment'));
    }

    public function test_a_retried_booking_request_does_not_create_a_second_booking(): void
    {
        $product = ProviderProduct::where('provider', 'sandbox')->firstOrFail();
        $payload = [
            'provider' => 'sandbox',
            'provider_product_id' => $product->provider_product_id,
            'quantity' => 1,
            'starts_at' => CarbonImmutable::now()->addDays(3)->setTime(11, 30)->toIso8601String(),
        ];

        $first = $this->postJson('/api/bookings', $payload, $this->headers(['Idempotency-Key' => 'retry-key']));
        $second = $this->postJson('/api/bookings', $payload, $this->headers(['Idempotency-Key' => 'retry-key']));

        $this->assertSame($first->json('data.reference'), $second->json('data.reference'));
        $this->assertTrue($second->json('data.replayed'));
        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * A timeout retry after the slot has started must still return the booking
     * that already succeeded, not a fresh "that time has started" 422.
     */
    public function test_a_completed_booking_replays_even_after_its_slot_has_started(): void
    {
        $product = ProviderProduct::where('provider', 'sandbox')->firstOrFail();
        $startsAt = CarbonImmutable::now()->addHour()->toIso8601String();
        $payload = [
            'provider' => 'sandbox',
            'provider_product_id' => $product->provider_product_id,
            'quantity' => 1,
            'starts_at' => $startsAt,
        ];
        $headers = $this->headers(['Idempotency-Key' => 'late-retry']);

        $first = $this->postJson('/api/bookings', $payload, $headers)->assertCreated();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));

        try {
            $second = $this->postJson('/api/bookings', $payload, $headers)->assertCreated();

            $this->assertSame($first->json('data.reference'), $second->json('data.reference'));
            $this->assertTrue($second->json('data.replayed'));
            $this->assertDatabaseCount('bookings', 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_a_booking_without_an_idempotency_key_is_refused(): void
    {
        $product = ProviderProduct::where('provider', 'sandbox')->firstOrFail();

        $this->postJson('/api/bookings', [
            'provider' => 'sandbox',
            'provider_product_id' => $product->provider_product_id,
        ], $this->headers())->assertStatus(422);
    }

    public function test_a_redirect_booking_cannot_be_cancelled_here_and_says_where_to_go(): void
    {
        $product = ProviderProduct::where('provider', 'deeplink')->firstOrFail();

        $booking = $this->postJson('/api/bookings', [
            'provider' => 'deeplink',
            'provider_product_id' => $product->provider_product_id,
        ], $this->headers(['Idempotency-Key' => 'cancel-key']))->json('data');

        $response = $this->postJson("/api/bookings/{$booking['id']}/cancel", [], $this->headers());

        $response->assertOk();
        $this->assertFalse($response->json('data.cancellable_here'));
        $this->assertStringContainsString('cancelled there', $response->json('data.message'));
    }
}
