<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\Money;

final readonly class ExperienceProduct
{
    public function __construct(
        public string $provider,
        public string $providerProductId,
        public string $title,
        public ?string $description,
        public ?string $productUrl,
        public ?Money $priceFrom,
        public Freshness $priceFreshness,
        public array $capabilities,
        public ?string $cancellationPolicy = null,
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_product_id' => $this->providerProductId,
            'title' => $this->title,
            'description' => $this->description,
            'product_url' => $this->productUrl,
            'price_from' => $this->priceFrom?->toArray(),
            'price_freshness' => $this->priceFreshness->toArray(),
            'capabilities' => $this->capabilities,
            'cancellation_policy' => $this->cancellationPolicy,
        ];
    }
}
