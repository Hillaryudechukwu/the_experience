<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ExternalSources\Contracts\WeatherProvider;
use App\Domains\ExternalSources\DTO\WeatherSnapshot;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Itineraries\Services\ItineraryPlanner;
use App\Domains\Itineraries\Services\ItineraryReplanner;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\GeoPoint;
use App\Domains\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/** Spec s8.3: replanning proposes, it never silently rewrites a paid plan. */
class ReplanTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_a_replan_is_a_proposal_and_leaves_the_current_plan_in_place(): void
    {
        [$actor, $trip, $date] = $this->scenario();

        $original = app(ItineraryPlanner::class)->generate($actor, $trip, [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);

        $result = app(ItineraryReplanner::class)->propose($actor, $trip->fresh(), [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);

        $this->assertNotNull($result['proposal']);
        $this->assertFalse($result['proposal']->is_current);
        $this->assertTrue($original->fresh()->is_current, 'The existing plan must stay current until the traveller accepts.');
        $this->assertNotEmpty($result['message']);
    }

    public function test_accepting_a_proposal_promotes_it(): void
    {
        [$actor, $trip, $date] = $this->scenario();

        $original = app(ItineraryPlanner::class)->generate($actor, $trip, ['from' => $date->toDateString(), 'to' => $date->toDateString()]);
        $result = app(ItineraryReplanner::class)->propose($actor, $trip->fresh(), ['from' => $date->toDateString(), 'to' => $date->toDateString()]);

        app(ItineraryReplanner::class)->accept($trip->fresh(), $result['proposal']);

        $this->assertFalse($original->fresh()->is_current);
        $this->assertTrue($result['proposal']->fresh()->is_current);
    }

    public function test_bad_weather_is_detected_as_the_reason_to_replan(): void
    {
        $this->swap(WeatherProvider::class, new class implements WeatherProvider
        {
            public function key(): string
            {
                return 'test_weather';
            }

            public function forecast(GeoPoint $point, CarbonImmutable $from, int $hours = 24): WeatherSnapshot
            {
                $hourly = [];
                for ($h = 0; $h <= max($hours, 48); $h++) {
                    $hourly[] = [
                        'at' => $from->startOfHour()->addHours($h)->toIso8601String(),
                        'condition' => 'rain',
                        'temp_c' => 11.0,
                        'precip_probability' => 95,
                    ];
                }

                return new WeatherSnapshot('rain', 11.0, 95, $hourly, Freshness::live('test_weather'), $from->addHours(6), $from->subHours(6));
            }
        });

        [$actor, $trip, $date] = $this->scenario(outdoor: true);

        app(ItineraryPlanner::class)->generate($actor, $trip, ['from' => $date->toDateString(), 'to' => $date->toDateString()]);

        $result = app(ItineraryReplanner::class)->propose($actor, $trip->fresh(), [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);

        $this->assertSame('weather', $result['trigger']['type'] ?? null);
        $this->assertSame('rain', $result['trigger']['condition']);
        $this->assertArrayHasKey('source', $result['trigger']);
    }

    /** @return array{0:Actor,1:Trip,2:CarbonImmutable} */
    private function scenario(bool $outdoor = false): array
    {
        $destination = $this->destination();
        $guest = GuestSession::create(['token' => Str::random(40)]);
        $actor = new Actor(guestSessionId: $guest->id);
        $date = CarbonImmutable::now('UTC')->addDay()->startOfDay();

        $journey = $this->journey($destination, $actor, [
            'reason' => 'holiday',
            'starts_on' => $date->toDateString(),
            'ends_on' => $date->toDateString(),
        ]);

        foreach (['one', 'two', 'three'] as $index => $slug) {
            $this->experience($destination, [
                'slug' => $slug,
                'title' => ucfirst($slug),
                'weather_exposure' => $outdoor ? 'outdoor' : 'indoor',
                'lat' => 51.5080 + ($index / 1000),
                'lng' => -0.1280 - ($index / 1000),
                'interest_affinity' => ['culture' => 80],
                'uniqueness' => 70,
                'iconic_weight' => 70,
                'value_signal' => 75,
            ]);
        }

        $trip = Trip::create(array_merge($actor->ownerAttributes(), [
            'journey_id' => $journey->id,
            'title' => 'Replan trip',
        ]));

        return [$actor, $trip->load('journey.destination'), $date];
    }
}
