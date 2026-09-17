<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

use App\Domains\Shared\ValueObjects\Freshness;
use Carbon\CarbonImmutable;

final readonly class WeatherSnapshot
{
    /** @param list<array{at:string,condition:string,temp_c:float,precip_probability:int}> $hourly */
    public function __construct(
        public string $condition,
        public float $temperatureC,
        public int $precipitationProbability,
        public array $hourly,
        public Freshness $freshness,
        public ?CarbonImmutable $sunset = null,
        public ?CarbonImmutable $sunrise = null,
    ) {}

    public function isPoor(): bool
    {
        return in_array($this->condition, (array) config('experience.weather.poor_conditions'), true);
    }

    /** Condition at a given moment, falling back to the current condition. */
    public function conditionAt(CarbonImmutable $at): string
    {
        foreach ($this->hourly as $point) {
            if (CarbonImmutable::parse($point['at'])->isSameHour($at)) {
                return $point['condition'];
            }
        }

        return $this->condition;
    }

    public function isPoorAt(CarbonImmutable $at): bool
    {
        return in_array($this->conditionAt($at), (array) config('experience.weather.poor_conditions'), true);
    }

    public function daylightRemainingMinutes(CarbonImmutable $now): ?int
    {
        if ($this->sunset === null) {
            return null;
        }

        return $now->lt($this->sunset) ? (int) $now->diffInMinutes($this->sunset) : 0;
    }

    public function toArray(): array
    {
        return [
            'condition' => $this->condition,
            'temperature_c' => $this->temperatureC,
            'precipitation_probability' => $this->precipitationProbability,
            'is_poor' => $this->isPoor(),
            'sunrise' => $this->sunrise?->toIso8601String(),
            'sunset' => $this->sunset?->toIso8601String(),
            'hourly' => $this->hourly,
            'freshness' => $this->freshness->toArray(),
        ];
    }
}
