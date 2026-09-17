<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

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
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\Money;
use Illuminate\Support\Collection;

/**
 * Affiliate deep-link supplier.
 *
 * It can describe a product and hand the traveller to the merchant's own
 * checkout, but it cannot see live inventory — so availability() returns
 * "unknown" rather than a guess (spec s15.1: never fabricate availability).
 */
class DeepLinkAffiliateProvider implements ExperienceProvider
{
    public function key(): string
    {
        return 'deeplink';
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::CONTENT,
            ProviderCapability::SEARCH,
            ProviderCapability::REDIRECT_BOOKING,
            ProviderCapability::IMAGES,
        ];
    }

    public function isConfigured(): bool
    {
        return (bool) config('experience.providers.deeplink.affiliate_id');
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
        return Availability::unknown(
            $this->key(),
            $providerId,
            'This supplier does not expose live inventory. Availability is confirmed on the merchant checkout page.',
        );
    }

    public function createBooking(BookingRequest $request): BookingResult
    {
        $product = ProviderProduct::where('provider', $this->key())
            ->where('provider_product_id', $request->providerProductId)
            ->first();

        if ($product === null) {
            return BookingResult::failed('Unknown product for deep-link provider.');
        }

        $base = $product->product_url ?: config('experience.providers.deeplink.base_url');
        $url = $base . (str_contains($base, '?') ? '&' : '?') . http_build_query([
            'partner' => config('experience.providers.deeplink.affiliate_id'),
            'product' => $request->providerProductId,
            'qty' => $request->quantity,
            'date' => $request->startsAt?->toDateString(),
            'ref' => $request->idempotencyKey,
        ]);

        return BookingResult::redirect($url, $product->cancellation_policy, [
            'note' => 'Checkout and payment are completed on the merchant site.',
        ]);
    }

    public function cancelBooking(string $providerBookingId): CancellationResult
    {
        return new CancellationResult(
            success: false,
            reason: 'Deep-link bookings must be cancelled with the merchant that took the payment.',
        );
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
            priceFreshness: new Freshness($this->key(), $product->price_verified_at, Freshness::HIGHLY_DYNAMIC),
            capabilities: $this->capabilities(),
            cancellationPolicy: $product->cancellation_policy,
        );
    }
}
