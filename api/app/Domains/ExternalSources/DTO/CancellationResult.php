<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

final readonly class CancellationResult
{
    public function __construct(
        public bool $success,
        public bool $refundExpected = false,
        public ?int $refundAmountMinor = null,
        public ?string $reason = null,
    ) {}
}
