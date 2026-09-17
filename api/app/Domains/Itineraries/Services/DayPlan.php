<?php

declare(strict_types=1);

namespace App\Domains\Itineraries\Services;

use App\Domains\Shared\ValueObjects\TimeWindow;
use Carbon\CarbonImmutable;

/** A single planned day: fixed anchors plus the flexible items placed around them. */
final class DayPlan
{
    /** @var list<array<string,mixed>> */
    public array $items = [];

    public float $objective = 0.0;

    public array $diagnostics = [];

    public function __construct(public readonly CarbonImmutable $date) {}

    public function add(array $item): void
    {
        $this->items[] = $item;
        usort($this->items, fn (array $a, array $b) => $a['starts_at'] <=> $b['starts_at']);
    }

    /** @return list<TimeWindow> */
    public function occupiedWindows(): array
    {
        return array_map(
            fn (array $item) => new TimeWindow($item['starts_at'], $item['ends_at']),
            $this->items,
        );
    }

    public function experienceCount(): int
    {
        return count(array_filter($this->items, fn (array $i) => $i['kind'] === 'experience'));
    }

    public function hasMeal(string $meal): bool
    {
        foreach ($this->items as $item) {
            if (($item['meta']['meal'] ?? null) === $meal) {
                return true;
            }
            if ($item['kind'] === 'experience' && ($item['meta']['is_food'] ?? false)) {
                return true;
            }
        }

        return false;
    }
}
