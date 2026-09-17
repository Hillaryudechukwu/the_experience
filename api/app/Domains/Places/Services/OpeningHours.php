<?php

declare(strict_types=1);

namespace App\Domains\Places\Services;

use App\Domains\Shared\ValueObjects\TimeWindow;
use Carbon\CarbonImmutable;

/**
 * Opening hours as stored on a canonical place.
 *
 *   {"mon":[["09:00","17:30"]], "tue":[], "exceptions":{"2026-12-25":[]}}
 *
 * An empty array means closed that day. A missing key means we do not know, and
 * "do not know" is never rendered as "open" (spec s15.1).
 */
final readonly class OpeningHours
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(private ?array $schedule) {}

    public function isKnown(): bool
    {
        return $this->schedule !== null && $this->schedule !== [];
    }

    /** @return list<TimeWindow> Opening windows on the given local date. */
    public function windowsOn(CarbonImmutable $date): array
    {
        if (! $this->isKnown()) {
            return [];
        }

        $key = self::DAYS[(int) $date->dayOfWeekIso - 1];
        $ranges = $this->schedule['exceptions'][$date->toDateString()] ?? $this->schedule[$key] ?? null;

        if (! is_array($ranges)) {
            return [];
        }

        $windows = [];
        foreach ($ranges as [$open, $close]) {
            $start = $date->setTimeFromTimeString($open);
            $end = $date->setTimeFromTimeString($close);
            if ($end <= $start) {
                $end = $end->addDay();   // closes after midnight
            }
            $windows[] = new TimeWindow($start, $end);
        }

        return $windows;
    }

    public function isOpenAt(CarbonImmutable $at): ?bool
    {
        if (! $this->isKnown()) {
            return null;
        }

        foreach ([$at->subDay(), $at] as $date) {
            foreach ($this->windowsOn($date->startOfDay()) as $window) {
                if ($at >= $window->start && $at < $window->end) {
                    return true;
                }
            }
        }

        return false;
    }

    public function closesAt(CarbonImmutable $at): ?CarbonImmutable
    {
        foreach ($this->windowsOn($at->startOfDay()) as $window) {
            if ($at >= $window->start && $at < $window->end) {
                return $window->end;
            }
        }

        return null;
    }

    /** Longest usable slice of the requested window during which the place is open. */
    public function usableMinutes(TimeWindow $window): ?int
    {
        if (! $this->isKnown()) {
            return null;
        }

        $best = 0;
        foreach ($this->windowsOn($window->start->startOfDay()) as $opening) {
            $start = $opening->start->max($window->start);
            $end = $opening->end->min($window->end);
            if ($end > $start) {
                $best = max($best, (int) $start->diffInMinutes($end));
            }
        }

        return $best;
    }

    public function toArray(): ?array
    {
        return $this->schedule;
    }
}
