<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ExternalSources\Contracts\ExperienceProvider;
use App\Domains\ExternalSources\Contracts\ProviderCapability;
use App\Domains\ExternalSources\DTO\Availability;
use App\Domains\ExternalSources\DTO\BookingRequest;
use App\Domains\ExternalSources\DTO\BookingResult;
use App\Domains\ExternalSources\DTO\CancellationResult;
use App\Domains\ExternalSources\DTO\DateRange;
use App\Domains\ExternalSources\DTO\ExperienceProduct;
use App\Domains\ExternalSources\DTO\SearchCriteria;
use App\Domains\ExternalSources\Models\ProviderProduct;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use App\Domains\Shared\ValueObjects\Freshness;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

/**
 * Spec s15.1 — an unreachable supplier and a sold-out date must not reach the
 * traveller looking the same.
 *
 * The booking flow used to check only whether the slot list was empty, and an
 * unknown availability has no slots. So a supplier being down told the
 * traveller their date was sold out: a statement about the product, made from
 * a fact about us. The traveller then books elsewhere, which is the expensive
 * kind of wrong.
 */
class BookingAvailabilityHonestyTest extends TestCase
{
    use RefreshDatabase;

    private string $guestToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->guestToken = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
    }

    public function test_an_unreachable_supplier_is_not_reported_as_sold_out(): void
    {
        $this->useProvider(fn (string $id) => Availability::unknown('sandbox', $id, 'Supplier is down.'));

        $data = $this->book('unreachable-key');

        $this->assertSame('failed', $data['state']);
        $this->assertStringContainsString('Could not reach the supplier', $this->lastReason($data['id']));
        $this->assertStringNotContainsString('No availability', $this->lastReason($data['id']));
    }

    /** A supplier that throws is the same situation, and must read the same. */
    public function test_a_supplier_that_throws_is_reported_the_same_way(): void
    {
        $this->useProvider(function (): Availability {
            throw new RuntimeException('connection reset');
        });

        $data = $this->book('throwing-key');

        $this->assertSame('failed', $data['state']);
        $this->assertStringContainsString('Could not reach the supplier', $this->lastReason($data['id']));
    }

    /** And a genuinely sold-out date still says so, in its own words. */
    public function test_a_sold_out_date_still_says_sold_out(): void
    {
        $this->useProvider(fn (string $id) => new Availability(
            provider: 'sandbox',
            providerProductId: $id,
            slots: [],
            freshness: Freshness::live('sandbox'),
            isLive: true,
        ));

        $data = $this->book('soldout-key');

        $this->assertSame('failed', $data['state']);
        $this->assertStringContainsString('No availability', $this->lastReason($data['id']));
    }

    /** Swaps the sandbox supplier for one whose availability we control. */
    private function useProvider(callable $availability): void
    {
        app(ProviderRegistry::class)->register(new class($availability) implements ExperienceProvider
        {
            public function __construct(private $availability) {}

            public function key(): string
            {
                return 'sandbox';
            }

            public function capabilities(): array
            {
                return [
                    ProviderCapability::CONTENT,
                    ProviderCapability::LIVE_AVAILABILITY,
                    ProviderCapability::NATIVE_BOOKING,
                ];
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function search(SearchCriteria $criteria): Collection
            {
                return collect();
            }

            public function getProduct(string $providerId): ?ExperienceProduct
            {
                return null;
            }

            public function availability(string $providerId, DateRange $dates): Availability
            {
                return ($this->availability)($providerId);
            }

            public function createBooking(BookingRequest $request): BookingResult
            {
                /* Never reached in these tests: every one of them should fail
                   at the availability step. Throwing makes that explicit — if
                   the flow ever gets this far, the test says so loudly. */
                throw new RuntimeException('The booking should not have reached the supplier.');
            }

            public function cancelBooking(string $providerBookingId): CancellationResult
            {
                return new CancellationResult(false);
            }
        });
    }

    private function book(string $idempotencyKey): array
    {
        $product = ProviderProduct::where('provider', 'sandbox')->firstOrFail();

        return $this->postJson('/api/bookings', [
            'provider' => 'sandbox',
            'provider_product_id' => $product->provider_product_id,
            'quantity' => 1,
            'starts_at' => CarbonImmutable::now()->addDays(3)->setTime(11, 30)->toIso8601String(),
        ], ['X-Guest-Token' => $this->guestToken, 'Idempotency-Key' => $idempotencyKey])
            ->assertSuccessful()
            ->json('data');
    }

    /** The state machine records why, and the reason is what a traveller reads. */
    private function lastReason(string $bookingId): string
    {
        return (string) \App\Domains\Bookings\Models\BookingTransition::where('booking_id', $bookingId)
            ->latest('id')
            ->value('reason');
    }
}
