<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

use App\Domains\Shared\ValueObjects\Freshness;

final readonly class Availability
{
    /** @param list<AvailabilitySlot> $slots */
    public function __construct(
        public string $provider,
        public string $providerProductId,
        public array $slots,
        public Freshness $freshness,
        public bool $isLive,
        public ?string $unavailableReason = null,
    ) {}

    public static function unknown(string $provider, string $productId, string $reason): self
    {
        return new self($provider, $productId, [], Freshness::unknown(), false, $reason);
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_product_id' => $this->providerProductId,
            'slots' => array_map(fn (AvailabilitySlot $s) => $s->toArray(), $this->slots),
            'freshness' => $this->freshness->toArray(),
            'is_live' => $this->isLive,
            'unavailable_reason' => $this->unavailableReason,
        ];
    }
}
