<?php

declare(strict_types=1);

namespace App\Domains\Shared\ValueObjects;

final readonly class Money
{
    public function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public static function of(?int $minor, ?string $currency): ?self
    {
        return $minor === null ? null : new self($minor, $currency ?? 'GBP');
    }

    public function plus(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new \InvalidArgumentException('Cannot add mixed currencies without an FX rate.');
        }

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function times(int $factor): self
    {
        return new self($this->minor * $factor, $this->currency);
    }

    public function major(): float
    {
        return round($this->minor / 100, 2);
    }

    public function format(): string
    {
        $symbols = ['GBP' => '£', 'EUR' => '€', 'USD' => '$', 'JPY' => '¥'];
        $symbol = $symbols[$this->currency] ?? ($this->currency . ' ');
        $decimals = $this->currency === 'JPY' ? 0 : ($this->minor % 100 === 0 ? 0 : 2);

        return $symbol . number_format($this->major(), $decimals);
    }

    public function toArray(): array
    {
        return [
            'minor' => $this->minor,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }
}
