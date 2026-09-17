<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

use App\Domains\Shared\ValueObjects\Money;

final readonly class BookingResult
{
    public function __construct(
        public bool $success,
        public string $fulfilment,          // redirect|native
        public ?string $providerBookingId = null,
        public ?string $redirectUrl = null,
        public ?Money $total = null,
        public ?string $cancellationPolicy = null,
        public ?string $failureReason = null,
        public array $meta = [],
    ) {}

    public static function redirect(string $url, ?string $cancellationPolicy = null, array $meta = []): self
    {
        return new self(true, 'redirect', null, $url, null, $cancellationPolicy, null, $meta);
    }

    public static function failed(string $reason): self
    {
        return new self(false, 'none', failureReason: $reason);
    }
}
