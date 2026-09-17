<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\WeatherProvider;
use App\Domains\ExternalSources\DTO\WeatherSnapshot;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;

/**
 * Deterministic offline weather.
 *
 * Used in tests and when no network is available. It is marked with the source
 * `seeded_forecast` so the client can tell the traveller this is a simulation
 * rather than a real forecast.
 */
class SeededWeatherProvider implements WeatherProvider
{
    private const CONDITIONS = ['clear', 'partly_cloudy', 'cloudy', 'rain', 'drizzle'];

    public function key(): string
    {
        return 'seeded_forecast';
    }

    public function forecast(GeoPoint $point, CarbonImmutable $from, int $hours = 24): WeatherSnapshot
    {
        $hourly = [];
        for ($h = 0; $h <= $hours; $h++) {
            $at = $from->startOfHour()->addHours($h);
            $seed = crc32(sprintf('%.2f:%.2f:%s', $point->lat, $point->lng, $at->format('Y-m-d-H')));
            $condition = self::CONDITIONS[$seed % count(self::CONDITIONS)];

            $hourly[] = [
                'at' => $at->toIso8601String(),
                'condition' => $condition,
                'temp_c' => round(9 + ($seed % 14), 1),
                'precip_probability' => in_array($condition, ['rain', 'drizzle'], true) ? 60 + ($seed % 30) : ($seed % 20),
            ];
        }

        return new WeatherSnapshot(
            condition: $hourly[0]['condition'],
            temperatureC: $hourly[0]['temp_c'],
            precipitationProbability: $hourly[0]['precip_probability'],
            hourly: $hourly,
            freshness: new Freshness($this->key(), CarbonImmutable::now(), Freshness::HIGHLY_DYNAMIC),
            sunset: $from->setTime(19, 42),
            sunrise: $from->setTime(6, 48),
        );
    }
}
