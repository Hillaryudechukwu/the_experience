<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

use Carbon\CarbonImmutable;

final readonly class BookingRequest
{
    public function __construct(
        public string $providerProductId,
        public string $idempotencyKey,
        public int $quantity,
        public ?CarbonImmutable $startsAt,
        public array $travellers = [],
        public array $contact = [],
        public array $meta = [],
    ) {}
}
