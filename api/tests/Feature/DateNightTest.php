<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Discovery\Models\LiveIntent;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Shared\ValueObjects\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/**
 * Date night: an evening for two, tonight or later, with every stop open at
 * the hour it is planned for and close to the one before.
 */
class DateNightTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    private Destination $destination;

    protected function setUp(): void
    {
        parent::setUp();

        /* A Monday afternoon in London. */
        $this->travelTo(CarbonImmutable::parse('2026-11-02 14:00', 'Europe/London'));

        $this->destination = $this->destination();
    }

    public function test_tonight_starts_at_seven_with_opener_dinner_and_nightcap_in_order(): void
    {
        $this->evening();

        $response = $this->plan(['date' => '2026-11-02']);

        $response->assertOk();
        $this->assertSame(['opener', 'dinner', 'nightcap'], array_column($response->json('data.stops'), 'role'));
        $this->assertSame('Sunset viewpoint', $response->json('data.stops.0.experience.title'));
        $this->assertSame('Candlelit bistro', $response->json('data.stops.1.experience.title'));
        $this->assertSame('Wine bar', $response->json('data.stops.2.experience.title'));
        $this->assertStringStartsWith('2026-11-02T19:00', $response->json('data.stops.0.starts_at'));

        /* Each stop starts no earlier than the last one ended. */
        $stops = $response->json('data.stops');
        for ($i = 1; $i < count($stops); $i++) {
            $this->assertGreaterThanOrEqual(
                CarbonImmutable::parse($stops[$i - 1]['ends_at']),
                CarbonImmutable::parse($stops[$i]['starts_at']),
            );
        }
    }

    public function test_tonight_after_the_start_has_passed_begins_at_the_next_quarter_hour(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 21:10', 'Europe/London'));
        $this->evening();

        $response = $this->plan(['date' => '2026-11-02']);

        $response->assertOk();
        $this->assertStringStartsWith('2026-11-02T21:15', $response->json('data.starts_at'));

        /* Too short an evening for an opener: dinner comes first. */
        $this->assertSame('dinner', $response->json('data.stops.0.role'));
    }

    public function test_too_late_tonight_is_refused_with_a_way_out(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 22:30', 'Europe/London'));
        $this->evening();

        $this->plan(['date' => '2026-11-02'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'date_night_too_late');
    }

    public function test_a_past_date_is_refused(): void
    {
        $this->evening();

        $this->plan(['date' => '2026-11-01'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'date_night_in_past');
    }

    public function test_a_date_after_the_trip_ends_is_refused(): void
    {
        $this->evening();
        $guest = GuestSession::create(['token' => Str::random(40)]);
        $journey = $this->journey($this->destination, new Actor(guestSessionId: $guest->id), [
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-05',
        ]);

        $this->plan(['date' => '2026-11-06', 'journey_id' => $journey->id], ['X-Guest-Token' => $guest->token])
            ->assertStatus(422)
            ->assertJsonPath('code', 'date_night_out_of_range');
    }

    public function test_a_future_date_is_planned_for_that_day_so_a_restaurant_closed_then_is_never_dinner(): void
    {
        $this->evening();

        /* Better on paper than the bistro, but shut on Saturdays. */
        $closedSaturday = $this->openAllWeek('12:00', '23:59');
        $closedSaturday['sat'] = [];
        $this->experience($this->destination, [
            'slug' => 'starred-room',
            'title' => 'Starred room',
            'categories' => ['food_experience', 'romantic'],
            'interest_affinity' => ['food' => 95],
            'mood_affinity' => ['romantic' => 100],
            'romance_score' => 100,
            'expected_duration_minutes' => 100,
            'opening_hours' => $closedSaturday,
            'lat' => 51.5076, 'lng' => -0.1279,
        ]);

        $saturday = $this->plan(['date' => '2026-11-07'])->assertOk();
        $this->assertSame('Candlelit bistro', $this->dinner($saturday->json('data.stops')));
        $this->assertStringStartsWith('2026-11-07T19:00', $saturday->json('data.starts_at'));

        $friday = $this->plan(['date' => '2026-11-06'])->assertOk();
        $this->assertSame('Starred room', $this->dinner($friday->json('data.stops')));
    }

    public function test_more_than_the_forecast_ahead_carries_no_made_up_weather(): void
    {
        $this->evening();

        $response = $this->plan(['date' => '2026-11-12'])->assertOk();

        $this->assertNull($response->json('data.weather'));
        $this->assertNotNull($response->json('data.weather_note'));
    }

    public function test_a_nightcap_too_far_from_dinner_is_left_out(): void
    {
        $this->evening(nightcapFarAway: true);

        $response = $this->plan(['date' => '2026-11-02'])->assertOk();

        $this->assertNotContains('nightcap', array_column($response->json('data.stops'), 'role'));
    }

    public function test_no_dinner_that_fits_says_so(): void
    {
        $this->experience($this->destination, [
            'slug' => 'viewpoint',
            'title' => 'Sunset viewpoint',
            'categories' => ['romantic', 'quick_experience'],
            'expected_duration_minutes' => 60,
        ]);

        $response = $this->plan(['date' => '2026-11-02'])->assertOk();

        $this->assertNotContains('dinner', array_column($response->json('data.stops') ?? [], 'role'));
        $this->assertStringContainsString('No dinner', $response->json('notice'));
    }

    public function test_planning_a_date_neither_writes_nor_reads_live_intent(): void
    {
        $this->evening();
        $guest = GuestSession::create(['token' => Str::random(40)]);
        $headers = ['X-Guest-Token' => $guest->token];

        /* Today's Discover has a one-hour window remembered. */
        $this->postJson('/api/discovery/time-boxed', [
            'destination_id' => $this->destination->id,
            'lat' => 51.5074,
            'lng' => -0.1278,
            'window_minutes' => 60,
        ], $headers)->assertOk();

        $response = $this->plan(['date' => '2026-11-07'], $headers)->assertOk();

        /* The one-hour window did not shrink the evening… */
        $this->assertGreaterThanOrEqual(180, $response->json('context.window_minutes'));

        /* …and the date left today's intent exactly as it was. */
        $intent = LiveIntent::query()->sole();
        $this->assertSame(60, (int) $intent->window_minutes);
        $this->assertNull($intent->mood);
    }

    public function test_the_plan_is_recorded_as_a_date_night_set(): void
    {
        $this->evening();

        $response = $this->plan(['date' => '2026-11-02'])->assertOk();

        $this->assertDatabaseHas('recommendation_sets', [
            'id' => $response->json('recommendation_set_id'),
            'surface' => 'date_night',
        ]);
        $this->assertDatabaseCount('recommendations', 3);
    }

    public function test_a_date_is_required(): void
    {
        $this->plan([])->assertStatus(422)->assertJsonValidationErrors('date');
    }

    private function plan(array $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/discovery/date-night', array_merge([
            'destination_id' => $this->destination->id,
            'lat' => 51.5074,
            'lng' => -0.1278,
        ], $body), $headers);
    }

    private function dinner(array $stops): ?string
    {
        foreach ($stops as $stop) {
            if ($stop['role'] === 'dinner') {
                return $stop['experience']['title'];
            }
        }

        return null;
    }

    /** A first stop, a dinner and a nightcap, all a short walk apart and open late. */
    private function evening(bool $nightcapFarAway = false): void
    {
        $this->experience($this->destination, [
            'slug' => 'viewpoint',
            'title' => 'Sunset viewpoint',
            'categories' => ['romantic', 'quick_experience'],
            'mood_affinity' => ['romantic' => 90],
            'romance_score' => 90,
            'weather_exposure' => 'outdoor',
            'expected_duration_minutes' => 60,
            'lat' => 51.5075, 'lng' => -0.1278,
        ]);

        $this->experience($this->destination, [
            'slug' => 'bistro',
            'title' => 'Candlelit bistro',
            'categories' => ['food_experience'],
            'interest_affinity' => ['food' => 90],
            'mood_affinity' => ['romantic' => 85],
            'romance_score' => 85,
            'is_free' => false,
            'price_from_minor' => 4000,
            'expected_duration_minutes' => 90,
            'opening_hours' => $this->openAllWeek('12:00', '23:59'),
            'lat' => 51.5078, 'lng' => -0.1281,
        ]);

        $this->experience($this->destination, [
            'slug' => 'wine-bar',
            'title' => 'Wine bar',
            'categories' => ['nightlife'],
            'mood_affinity' => ['romantic' => 80],
            'romance_score' => 80,
            'expected_duration_minutes' => 50,
            'opening_hours' => $this->openAllWeek('16:00', '23:59'),
            'lat' => $nightcapFarAway ? 51.5500 : 51.5080,
            'lng' => $nightcapFarAway ? -0.1900 : -0.1283,
        ]);
    }
}
