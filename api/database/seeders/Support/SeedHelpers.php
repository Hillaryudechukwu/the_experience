<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

trait SeedHelpers
{
    /**
     * Weekly opening hours.
     *
     * @param  array<string,array{0:string,1:string}|null>  $overrides  day => [open, close] or null for closed
     */
    protected function hours(string $open, string $close, array $overrides = []): array
    {
        $schedule = [];

        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            if (array_key_exists($day, $overrides)) {
                $schedule[$day] = $overrides[$day] === null ? [] : [$overrides[$day]];

                continue;
            }

            $schedule[$day] = [[$open, $close]];
        }

        return $schedule;
    }

    protected function allDay(): array
    {
        return $this->hours('00:00', '23:59');
    }

    protected function normalise(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name))));
    }
}
