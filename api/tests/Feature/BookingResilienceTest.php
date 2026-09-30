<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Bookings\BookingState;
use App\Domains\Bookings\Models\Booking;
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
 * What happens when a supplier fails mid-booking.
 *
 * This is the money path, and the booking row is written before the supplier
 * is called. An exception escaping that call does not roll anything back:
 * there is no transaction around it, so the booking is simply abandoned in
 * whatever state it had reached, and nothing will ever move it again.
 */
class BookingResilienceTest extends TestCase
{
    use RefreshDatabase;

    private string $guestToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->guestToken = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
    }

    /**
     * A supplier that throws must leave the booking failed, not stranded.
     *
     * AwaitingPayment is not terminal, so a booking abandoned there sits in
     * the traveller's list as pending forever — and the idempotency key is
     * spent, so they cannot even retry.
     */
    public function test_a_supplier_that_throws_while_booking_leaves_a_failed_booking(): void
    {
        $this->useProvider(createBooking: fn () => throw new RuntimeException('connection reset'));

        $response = $this->book('throws-key');

        $response->assertSuccessful();
        $this->assertSame(BookingState::Failed->value, $response->json('data.state'));
        $this->assertDatabaseMissing('bookings', ['state' => BookingState::AwaitingPayment->value]);
    }

    /** And the traveller must be able to try again. */
    public function test_a_failed_attempt_can_be_retried_with_the_same_key(): void
    {
        $this->useProvider(createBooking: fn () => throw new RuntimeException('connection reset'));
        $this->book('retry-key')->assertSuccessful();

        /* Second time the supplier is healthy. The same key is the same
           intent, and an attempt that failed is exactly what a retry is for. */
        $this->useProvider(createBooking: fn () => new BookingResult(
            success: true,
            fulfilment: 'native',
            providerBookingId: 'OK-1',
        ));

        $retry = $this->book('retry-key');

        $retry->assertSuccessful();
        $this->assertSame(BookingState::Confirmed->value, $retry->json('data.state'));
    }

    /**
     * A cancellation that did not happen must not be reported as one.
     *
     * The flow moves a confirmed booking to CancelRequested before it asks the
     * supplier. If that call throws, the booking is left claiming a
     * cancellation is under way when nothing was ever requested — and
     * CancelRequested only leads to Cancelled or Confirmed, neither of which
     * anything will do. The traveller stops watching a live reservation.
     */
    public function test_a_supplier_that_throws_while_cancelling_does_not_claim_a_cancellation(): void
    {
        $booking = $this->confirmedBooking();

        $this->useProvider(cancelBooking: fn () => throw new RuntimeException('connection reset'));

        $response = $this->postJson("/api/bookings/{$booking->id}/cancel", [], [
            'X-Guest-Token' => $this->guestToken,
        ]);

        $response->assertSuccessful();
        $this->assertFalse($response->json('data.cancellable_here'));
        $this->assertStringContainsString('still stands', (string) $response->json('data.message'));

        $booking->refresh();
        $this->assertSame(
            BookingState::Confirmed,
            $booking->state,
            'A booking whose cancellation never reached the supplier is still confirmed.',
        );
    }

    private function book(string $key)
    {
        $product = ProviderProduct::where('provider', 'sandbox')->firstOrFail();

        return $this->postJson('/api/bookings', [
            'provider' => 'sandbox',
            'provider_product_id' => $product->provider_product_id,
            'quantity' => 1,
        ], ['X-Guest-Token' => $this->guestToken, 'Idempotency-Key' => $key]);
    }

    private function confirmedBooking(): Booking
    {
        $this->useProvider(createBooking: fn () => new BookingResult(
            success: true,
            fulfilment: 'native',
            providerBookingId: 'GYG-1',
        ));

        $this->book('confirm-key')->assertSuccessful();

        return Booking::where('state', BookingState::Confirmed->value)->firstOrFail();
    }

    private function useProvider(?callable $createBooking = null, ?callable $cancelBooking = null): void
    {
        app(ProviderRegistry::class)->register(new class($createBooking, $cancelBooking) implements ExperienceProvider
        {
            public function __construct(private $onBook, private $onCancel) {}

            public function key(): string
            {
                return 'sandbox';
            }

            public function capabilities(): array
            {
                return [ProviderCapability::NATIVE_BOOKING, ProviderCapability::CANCELLATION];
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
                return new Availability('sandbox', $providerId, [], Freshness::live('sandbox'), true);
            }

            public function createBooking(BookingRequest $request): BookingResult
            {
                return ($this->onBook)();
            }

            public function cancelBooking(string $providerBookingId): CancellationResult
            {
                return ($this->onCancel)();
            }
        });
    }
}
