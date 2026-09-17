<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\WeatherProvider;
use App\Domains\ExternalSources\DTO\WeatherSnapshot;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

class OpenMeteoWeatherProvider implements WeatherProvider
{
    public function __construct(private readonly HttpFactory $http) {}

    public function key(): string
    {
        return 'open_meteo';
    }

    public function forecast(GeoPoint $point, CarbonImmutable $from, int $hours = 24): WeatherSnapshot
    {
        $cacheKey = sprintf('weather:%s:%.3f:%.3f:%s', $this->key(), $point->lat, $point->lng, $from->format('Y-m-d-H'));

        $payload = Cache::remember($cacheKey, (int) config('experience.weather.cache_seconds', 900), function () use ($point) {
            return $this->http->timeout(6)->retry(2, 150)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => $point->lat,
                'longitude' => $point->lng,
                'hourly' => 'temperature_2m,precipitation_probability,weathercode',
                'daily' => 'sunrise,sunset',
                'forecast_days' => 3,
                'timezone' => 'auto',
            ])->throw()->json();
        });

        $hourly = [];
        $times = $payload['hourly']['time'] ?? [];
        foreach ($times as $i => $time) {
            $at = CarbonImmutable::parse($time);
            if ($at->lt($from->startOfHour()) || $at->gt($from->addHours($hours))) {
                continue;
            }
            $hourly[] = [
                'at' => $at->toIso8601String(),
                'condition' => $this->mapCode($payload['hourly']['weathercode'][$i] ?? 0),
                'temp_c' => (float) ($payload['hourly']['temperature_2m'][$i] ?? 0),
                'precip_probability' => (int) ($payload['hourly']['precipitation_probability'][$i] ?? 0),
            ];
        }

        $current = $hourly[0] ?? ['condition' => 'clear', 'temp_c' => 0.0, 'precip_probability' => 0];

        return new WeatherSnapshot(
            condition: $current['condition'],
            temperatureC: $current['temp_c'],
            precipitationProbability: $current['precip_probability'],
            hourly: $hourly,
            freshness: Freshness::live($this->key()),
            sunset: isset($payload['daily']['sunset'][0]) ? CarbonImmutable::parse($payload['daily']['sunset'][0]) : null,
            sunrise: isset($payload['daily']['sunrise'][0]) ? CarbonImmutable::parse($payload['daily']['sunrise'][0]) : null,
        );
    }

    /** WMO weather interpretation codes -> our condition vocabulary. */
    private function mapCode(int $code): string
    {
        return match (true) {
            $code === 0 => 'clear',
            $code <= 2 => 'partly_cloudy',
            $code === 3 => 'cloudy',
            in_array($code, [45, 48], true) => 'fog',
            $code >= 51 && $code <= 57 => 'drizzle',
            $code >= 61 && $code <= 65 => 'rain',
            $code >= 66 && $code <= 67 => 'sleet',
            $code >= 71 && $code <= 77 => 'snow',
            $code >= 80 && $code <= 82 => 'heavy_rain',
            $code >= 85 && $code <= 86 => 'snow',
            $code >= 95 => 'storm',
            default => 'cloudy',
        };
    }
}
