<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalSources;

use App\Domains\ExternalSources\Contracts\ProviderCapability;
use App\Domains\ExternalSources\DTO\BookingRequest;
use App\Domains\ExternalSources\DTO\DateRange;
use App\Domains\ExternalSources\Providers\TiqetsProvider;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Contract tests against recorded Tiqets Partner API shapes. */
class TiqetsProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'experience.providers.tiqets.api_key' => 'test-token',
            'experience.currency.base' => 'GBP',
        ]);
    }

    public function test_it_is_inert_without_a_key(): void
    {
        config(['experience.providers.tiqets.api_key' => null]);

        $this->assertFalse(app(TiqetsProvider::class)->isConfigured());

        $usable = array_map(
            fn ($provider) => $provider->key(),
            app(ProviderRegistry::class)->usable(),
        );

        $this->assertNotContains('tiqets', $usable);
    }

    public function test_it_does_not_claim_native_booking(): void
    {
        $capabilities = app(TiqetsProvider::class)->capabilities();

        $this->assertContains(ProviderCapability::REDIRECT_BOOKING, $capabilities);
        $this->assertNotContains(ProviderCapability::NATIVE_BOOKING, $capabilities);
    }

    public function test_it_maps_a_product_and_parses_duration(): void
    {
        Http::fake(['api.tiqets.com/*' => Http::response($this->productFixture())]);

        $product = app(TiqetsProvider::class)->getProduct('1105781');

        $this->assertNotNull($product);
        $this->assertSame('tiqets', $product->provider);
        $this->assertSame('1105781', $product->providerProductId);
        $this->assertSame('Colosseum: Professional Photoshoot', $product->title);
        $this->assertStringContainsString('photoshoot', (string) $product->description);
        $this->assertSame(3808, $product->priceFrom?->minor);
        $this->assertSame('GBP', $product->priceFrom?->currency);
        $this->assertStringContainsString('partner=synteric_limited-190746', (string) $product->productUrl);
        $this->assertSame(15, $product->meta['duration_minutes']);
        $this->assertStringContainsString('/checkout/', (string) $product->meta['checkout_url']);
    }

    public function test_it_maps_timeslot_availability_with_retail_price(): void
    {
        Http::fake(['api.tiqets.com/*' => Http::response($this->availabilityFixture())]);

        $availability = app(TiqetsProvider::class)->availability(
            '1110286',
            DateRange::singleDay(CarbonImmutable::parse('2026-11-02')),
        );

        $this->assertTrue($availability->isLive);
        $this->assertSame('1110286', $availability->providerProductId);
        $this->assertNotEmpty($availability->slots);
        $this->assertSame('10:00', $availability->slots[0]->startTime);
        $this->assertSame(20, $availability->slots[0]->slotsRemaining);
        $this->assertSame(423, $availability->slots[0]->price?->minor);
        $this->assertSame('GBP', $availability->slots[0]->price?->currency);
    }

    public function test_it_resolves_comparison_group_products_to_a_bookable_child(): void
    {
        Http::fake([
            'api.tiqets.com/v2/products/1111515/availability*' => Http::response([
                'success' => false,
                'error' => 'validation_error',
                'message' => "This product is a group and doesn't have detailed availability.",
            ], 422),
            'api.tiqets.com/v2/products/1111515' => Http::response($this->groupProductFixture()),
            'api.tiqets.com/v2/products/1110286/availability*' => Http::response($this->availabilityFixture()),
        ]);

        $availability = app(TiqetsProvider::class)->availability(
            '1111515',
            DateRange::singleDay(CarbonImmutable::parse('2026-11-02')),
        );

        $this->assertTrue($availability->isLive);
        $this->assertSame('1110286', $availability->providerProductId);
    }

    public function test_failed_availability_is_unknown_not_empty(): void
    {
        Http::fake(['api.tiqets.com/*' => Http::response(['error' => 'upstream'], 503)]);

        $availability = app(TiqetsProvider::class)->availability(
            '1110286',
            DateRange::singleDay(CarbonImmutable::parse('2026-11-02')),
        );

        $this->assertFalse($availability->isLive);
        $this->assertSame([], $availability->slots);
    }

    public function test_booking_redirects_to_tiqets_checkout(): void
    {
        Http::fake(['api.tiqets.com/*' => Http::response($this->productFixture())]);

        $result = app(TiqetsProvider::class)->createBooking(new BookingRequest(
            providerProductId: '1105781',
            idempotencyKey: 'idem-tiqets-1',
            quantity: 2,
            startsAt: CarbonImmutable::parse('2026-11-02'),
        ));

        $this->assertTrue($result->success);
        $this->assertSame('redirect', $result->fulfilment);
        $this->assertStringContainsString('/checkout/', (string) $result->redirectUrl);
        $this->assertStringContainsString('date=2026-11-02', (string) $result->redirectUrl);
    }

    public function test_cancel_refuses_redirect_bookings(): void
    {
        $result = app(TiqetsProvider::class)->cancelBooking('anything');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Tiqets', (string) $result->reason);
    }

    /** @return array<string, mixed> */
    private function productFixture(): array
    {
        return [
            'success' => true,
            'product' => [
                'id' => '1105781',
                'title' => 'Colosseum: Professional Photoshoot',
                'tagline' => 'Enjoy a mini photoshoot at the Colosseum',
                'summary' => 'Book a photoshoot outside the Colosseum with a professional photographer.',
                'currency' => 'GBP',
                'price' => 38.08,
                'duration' => '00:15',
                'city_name' => 'Rome',
                'city_id' => '71631',
                'geolocation' => ['lat' => 41.891608, 'lng' => 12.490951],
                'product_url' => 'https://www.tiqets.com/en/rome-attractions-c71631/tickets-for-colosseum-professional-photoshoot-p1105781/?partner=synteric_limited-190746',
                'product_checkout_url' => 'https://www.tiqets.com/en/checkout/tickets-for-colosseum-professional-photoshoot-p1105781/booking_details/?partner=synteric_limited-190746',
                'images' => [],
                'ratings' => ['total' => 6, 'average' => 3.7],
                'cancellation' => ['policy' => 'never'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function groupProductFixture(): array
    {
        return [
            'success' => true,
            'product' => [
                'id' => '1111515',
                'title' => 'Colosseum & Roman Forum + Audio Guide',
                'product_groups' => [[
                    'group_id' => '1111515',
                    'type' => 'comparison',
                    'products' => [
                        ['id' => '1110286', 'title' => 'Immersive Ancient Rome Experience', 'from_price' => 36.0],
                        ['id' => '975871', 'title' => 'Audio Guide', 'from_price' => 25.0],
                    ],
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function availabilityFixture(): array
    {
        return [
            'success' => true,
            'dates' => [[
                'date' => '2026-11-02',
                'availability' => 20,
                'price' => 31.62,
                'currency' => 'GBP',
                'timeslots' => [[
                    'time' => '10:00',
                    'timezone' => 'Europe/Rome',
                    'availability' => 20,
                    'variants' => [[
                        'id' => 159871,
                        'currency' => 'GBP',
                        'max_tickets' => 20,
                        'price_mediation' => [
                            'sale_ticket_value_incl_vat' => 4.23,
                            'booking_fee_incl_vat' => 0.0,
                            'total_retail_price_incl_vat' => 4.23,
                        ],
                    ]],
                ]],
            ]],
            'next_available_date' => '2026-11-02',
            'sales_enabled' => true,
        ];
    }
}
