<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ExternalSources\Contracts\WeatherProvider;
use App\Domains\ExternalSources\DTO\WeatherSnapshot;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Itineraries\Services\ItineraryPlanner;
use App\Domains\Itineraries\Services\ItineraryReplanner;
use App\Domains\Notifications\Models\NotificationOutbox;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\GeoPoint;
use App\Domains\Trips\Models\Trip;
use App\Models\User;
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

    public function test_interactive_replan_does_not_enqueue_a_push(): void
    {
        [$actor, $trip, $date] = $this->scenario();

        app(ItineraryPlanner::class)->generate($actor, $trip, [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);

        app(ItineraryReplanner::class)->propose($actor, $trip->fresh(), [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);

        $this->assertSame(0, NotificationOutbox::query()->where('kind', 'replan_proposed')->count());
    }

    public function test_proactive_weather_replan_notifies_once_per_day(): void
    {
        $user = User::factory()->create();
        [$actor, $trip, $date] = $this->scenario(outdoor: true, userId: $user->id, withIndoorAlternatives: true);

        /* Build the plan in clear weather so outdoor stops are on it, then
           turn the forecast bad — that is the proactive watcher's job. */
        $this->swap(WeatherProvider::class, $this->fixedWeather('clear'));
        app(ItineraryPlanner::class)->generate($actor, $trip, [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);

        $this->swap(WeatherProvider::class, $this->fixedWeather('rain'));

        $first = app(ItineraryReplanner::class)->proposeForWeather($actor, $trip->fresh(), [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);
        $this->assertNotNull($first);
        $this->assertNotEmpty($first['changes'], 'Weather should move outdoor items onto indoor alternatives.');

        $second = app(ItineraryReplanner::class)->proposeForWeather($actor, $trip->fresh(), [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);
        $this->assertNotNull($second);

        $this->assertSame(1, NotificationOutbox::query()->where('kind', 'replan_proposed')->count());
        $this->assertDatabaseHas('notifications_outbox', [
            'idempotency_key' => 'replan_weather:'.$trip->id.':'.CarbonImmutable::now($trip->journey->destination->timezone)->toDateString(),
        ]);
        $this->assertTrue(app(ItineraryReplanner::class)->proposedForWeatherToday($trip->fresh()));
    }

    public function test_an_interactive_replan_does_not_count_as_todays_weather_proposal(): void
    {
        [$actor, $trip, $date] = $this->scenario();

        app(ItineraryPlanner::class)->generate($actor, $trip, [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);
        app(ItineraryReplanner::class)->propose($actor, $trip->fresh(), [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);

        $this->assertFalse(app(ItineraryReplanner::class)->proposedForWeatherToday($trip->fresh()));
    }

    private function fixedWeather(string $condition): WeatherProvider
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
                for ($h = 0; $h <= max($hours, 48); $h++) {
                    $hourly[] = [
                        'at' => $from->startOfHour()->addHours($h)->toIso8601String(),
                        'condition' => $this->condition,
                        'temp_c' => 11.0,
                        'precip_probability' => $this->condition === 'rain' ? 95 : 5,
                    ];
                }

                return new WeatherSnapshot(
                    $this->condition,
                    11.0,
                    $this->condition === 'rain' ? 95 : 5,
                    $hourly,
                    Freshness::live('test_weather'),
                    $from->addHours(6),
                    $from->subHours(6),
                );
            }
        };
    }

    /** @return array{0:Actor,1:Trip,2:CarbonImmutable} */
    private function scenario(bool $outdoor = false, ?int $userId = null, bool $withIndoorAlternatives = false): array
    {
        $destination = $this->destination();
        $actor = $userId !== null
            ? new Actor(userId: $userId)
            : new Actor(guestSessionId: GuestSession::create(['token' => Str::random(40)])->id);
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

        if ($withIndoorAlternatives) {
            foreach (['gallery', 'museum', 'cafe'] as $index => $slug) {
                $this->experience($destination, [
                    'slug' => $slug,
                    'title' => ucfirst($slug),
                    'weather_exposure' => 'indoor',
                    'lat' => 51.5090 + ($index / 1000),
                    'lng' => -0.1290 - ($index / 1000),
                    'interest_affinity' => ['culture' => 85],
                    'uniqueness' => 72,
                    'iconic_weight' => 72,
                    'value_signal' => 78,
                ]);
            }
        }

        $trip = Trip::create(array_merge($actor->ownerAttributes(), [
            'journey_id' => $journey->id,
            'title' => 'Replan trip',
        ]));

        return [$actor, $trip->load('journey.destination'), $date];
    }
}
