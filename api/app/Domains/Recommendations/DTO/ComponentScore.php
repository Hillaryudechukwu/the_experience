<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\DTO;

final readonly class ComponentScore
{
    /** @param list<Reason> $reasons */
    public function __construct(
        public string $component,
        public float $value,        // 0.0 - 1.0
        public array $reasons = [],
        public bool $isNeutral = false,   // true when we lack the data to judge
    ) {}

    public static function neutral(string $component, string $why = ''): self
    {
        return new self($component, 0.5, $why === '' ? [] : [Reason::minus($why)], true);
    }

    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'value' => round($this->value, 3),
            'is_neutral' => $this->isNeutral,
            'reasons' => array_map(fn (Reason $r) => $r->toArray(), $this->reasons),
        ];
    }
}
