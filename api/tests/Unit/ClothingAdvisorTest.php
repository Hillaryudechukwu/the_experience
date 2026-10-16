<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Discovery\Services\ClothingAdvisor;
use App\Domains\ExternalSources\DTO\WeatherSnapshot;
use App\Domains\Shared\ValueObjects\Freshness;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ClothingAdvisorTest extends TestCase
{
    public function test_each_band_and_the_rain_add_on(): void
    {
        $advisor = new ClothingAdvisor;

        $cold = $advisor->advise($this->weather('snow', 2, 10));
        $this->assertStringContainsString('waterproof', $cold['headline']);
        $this->assertContains('warm coat', $cold['items']);

        $coolWet = $advisor->advise($this->weather('rain', 6, 70));
        $this->assertContains('waterproof jacket', $coolWet['items']);

        $mild = $advisor->advise($this->weather('clear', 15, 0));
        $this->assertStringContainsString('Mild', $mild['headline']);
        $this->assertNotContains('waterproof jacket', $mild['items']);

        $warm = $advisor->advise($this->weather('clear', 21, 0));
        $this->assertStringContainsString('Warm', $warm['headline']);
        $this->assertNotContains('waterproof jacket', $warm['items']);

        $hot = $advisor->advise($this->weather('clear', 28, 0));
        $this->assertContains('sun protection', $hot['items']);

        $wet = $advisor->advise($this->weather('rain', 16, 80));
        $this->assertContains('waterproof jacket', $wet['items']);
    }

    public function test_rain_later_adds_a_waterproof_even_when_it_is_clear_now(): void
    {
        $now = CarbonImmutable::parse('2026-06-01T12:00:00Z');
        $hourly = [];
        for ($hour = 0; $hour < 4; $hour++) {
            $hourly[] = [
                'at' => $now->addHours($hour)->toIso8601String(),
                'condition' => $hour === 2 ? 'rain' : 'clear',
                'temp_c' => 21.0,
                'precip_probability' => $hour === 2 ? 80 : 5,
            ];
        }

        $advice = (new ClothingAdvisor)->advise(new WeatherSnapshot(
            condition: 'clear',
            temperatureC: 21,
            precipitationProbability: 5,
            hourly: $hourly,
            freshness: new Freshness('test', $now, Freshness::HIGHLY_DYNAMIC),
        ));

        $this->assertContains('waterproof jacket', $advice['items']);
        $this->assertStringContainsString('Rain is likely soon', $advice['headline']);
    }

    public function test_a_missing_forecast_adds_nothing(): void
    {
        $this->assertNull((new ClothingAdvisor)->advise(null));
    }

    private function weather(string $condition, float $temp, int $precip): WeatherSnapshot
    {
        $now = CarbonImmutable::parse('2026-06-01T12:00:00Z');

        return new WeatherSnapshot(
            condition: $condition,
            temperatureC: $temp,
            precipitationProbability: $precip,
            hourly: [[
                'at' => $now->toIso8601String(),
                'condition' => $condition,
                'temp_c' => $temp,
                'precip_probability' => $precip,
            ]],
            freshness: new Freshness('test', $now, Freshness::HIGHLY_DYNAMIC),
        );
    }
}
