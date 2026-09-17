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
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;

/**
 * Viator adapter.
 *
 * Disabled until VIATOR_API_KEY is present, at which point the registry starts
 * offering it. Response mapping is covered by contract tests against recorded
 * fixtures (tests/Feature/ExternalSources/ViatorContractTest.php) so a breaking
 * change upstream fails CI rather than production.
 */
class ViatorProvider implements ExperienceProvider
{
    private const BASE = 'https://api.viator.com/partner';

    public function __construct(private readonly HttpFactory $http) {}

    public function key(): string
    {
        return 'viator';
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
            ProviderCapability::REVIEWS,
            ProviderCapability::IMAGES,
        ];
    }

    public function isConfigured(): bool
    {
        return (bool) config('experience.providers.viator.api_key');
    }

    public function search(SearchCriteria $criteria): Collection
    {
        $response = $this->client()->post(self::BASE . '/products/search', array_filter([
            'searchTerm' => $criteria->query,
            'count' => $criteria->limit,
        ]))->throw()->json();

        return collect($response['products'] ?? [])->map(fn (array $p) => $this->mapProduct($p));
    }

    public function getProduct(string $providerId): ?ExperienceProduct
    {
        $response = $this->client()->get(self::BASE . "/products/{$providerId}");

        if ($response->status() === 404) {
            return null;
        }

        return $this->mapProduct($response->throw()->json());
    }

    public function availability(string $providerId, DateRange $dates): Availability
    {
        $response = $this->client()->post(self::BASE . '/availability/schedules', [
            'productCode' => $providerId,
            'startDate' => $dates->from->toDateString(),
            'endDate' => $dates->to->toDateString(),
        ])->throw()->json();

        $slots = [];
        foreach ($response['bookableItems'] ?? [] as $item) {
            foreach ($item['seasons'][0]['pricingRecords'] ?? [] as $record) {
                $slots[] = new AvailabilitySlot(
                    date: CarbonImmutable::parse($record['date'] ?? $dates->from),
                    startTime: $record['startTime'] ?? null,
                    slotsRemaining: $record['available'] ?? null,
                    price: Money::of(
                        isset($record['price']) ? (int) round($record['price'] * 100) : null,
                        $response['currency'] ?? 'GBP',
                    ),
                );
            }
        }

        return new Availability($this->key(), $providerId, $slots, Freshness::live($this->key()), true);
    }

    public function createBooking(BookingRequest $request): BookingResult
    {
        $response = $this->client()
            ->withHeader('idempotency-key', $request->idempotencyKey)
            ->post(self::BASE . '/bookings', [
                'productCode' => $request->providerProductId,
                'travelDate' => $request->startsAt?->toDateString(),
                'paxMix' => $request->travellers,
                'communication' => $request->contact,
            ]);

        if ($response->failed()) {
            return BookingResult::failed($response->json('message') ?? 'Viator rejected the booking.');
        }

        $body = $response->json();

        return new BookingResult(
            success: true,
            fulfilment: 'native',
            providerBookingId: $body['bookingRef'] ?? null,
            total: Money::of(
                isset($body['totalPrice']) ? (int) round($body['totalPrice'] * 100) : null,
                $body['currency'] ?? 'GBP',
            ),
            cancellationPolicy: $body['cancellationPolicy']['description'] ?? null,
        );
    }

    public function cancelBooking(string $providerBookingId): CancellationResult
    {
        $response = $this->client()->post(self::BASE . "/bookings/{$providerBookingId}/cancel", [
            'reasonCode' => 'CUSTOMER_REQUESTED',
        ]);

        if ($response->failed()) {
            return new CancellationResult(false, reason: $response->json('message') ?? 'Cancellation refused.');
        }

        return new CancellationResult(
            success: true,
            refundExpected: (bool) ($response->json('refundDetails.refundAmount') ?? false),
            refundAmountMinor: isset($response->json()['refundDetails']['refundAmount'])
                ? (int) round($response->json('refundDetails.refundAmount') * 100)
                : null,
        );
    }

    private function mapProduct(array $payload): ExperienceProduct
    {
        $priceMajor = $payload['pricing']['summary']['fromPrice'] ?? null;

        return new ExperienceProduct(
            provider: $this->key(),
            providerProductId: (string) ($payload['productCode'] ?? $payload['code'] ?? ''),
            title: (string) ($payload['title'] ?? 'Untitled product'),
            description: $payload['description'] ?? null,
            productUrl: $payload['productUrl'] ?? null,
            priceFrom: Money::of(
                $priceMajor === null ? null : (int) round($priceMajor * 100),
                $payload['pricing']['currency'] ?? 'GBP',
            ),
            priceFreshness: Freshness::live($this->key()),
            capabilities: $this->capabilities(),
            cancellationPolicy: $payload['cancellationPolicy']['description'] ?? null,
            meta: ['images' => $payload['images'] ?? []],
        );
    }

    private function client()
    {
        return $this->http
            ->withHeaders([
                'exp-api-key' => (string) config('experience.providers.viator.api_key'),
                'Accept' => 'application/json;version=2.0',
            ])
            ->timeout(8)
            ->retry(2, 200);
    }
}
