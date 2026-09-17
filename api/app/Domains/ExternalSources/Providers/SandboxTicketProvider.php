<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\ExperienceProvider;
use App\Domains\ExternalSources\Contracts\ProviderCapability;
use App\Domains\ExternalSources\DTO\Availability;
use App\Domains\ExternalSources\DTO\AvailabilitySlot;
use App\Domains\ExternalSources\DTO\BookingRequest;
use App\Domains\ExternalSources\DTO\BookingResult;
use App\Domains\ExternalSources\DTO\CancellationResult;
use App\Domains\ExternalSources\DTO\DateRange;
use App\Domains\ExternalSources\DTO\ExperienceProduct;
use App\Domains\ExternalSources\DTO\SearchCriteria;
use App\Domains\ExternalSources\Models\ProviderProduct;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\Money;
use Illuminate\Support\Collection;

/**
 * Sandbox ticketing supplier.
 *
 * A real, complete implementation of the provider contract whose inventory is
 * generated deterministically from the product id and date. It exists so the
 * native-booking path can be exercised end to end before a commercial
 * integration is signed. Its data is labelled `sandbox` everywhere it surfaces,
 * so it is never mistaken for a live merchant feed.
 */
class SandboxTicketProvider implements ExperienceProvider
{
    public function key(): string
    {
        return 'sandbox';
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::CONTENT,
            ProviderCapability::SEARCH,
            ProviderCapability::LIVE_AVAILABILITY,
            ProviderCapability::LIVE_PRICING,
            ProviderCapability::NATIVE_BOOKING,
            ProviderCapability::CANCELLATION,
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function search(SearchCriteria $criteria): Collection
    {
        return ProviderProduct::query()
            ->where('provider', $this->key())
            ->where('is_active', true)
            ->when($criteria->query, fn ($q, $term) => $q->whereRaw('lower(title) like ?', ['%' . mb_strtolower($term) . '%']))
            ->limit($criteria->limit)
            ->get()
            ->map(fn (ProviderProduct $p) => $this->toDto($p));
    }

    public function getProduct(string $providerId): ?ExperienceProduct
    {
        $product = ProviderProduct::where('provider', $this->key())
            ->where('provider_product_id', $providerId)
            ->first();

        return $product ? $this->toDto($product) : null;
    }

    public function availability(string $providerId, DateRange $dates): Availability
    {
        $product = ProviderProduct::where('provider', $this->key())
            ->where('provider_product_id', $providerId)
            ->first();

        if ($product === null) {
            return Availability::unknown($this->key(), $providerId, 'Unknown sandbox product.');
        }

        $slots = [];
        foreach ($dates->days() as $day) {
            foreach (['09:30', '11:30', '13:30', '15:30', '17:30'] as $index => $time) {
                $seed = crc32($providerId . $day->toDateString() . $time);
                $remaining = $seed % 14;          // 0 means that slot is sold out
                if ($remaining === 0) {
                    continue;
                }

                $slots[] = new AvailabilitySlot(
                    date: $day,
                    startTime: $time,
                    slotsRemaining: $remaining,
                    price: Money::of($product->price_from_minor, $product->currency),
                );
            }
        }

        return new Availability(
            provider: $this->key(),
            providerProductId: $providerId,
            slots: $slots,
            freshness: Freshness::live($this->key()),
            isLive: true,
        );
    }

    public function createBooking(BookingRequest $request): BookingResult
    {
        $product = ProviderProduct::where('provider', $this->key())
            ->where('provider_product_id', $request->providerProductId)
            ->first();

        if ($product === null) {
            return BookingResult::failed('Unknown sandbox product.');
        }

        $total = Money::of(($product->price_from_minor ?? 0) * $request->quantity, $product->currency);

        return new BookingResult(
            success: true,
            fulfilment: 'native',
            providerBookingId: 'SBX-' . strtoupper(substr(hash('sha256', $request->idempotencyKey), 0, 10)),
            redirectUrl: null,
            total: $total,
            cancellationPolicy: $product->cancellation_policy,
            meta: ['environment' => 'sandbox'],
        );
    }

    public function cancelBooking(string $providerBookingId): CancellationResult
    {
        return new CancellationResult(success: true, refundExpected: true);
    }

    private function toDto(ProviderProduct $product): ExperienceProduct
    {
        return new ExperienceProduct(
            provider: $this->key(),
            providerProductId: $product->provider_product_id,
            title: $product->title,
            description: $product->description,
            productUrl: $product->product_url,
            priceFrom: Money::of($product->price_from_minor, $product->currency),
            priceFreshness: Freshness::live($this->key()),
            capabilities: $this->capabilities(),
            cancellationPolicy: $product->cancellation_policy,
        );
    }
}
