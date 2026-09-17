<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Discovery\Services\CandidateBuilder;
use App\Domains\Discovery\Services\ContextEngine;
use App\Domains\ExternalSources\Contracts\WeatherProvider;
use App\Domains\ExternalSources\DTO\WeatherSnapshot;
use App\Domains\Itineraries\Services\ItineraryReplanner;
use App\Domains\JourneyIntelligence\Services\MissionInterpreter;
use App\Domains\Recommendations\Scoring\ExperienceScorer;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/** Acceptance 60: the app can suggest weather-appropriate alternatives. */
class WeatherAwareTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_rain_moves_the_indoor_option_above_the_outdoor_one(): void
    {
        $destination = $this->destination();

        $this->experience($destination, [
            'slug' => 'open-air-garden',
            'title' => 'Open air garden',
            'weather_exposure' => 'outdoor',
            'interest_affinity' => ['nature' => 90],
            'uniqueness' => 70, 'iconic_weight' => 70, 'value_signal' => 70,
            'lat' => 51.5080, 'lng' => -0.1280,
        ]);

        $this->experience($destination, [
            'slug' => 'covered-gallery',
            'title' => 'Covered gallery',
            'weather_exposure' => 'indoor',
            'interest_affinity' => ['nature' => 90],
            'uniqueness' => 70, 'iconic_weight' => 70, 'value_signal' => 70,
            'lat' => 51.5081, 'lng' => -0.1281,
        ]);

        $dry = $this->rank($destination, 'clear');
        $wet = $this->rank($destination, 'rain');

        $this->assertSame('Open air garden', $dry[0]['title'], 'On a clear day the garden should win.');
        $this->assertSame('Covered gallery', $wet[0]['title'], 'In the rain the indoor option should win.');

        $this->assertStringContainsString('suits the weather', implode(' ', $wet[0]['why']));
        $this->assertStringContainsString('Rain expected', implode(' ', $wet[1]['caveats']));
    }

    public function test_an_unavailable_forecast_leaves_the_component_neutral_rather_than_guessing(): void
    {
        $destination = $this->destination();
        $this->experience($destination, ['slug' => 'anything', 'title' => 'Anything', 'weather_exposure' => 'outdoor']);

        $this->swap(WeatherProvider::class, new class implements WeatherProvider
        {
            public function key(): string
            {
                return 'broken';
            }

            public function forecast(GeoPoint $point, CarbonImmutable $from, int $hours = 24): WeatherSnapshot
            {
                throw new \RuntimeException('Forecast service unreachable');
            }
        });

        $context = app(ContextEngine::class)->build(new Actor, [
            'destination_id' => $destination->id,
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);

        $this->assertNull($context->weather, 'A broken forecast must not stop the request.');

        $candidates = app(CandidateBuilder::class)->build($context, ['destination_id' => $destination->id]);
        $scored = app(ExperienceScorer::class)->score($candidates[0], $context);

        $weather = collect($scored->components)->firstWhere('component', 'weather_fit');
        $this->assertTrue($weather->isNeutral);
        $this->assertGreaterThan(0, $scored->score);
    }

    /** @return list<array{title:string, score:int, why:list<string>, caveats:list<string>}> */
    private function rank($destination, string $condition): array
    {
        $this->swap(WeatherProvider::class, $this->weather($condition));

        $context = app(ContextEngine::class)->build(new Actor, [
            'destination_id' => $destination->id,
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);

        $this->assertSame($condition, $context->weather?->condition);

        $candidates = app(CandidateBuilder::class)->build($context, ['destination_id' => $destination->id]);
        $ranked = app(ExperienceScorer::class)->rank($candidates, $context);

        return array_map(fn ($scored) => [
            'title' => $scored->candidate->experience->title,
            'score' => $scored->score,
            'why' => array_map(fn ($r) => $r->message, $scored->reasons('positive')),
            'caveats' => array_map(fn ($r) => $r->message, $scored->reasons('negative')),
        ], $ranked);
    }

    private function weather(string $condition): WeatherProvider
    {
        return new class($condition) implements WeatherProvider
        {
            public function __construct(private readonly string $condition) {}

            public function key(): string
            {
                return 'test_weather';
            }

            public function forecast(GeoPoint $point, CarbonImmutable $from, int $hours = 24): WeatherSnapshot
            {
                $hourly = [];
                for ($h = 0; $h <= $hours; $h++) {
                    $hourly[] = [
                        'at' => $from->startOfHour()->addHours($h)->toIso8601String(),
                        'condition' => $this->condition,
                        'temp_c' => 15.0,
                        'precip_probability' => $this->condition === 'rain' ? 90 : 5,
                    ];
                }

                return new WeatherSnapshot(
                    condition: $this->condition,
                    temperatureC: 15.0,
                    precipitationProbability: $this->condition === 'rain' ? 90 : 5,
                    hourly: $hourly,
                    freshness: Freshness::live('test_weather'),
                    sunset: $from->addHours(6),
                    sunrise: $from->subHours(6),
                );
            }
        };
    }
}
