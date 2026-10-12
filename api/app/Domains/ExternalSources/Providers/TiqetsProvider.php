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
use App\Domains\ExternalSources\Services\OutboundHttp;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Tiqets Partner API adapter (affiliate / distributor catalogue).
 *
 * Self-serve portal tokens unlock products + availability. Native order create
 * / confirm is a separate Booking API tier — do not claim NATIVE_BOOKING until
 * Tiqets grants that access and the acceptance checklist is done.
 *
 * Checkout is redirect-only: product_checkout_url already carries the partner
 * sitebrand from the token, and payment stays on Tiqets.
 */
class TiqetsProvider implements ExperienceProvider
{
    private const BASE = 'https://api.tiqets.com/v2';

    public function __construct(private readonly OutboundHttp $http) {}

    public function key(): string
    {
        return 'tiqets';
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::CONTENT,
            ProviderCapability::SEARCH,
            ProviderCapability::LIVE_AVAILABILITY,
            ProviderCapability::LIVE_PRICING,
            ProviderCapability::REDIRECT_BOOKING,
            ProviderCapability::IMAGES,
        ];
    }

    public function isConfigured(): bool
    {
        return filled(config('experience.providers.tiqets.api_key'));
    }

    public function search(SearchCriteria $criteria): Collection
    {
        $params = array_filter([
            'page_size' => min(50, max(1, $criteria->limit)),
            'currency' => config('experience.currency.base', 'GBP'),
            'query' => $criteria->query,
            'city_name' => $this->cityName($criteria),
            'city_id' => $this->cityIdNear($criteria),
        ], fn ($value) => $value !== null && $value !== '');

        $response = $this->client()->get(self::BASE . '/products', $params);

        if ($response->failed()) {
            throw new RuntimeException("Tiqets search returned {$response->status()}.");
        }

        return collect($response->json('products') ?? [])
            ->map(fn (array $product) => $this->mapProduct($product))
            ->filter(fn (ExperienceProduct $product) => $product->providerProductId !== '')
            ->values();
    }

    public function getProduct(string $providerId): ?ExperienceProduct
    {
        $response = $this->client()->get(self::BASE . '/products/' . urlencode($providerId), [
            'currency' => config('experience.currency.base', 'GBP'),
        ]);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException("Tiqets product lookup returned {$response->status()}.");
        }

        $product = $response->json('product');

        return is_array($product) ? $this->mapProduct($product) : null;
    }

    public function availability(string $providerId, DateRange $dates): Availability
    {
        $bookableId = $providerId;
        $response = $this->availabilityRequest($bookableId, $dates);

        if ($response->status() === 422 && str_contains(mb_strtolower((string) $response->json('message', '')), 'group')) {
            $bookableId = $this->firstGroupedProductId($providerId) ?? $providerId;
            if ($bookableId !== $providerId) {
                $response = $this->availabilityRequest($bookableId, $dates);
            }
        }

        if ($response->failed()) {
            return Availability::unknown(
                $this->key(),
                $providerId,
                "Tiqets did not answer ({$response->status()}).",
            );
        }

        $slots = [];

        foreach ($response->json('dates') ?? [] as $day) {
            if (! is_array($day) || ! is_string($day['date'] ?? null)) {
                continue;
            }

            $dayDate = CarbonImmutable::parse($day['date']);
            $timeslots = $day['timeslots'] ?? [];

            if ($timeslots === [] || $timeslots === null) {
                $slots[] = new AvailabilitySlot(
                    date: $dayDate,
                    startTime: null,
                    slotsRemaining: isset($day['availability']) ? (int) $day['availability'] : null,
                    price: $this->money($day['price'] ?? null, $day['currency'] ?? null),
                );

                continue;
            }

            foreach ($timeslots as $timeslot) {
                if (! is_array($timeslot)) {
                    continue;
                }

                $slots[] = new AvailabilitySlot(
                    date: $dayDate,
                    startTime: is_string($timeslot['time'] ?? null) ? $timeslot['time'] : null,
                    slotsRemaining: isset($timeslot['availability']) ? (int) $timeslot['availability'] : null,
                    price: $this->slotPrice($timeslot, $day),
                );
            }
        }

        return new Availability(
            provider: $this->key(),
            providerProductId: $bookableId,
            slots: $slots,
            freshness: Freshness::live($this->key()),
            isLive: true,
        );
    }

    public function createBooking(BookingRequest $request): BookingResult
    {
        $product = $this->getProduct($request->providerProductId);
        $url = $product?->meta['checkout_url']
            ?? $product?->productUrl
            ?? null;

        if (! is_string($url) || $url === '') {
            return BookingResult::failed('Tiqets did not return a checkout URL for this product.');
        }

        $query = array_filter([
            'date' => $request->startsAt?->toDateString(),
        ], fn ($value) => $value !== null && $value !== '');

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return BookingResult::redirect(
            $url,
            cancellationPolicy: 'Cancellation terms are set by Tiqets and shown at checkout.',
            meta: ['provider_product_id' => $request->providerProductId],
        );
    }

    public function cancelBooking(string $providerBookingId): CancellationResult
    {
        return new CancellationResult(
            success: false,
            reason: 'This was booked on Tiqets, so it has to be cancelled there — '
                . 'through the confirmation email or a Tiqets account.',
        );
    }

    /** @param array<string, mixed> $product */
    private function mapProduct(array $product): ExperienceProduct
    {
        $images = [];
        foreach ($product['images'] ?? [] as $image) {
            if (is_string($image) && $image !== '') {
                $images[] = $image;
            } elseif (is_array($image) && is_string($image['url'] ?? null)) {
                $images[] = $image['url'];
            } elseif (is_array($image) && is_string($image['large'] ?? null)) {
                $images[] = $image['large'];
            } elseif (is_array($image) && is_string($image['medium'] ?? null)) {
                $images[] = $image['medium'];
            }
        }

        return new ExperienceProduct(
            provider: $this->key(),
            providerProductId: (string) ($product['id'] ?? ''),
            title: (string) ($product['title'] ?? 'Untitled experience'),
            description: $product['summary'] ?? $product['tagline'] ?? null,
            productUrl: is_string($product['product_url'] ?? null) ? $product['product_url'] : null,
            priceFrom: $this->money($product['price'] ?? $product['display_price'] ?? null, $product['currency'] ?? null),
            priceFreshness: Freshness::live($this->key()),
            capabilities: $this->capabilities(),
            cancellationPolicy: is_array($product['cancellation'] ?? null)
                ? ($product['cancellation']['policy'] ?? null)
                : null,
            meta: [
                'images' => array_values(array_unique($images)),
                'duration_minutes' => $this->durationMinutes($product['duration'] ?? null),
                'checkout_url' => $product['product_checkout_url'] ?? null,
                'city_name' => $product['city_name'] ?? null,
                'city_id' => $product['city_id'] ?? null,
                'lat' => $product['geolocation']['lat'] ?? null,
                'lng' => $product['geolocation']['lng'] ?? null,
                'rating' => $product['ratings']['average'] ?? null,
                'rating_count' => $product['ratings']['total'] ?? null,
            ],
        );
    }

    private function availabilityRequest(string $providerId, DateRange $dates)
    {
        return $this->client()->get(self::BASE . '/products/' . urlencode($providerId) . '/availability', [
            'start_date' => $dates->from->toDateString(),
            'end_date' => $dates->to->toDateString(),
            'currency' => config('experience.currency.base', 'GBP'),
        ]);
    }

    /**
     * Comparison "group" products cannot be queried for timeslots — use the
     * first included bookable product id when Tiqets says so.
     */
    private function firstGroupedProductId(string $providerId): ?string
    {
        $product = $this->client()
            ->get(self::BASE . '/products/' . urlencode($providerId))
            ->json('product');

        foreach ($product['product_groups'] ?? [] as $group) {
            foreach ($group['products'] ?? [] as $child) {
                $childId = is_array($child) ? ($child['id'] ?? null) : $child;
                if (is_string($childId) || is_int($childId)) {
                    return (string) $childId;
                }
            }
        }

        return null;
    }

    private function cityName(SearchCriteria $criteria): ?string
    {
        if ($criteria->destinationSlug === null || $criteria->destinationSlug === '') {
            return null;
        }

        /* Slugs are "lisbon" / "reykjavik-is" — good enough as a city_name hint. */
        $name = str_replace('-', ' ', $criteria->destinationSlug);

        return trim(preg_replace('/\b[a-z]{2}\b$/i', '', $name) ?? $name) ?: null;
    }

    private function cityIdNear(SearchCriteria $criteria): ?string
    {
        if ($criteria->near === null || $criteria->query || $criteria->destinationSlug) {
            return null;
        }

        $response = $this->client()->get(self::BASE . '/cities', [
            'lat' => $criteria->near->lat,
            'lng' => $criteria->near->lng,
            'page_size' => 1,
        ]);

        if ($response->failed()) {
            return null;
        }

        $id = $response->json('cities.0.id');

        return $id !== null ? (string) $id : null;
    }

    /** @param array<string, mixed> $timeslot @param array<string, mixed> $day */
    private function slotPrice(array $timeslot, array $day): ?Money
    {
        foreach ($timeslot['variants'] ?? [] as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $amount = $variant['price_mediation']['total_retail_price_incl_vat']
                ?? $variant['price_mediation']['sale_ticket_value_incl_vat']
                ?? null;

            if (is_numeric($amount)) {
                return $this->money($amount, $variant['currency'] ?? $day['currency'] ?? null);
            }
        }

        return $this->money($day['price'] ?? null, $day['currency'] ?? null);
    }

    private function durationMinutes(mixed $duration): ?int
    {
        if (is_int($duration) || (is_string($duration) && ctype_digit($duration))) {
            return (int) $duration;
        }

        if (! is_string($duration) || ! preg_match('/^(\d{1,2}):(\d{2})$/', $duration, $matches)) {
            return null;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    private function money(mixed $amount, ?string $currency): ?Money
    {
        if (! is_numeric($amount)) {
            return null;
        }

        return Money::of(
            (int) round((float) $amount * 100),
            $currency ?? config('experience.currency.base', 'GBP'),
        );
    }

    private function client()
    {
        return $this->http
            ->for($this->key(), timeout: 12, accept: 'application/json')
            ->withHeaders([
                'Authorization' => 'Token ' . (string) config('experience.providers.tiqets.api_key'),
            ]);
    }
}
