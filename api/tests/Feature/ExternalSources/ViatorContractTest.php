<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalSources;

use App\Domains\ExternalSources\DTO\BookingRequest;
use App\Domains\ExternalSources\DTO\DateRange;
use App\Domains\ExternalSources\DTO\SearchCriteria;
use App\Domains\ExternalSources\Providers\ViatorProvider;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract test for the Viator adapter.
 *
 * The class docblock has claimed since it was written that "response mapping
 * is covered by contract tests against recorded fixtures" naming this file.
 * The file did not exist. Viator is the one commercial supplier with a real
 * key configured, so of all the adapters it was the one whose mapping was both
 * unverified and live.
 *
 * Every field the adapter reads is pinned here. The fixtures are shaped from
 * the adapter's own assumptions rather than from a recorded call, so passing
 * proves the mapping is self-consistent, not that it matches Viator. Record a
 * real response over these and whatever fails is the list of things to fix.
 */
class ViatorContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['experience.providers.viator.api_key' => 'test-key']);
    }

    public function test_it_is_inert_without_a_key(): void
    {
        config(['experience.providers.viator.api_key' => null]);

        $this->assertFalse(app(ViatorProvider::class)->isConfigured());

        $usable = array_map(fn ($p) => $p->key(), app(ProviderRegistry::class)->usable());
        $this->assertNotContains('viator', $usable);
    }

    public function test_it_sends_the_key_and_the_versioned_accept_header(): void
    {
        Http::fake(['api.viator.com/*' => Http::response($this->productFixture())]);

        app(ViatorProvider::class)->getProduct('5010SYDNEY');

        Http::assertSent(function (Request $request) {
            /* The version in Accept is not decoration: Viator serves a
               different response shape without it. */
            return $request->header('exp-api-key')[0] === 'test-key'
                && $request->header('Accept')[0] === 'application/json;version=2.0';
        });
    }

    public function test_it_maps_a_product(): void
    {
        Http::fake(['api.viator.com/*' => Http::response($this->productFixture())]);

        $product = app(ViatorProvider::class)->getProduct('5010SYDNEY');

        $this->assertNotNull($product);
        $this->assertSame('viator', $product->provider);
        $this->assertSame('5010SYDNEY', $product->providerProductId);
        $this->assertSame('Tower of London Entry with Crown Jewels', $product->title);
        $this->assertSame('Priority entry and the Crown Jewels.', $product->description);
        $this->assertSame('https://www.viator.com/tours/London/5010SYDNEY', $product->productUrl);

        /* Major units on the wire, minor units in the domain. */
        $this->assertSame(3450, $product->priceFrom?->minor);
        $this->assertSame('GBP', $product->priceFrom?->currency);

        $this->assertSame('Free cancellation up to 24 hours before.', $product->cancellationPolicy);
        $this->assertSame(['https://cdn.viator.com/tower.jpg'], $product->meta['images']);
    }

    /** Viator returns the id as `code` on some endpoints and `productCode` on others. */
    public function test_it_accepts_either_spelling_of_the_product_id(): void
    {
        $fixture = $this->productFixture();
        unset($fixture['productCode']);
        $fixture['code'] = 'ALT-CODE';

        Http::fake(['api.viator.com/*' => Http::response($fixture)]);

        $this->assertSame('ALT-CODE', app(ViatorProvider::class)->getProduct('ALT-CODE')?->providerProductId);
    }

    public function test_a_missing_product_is_null_rather_than_an_error(): void
    {
        Http::fake(['api.viator.com/*' => Http::response([], 404)]);

        $this->assertNull(app(ViatorProvider::class)->getProduct('GONE'));
    }

    public function test_it_maps_a_search(): void
    {
        Http::fake(['api.viator.com/*' => Http::response(['products' => [$this->productFixture()]])]);

        $results = app(ViatorProvider::class)->search(new SearchCriteria(query: 'tower of london', limit: 5));

        $this->assertCount(1, $results);
        $this->assertSame('5010SYDNEY', $results->first()->providerProductId);

        Http::assertSent(fn (Request $r) =>
            str_contains($r->url(), '/products/search')
            && $r->data()['searchTerm'] === 'tower of london'
            && $r->data()['count'] === 5);
    }

    /**
     * The nesting here is the fragile part — slots live four levels down, and
     * the adapter reads only the first season.
     */
    public function test_it_maps_availability_out_of_the_nested_schedule(): void
    {
        Http::fake(['api.viator.com/*' => Http::response($this->availabilityFixture())]);

        $availability = app(ViatorProvider::class)->availability(
            '5010SYDNEY',
            DateRange::singleDay(CarbonImmutable::parse('2026-10-05')),
        );

        $this->assertTrue($availability->isLive);
        $this->assertCount(2, $availability->slots);

        [$first, $second] = $availability->slots;

        $this->assertSame('2026-10-05', $first->date->toDateString());
        $this->assertSame('09:30', $first->startTime);
        $this->assertSame(12, $first->slotsRemaining);
        $this->assertSame(3450, $first->price?->minor);
        $this->assertSame('GBP', $first->price?->currency);

        $this->assertSame('14:00', $second->startTime);
    }

    /**
     * Current behaviour, pinned so that changing it is a decision.
     *
     * Unlike the GetYourGuide adapter, this one lets a failed availability call
     * throw rather than returning Availability::unknown(). OfferService wraps
     * the call in the registry's attempt() with an unknown() fallback, so that
     * path is safe. BookingService calls it directly and does not.
     */
    public function test_a_failed_availability_call_throws_rather_than_reporting_unknown(): void
    {
        Http::fake(['api.viator.com/*' => Http::response(['message' => 'upstream'], 503)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        app(ViatorProvider::class)->availability(
            '5010SYDNEY',
            DateRange::singleDay(CarbonImmutable::parse('2026-10-05')),
        );
    }

    public function test_it_books_natively_and_passes_the_idempotency_key(): void
    {
        Http::fake(['api.viator.com/*' => Http::response([
            'bookingRef' => 'BR-0001',
            'totalPrice' => 69.0,
            'currency' => 'GBP',
            'cancellationPolicy' => ['description' => 'Free cancellation up to 24 hours before.'],
        ])]);

        $result = app(ViatorProvider::class)->createBooking(new BookingRequest(
            providerProductId: '5010SYDNEY',
            idempotencyKey: 'idem-abc',
            quantity: 2,
            startsAt: CarbonImmutable::parse('2026-10-05 09:30'),
        ));

        $this->assertTrue($result->success);
        $this->assertSame('native', $result->fulfilment);
        $this->assertSame('BR-0001', $result->providerBookingId);
        $this->assertSame(6900, $result->total?->minor);
        $this->assertSame('Free cancellation up to 24 hours before.', $result->cancellationPolicy);

        /* Without this header a retry after a timeout books twice, and the
           traveller pays twice. */
        Http::assertSent(fn (Request $r) => $r->header('idempotency-key')[0] === 'idem-abc');
    }

    public function test_a_refused_booking_reports_the_suppliers_reason(): void
    {
        Http::fake(['api.viator.com/*' => Http::response(['message' => 'Sold out for that date.'], 409)]);

        $result = app(ViatorProvider::class)->createBooking(new BookingRequest(
            providerProductId: '5010SYDNEY',
            idempotencyKey: 'idem-abc',
            quantity: 2,
            startsAt: CarbonImmutable::parse('2026-10-05 09:30'),
        ));

        $this->assertFalse($result->success);
        $this->assertSame('Sold out for that date.', $result->failureReason);
    }

    public function test_it_cancels_and_reports_the_refund(): void
    {
        Http::fake(['api.viator.com/*' => Http::response([
            'refundDetails' => ['refundAmount' => 69.0],
        ])]);

        $result = app(ViatorProvider::class)->cancelBooking('BR-0001');

        $this->assertTrue($result->success);
        $this->assertTrue($result->refundExpected);
        $this->assertSame(6900, $result->refundAmountMinor);
    }

    public function test_a_refused_cancellation_reports_the_suppliers_reason(): void
    {
        Http::fake(['api.viator.com/*' => Http::response(['message' => 'Past the cancellation window.'], 422)]);

        $result = app(ViatorProvider::class)->cancelBooking('BR-0001');

        $this->assertFalse($result->success);
        $this->assertSame('Past the cancellation window.', $result->reason);
    }

    /** @return array<string, mixed> */
    private function productFixture(): array
    {
        return [
            'productCode' => '5010SYDNEY',
            'title' => 'Tower of London Entry with Crown Jewels',
            'description' => 'Priority entry and the Crown Jewels.',
            'productUrl' => 'https://www.viator.com/tours/London/5010SYDNEY',
            'pricing' => ['summary' => ['fromPrice' => 34.5], 'currency' => 'GBP'],
            'cancellationPolicy' => ['description' => 'Free cancellation up to 24 hours before.'],
            'images' => ['https://cdn.viator.com/tower.jpg'],
        ];
    }

    /** @return array<string, mixed> */
    private function availabilityFixture(): array
    {
        return [
            'currency' => 'GBP',
            'bookableItems' => [[
                'productOptionCode' => 'STANDARD',
                'seasons' => [[
                    'pricingRecords' => [
                        ['date' => '2026-10-05', 'startTime' => '09:30', 'available' => 12, 'price' => 34.5],
                        ['date' => '2026-10-05', 'startTime' => '14:00', 'available' => 4, 'price' => 34.5],
                    ],
                ]],
            ]],
        ];
    }
}
