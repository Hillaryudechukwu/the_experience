<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalSources;

use App\Domains\ExternalSources\Contracts\ProviderCapability;
use App\Domains\ExternalSources\DTO\BookingRequest;
use App\Domains\ExternalSources\DTO\DateRange;
use App\Domains\ExternalSources\Providers\GetYourGuideProvider;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract test for the GetYourGuide adapter.
 *
 * The adapter was written without access to GetYourGuide's documentation, so
 * the field names it reads are a guess. This file is where that guess is
 * written down: every field the mapping depends on is asserted here against a
 * fixture.
 *
 * When the API key arrives, replace `tourFixture()` and `availabilityFixture()`
 * with a recorded real response and run this. Whatever fails is the list of
 * things the guess got wrong — which is the point of pinning them, and the
 * reason this is worth more than the adapter compiling.
 */
class GetYourGuideProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'experience.providers.getyourguide.api_key' => 'test-key',
            'experience.providers.getyourguide.partner_id' => 'INTERLUDE',
            'experience.currency.base' => 'GBP',
        ]);
    }

    public function test_it_is_inert_without_a_key(): void
    {
        config(['experience.providers.getyourguide.api_key' => null]);

        $this->assertFalse(app(GetYourGuideProvider::class)->isConfigured());

        $usable = array_map(
            fn ($provider) => $provider->key(),
            app(ProviderRegistry::class)->usable(),
        );

        $this->assertNotContains('getyourguide', $usable);
    }

    /**
     * Native booking is a contract, not an endpoint. Listing it would make the
     * registry route real checkouts into a call we have no right to make.
     */
    public function test_it_does_not_claim_booking_it_cannot_perform(): void
    {
        $capabilities = app(GetYourGuideProvider::class)->capabilities();

        $this->assertContains(ProviderCapability::REDIRECT_BOOKING, $capabilities);
        $this->assertNotContains(ProviderCapability::NATIVE_BOOKING, $capabilities);
    }

    public function test_it_maps_a_product(): void
    {
        Http::fake(['api.getyourguide.com/*' => Http::response($this->tourFixture())]);

        $product = app(GetYourGuideProvider::class)->getProduct('12345');

        $this->assertNotNull($product);
        $this->assertSame('getyourguide', $product->provider);
        $this->assertSame('12345', $product->providerProductId);
        $this->assertSame('Tower of London: Entry Ticket with Crown Jewels', $product->title);
        $this->assertSame('Skip the queue and see the Crown Jewels.', $product->description);

        /* Major units in, minor units stored. */
        $this->assertSame(3450, $product->priceFrom?->minor);
        $this->assertSame('GBP', $product->priceFrom?->currency);

        /* Every outbound link is attributed or the booking is not ours. */
        $this->assertStringContainsString('partner_id=INTERLUDE', (string) $product->productUrl);

        $this->assertSame(['https://cdn.getyourguide.com/tower.jpg'], $product->meta['images']);
        $this->assertSame(180, $product->meta['duration_minutes']);
    }

    public function test_it_maps_availability_and_keeps_date_only_entries_timeless(): void
    {
        Http::fake(['api.getyourguide.com/*' => Http::response($this->availabilityFixture())]);

        $availability = app(GetYourGuideProvider::class)->availability(
            '12345',
            DateRange::singleDay(CarbonImmutable::parse('2026-10-05')),
        );

        $this->assertTrue($availability->isLive);
        $this->assertCount(2, $availability->slots);

        [$timed, $allDay] = $availability->slots;

        $this->assertSame('09:30', $timed->startTime);
        $this->assertSame(12, $timed->slotsRemaining);
        $this->assertSame(3450, $timed->price?->minor);

        /* A date with no time is not a midnight departure. Inventing 00:00
           would put a departure time on screen that nobody published. */
        $this->assertNull($allDay->startTime);
    }

    /**
     * "We could not ask" and "there is nothing left" must not look the same to
     * a traveller. One is a fact about the product; the other is a fact about
     * us (spec s15.1).
     */
    public function test_a_failed_availability_call_is_unknown_rather_than_sold_out(): void
    {
        Http::fake(['api.getyourguide.com/*' => Http::response(['error' => 'upstream'], 503)]);

        $availability = app(GetYourGuideProvider::class)->availability(
            '12345',
            DateRange::singleDay(CarbonImmutable::parse('2026-10-05')),
        );

        $this->assertFalse($availability->isLive);
        $this->assertSame([], $availability->slots);
        $this->assertStringContainsString('503', (string) $availability->unavailableReason);
    }

    public function test_booking_redirects_to_their_checkout_without_calling_anything(): void
    {
        Http::fake();

        $result = app(GetYourGuideProvider::class)->createBooking(new BookingRequest(
            providerProductId: '12345',
            idempotencyKey: 'idem-1',
            quantity: 2,
            startsAt: CarbonImmutable::parse('2026-10-05 09:30'),
        ));

        $this->assertTrue($result->success);
        $this->assertSame('redirect', $result->fulfilment);
        $this->assertStringContainsString('getyourguide.com/activity/12345', (string) $result->redirectUrl);
        $this->assertStringContainsString('partner_id=INTERLUDE', (string) $result->redirectUrl);
        $this->assertStringContainsString('date=2026-10-05', (string) $result->redirectUrl);
        $this->assertStringContainsString('p=2', (string) $result->redirectUrl);

        /* There is nothing to ask them. The redirect is the whole operation. */
        Http::assertNothingSent();
    }

    /**
     * A booking we redirected is a booking we do not hold. Reporting a
     * cancellation we did not perform would leave a traveller ignoring a
     * reservation that is still live.
     */
    public function test_it_refuses_to_cancel_a_booking_it_never_held(): void
    {
        $result = app(GetYourGuideProvider::class)->cancelBooking('GYG-123');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('GetYourGuide', (string) $result->reason);
    }

    /** @return array<string, mixed> */
    private function tourFixture(): array
    {
        return [
            'data' => [
                'tours' => [[
                    'tour_id' => 12345,
                    'title' => 'Tower of London: Entry Ticket with Crown Jewels',
                    'abstract' => 'Skip the queue and see the Crown Jewels.',
                    'url' => 'https://www.getyourguide.com/london-l57/tower-of-london-t12345',
                    'duration' => 180,
                    'price' => ['values' => ['amount' => 34.5], 'currency' => 'GBP'],
                    'cancellation_policy' => 'Free cancellation up to 24 hours before.',
                    'pictures' => [['url' => 'https://cdn.getyourguide.com/tower.jpg']],
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function availabilityFixture(): array
    {
        return [
            'data' => [
                'availabilities' => [
                    ['start_time' => '2026-10-05T09:30:00+01:00', 'vacancies' => 12, 'price' => 34.5, 'currency' => 'GBP'],
                    ['date' => '2026-10-05', 'vacancies' => 40, 'price' => 34.5, 'currency' => 'GBP'],
                ],
            ],
        ];
    }
}
