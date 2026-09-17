<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Contracts;

interface CurrencyProvider
{
    public function key(): string;

    /** @return array{rate: float, as_of: string, source: string}|null */
    public function rate(string $from, string $to): ?array;
}
