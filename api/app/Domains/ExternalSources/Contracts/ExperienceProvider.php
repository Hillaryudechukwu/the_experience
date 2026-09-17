<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Contracts;

use App\Domains\ExternalSources\DTO\Availability;
use App\Domains\ExternalSources\DTO\BookingRequest;
use App\Domains\ExternalSources\DTO\BookingResult;
use App\Domains\ExternalSources\DTO\CancellationResult;
use App\Domains\ExternalSources\DTO\DateRange;
use App\Domains\ExternalSources\DTO\ExperienceProduct;
use App\Domains\ExternalSources\DTO\SearchCriteria;
use Illuminate\Support\Collection;

/** Spec s9.1 — every ticket/content supplier sits behind this interface. */
interface ExperienceProvider
{
    public function key(): string;

    /** @return list<string> One of ProviderCapability::ALL */
    public function capabilities(): array;

    public function isConfigured(): bool;

    /** @return Collection<int, ExperienceProduct> */
    public function search(SearchCriteria $criteria): Collection;

    public function getProduct(string $providerId): ?ExperienceProduct;

    public function availability(string $providerId, DateRange $dates): Availability;

    public function createBooking(BookingRequest $request): BookingResult;

    public function cancelBooking(string $providerBookingId): CancellationResult;
}
