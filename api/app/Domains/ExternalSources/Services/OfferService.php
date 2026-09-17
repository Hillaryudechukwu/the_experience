<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Services;

use App\Domains\ExternalSources\Contracts\ProviderCapability;
use App\Domains\ExternalSources\DTO\Availability;
use App\Domains\ExternalSources\DTO\DateRange;
use App\Domains\ExternalSources\Models\ProviderProduct;
use App\Domains\Experiences\Models\Experience;
use Carbon\CarbonImmutable;

/**
 * Bookable offers for a canonical experience.
 *
 * One real-world place can carry many provider products (spec s11). Suppliers
 * are asked in turn and a failure removes that supplier's offers only — the
 * rest of the page keeps working (acceptance 59).
 */
class OfferService
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    /** @return list<array<string,mixed>> */
    public function offersFor(Experience $experience): array
    {
        $products = ProviderProduct::where('experience_id', $experience->id)
            ->where('is_active', true)
            ->get();

        $offers = [];

        foreach ($products as $product) {
            $provider = $this->registry->get($product->provider);

            if ($provider === null || ! $provider->isConfigured()) {
                continue;
            }

            $dto = $this->registry->attempt(
                $product->provider,
                fn () => $provider->getProduct($product->provider_product_id),
            );

            $offers[] = [
                'provider' => $product->provider,
                'provider_product_id' => $product->provider_product_id,
                'title' => $dto?->title ?? $product->title,
                'description' => $dto?->description ?? $product->description,
                'price_from' => $dto?->priceFrom?->toArray() ?? $product->priceFrom()?->toArray(),
                'price_freshness' => ($dto?->priceFreshness ?? $product->priceFreshness())->toArray(),
                'capabilities' => $provider->capabilities(),
                'fulfilment' => in_array(ProviderCapability::NATIVE_BOOKING, $provider->capabilities(), true) ? 'native' : 'redirect',
                'cancellation_policy' => $dto?->cancellationPolicy ?? $product->cancellation_policy,
                'degraded' => $dto === null,
            ];
        }

        return $offers;
    }

    public function availabilityFor(Experience $experience, CarbonImmutable $from, int $days = 3): array
    {
        $products = ProviderProduct::where('experience_id', $experience->id)
            ->where('is_active', true)
            ->get();

        $result = [];

        foreach ($products as $product) {
            $provider = $this->registry->get($product->provider);

            if ($provider === null || ! $provider->isConfigured()) {
                continue;
            }

            if (! in_array(ProviderCapability::LIVE_AVAILABILITY, $provider->capabilities(), true)) {
                $result[] = Availability::unknown(
                    $product->provider,
                    $product->provider_product_id,
                    'This supplier does not publish live inventory.',
                )->toArray();

                continue;
            }

            $availability = $this->registry->attempt(
                $product->provider,
                fn () => $provider->availability(
                    $product->provider_product_id,
                    new DateRange($from->startOfDay(), $from->addDays($days)->endOfDay()),
                ),
                Availability::unknown($product->provider, $product->provider_product_id, 'Supplier is temporarily unreachable.'),
            );

            $result[] = $availability->toArray();
        }

        return $result;
    }

    /** @return array<string, bool> experience_id => has at least one live, bookable slot today */
    public function liveAvailabilityFlags(array $experienceIds, CarbonImmutable $day): array
    {
        if ($experienceIds === []) {
            return [];
        }

        $products = ProviderProduct::whereIn('experience_id', $experienceIds)
            ->where('is_active', true)
            ->get()
            ->groupBy('experience_id');

        $flags = [];

        foreach ($products as $experienceId => $group) {
            foreach ($group as $product) {
                $provider = $this->registry->get($product->provider);

                if ($provider === null
                    || ! $provider->isConfigured()
                    || ! in_array(ProviderCapability::LIVE_AVAILABILITY, $provider->capabilities(), true)) {
                    continue;
                }

                $availability = $this->registry->attempt(
                    $product->provider,
                    fn () => $provider->availability($product->provider_product_id, DateRange::singleDay($day)),
                );

                if ($availability !== null && $availability->slots !== []) {
                    $flags[$experienceId] = true;
                    break;
                }
            }
        }

        return $flags;
    }
}
