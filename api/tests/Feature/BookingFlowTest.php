<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ExternalSources\Models\ProviderProduct;
use App\Domains\Experiences\Models\Experience;
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

        $history = $this->getJson('/api/bookings/' . $response->json('data.id'), $this->headers())->json('data.history');
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
