<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Identity\Models\GuestSession;
use App\Domains\Shared\ValueObjects\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/**
 * Acceptance 55: a time-boxed request returns only options that fit the window,
 * with travel time included.
 */
class TimeBoxedDiscoveryTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_options_that_cannot_fit_the_window_are_excluded_not_merely_downranked(): void
    {
        $destination = $this->destination();

        $this->experience($destination, [
            'slug' => 'short-stop',
            'title' => 'Short stop',
            'min_duration_minutes' => 20,
            'expected_duration_minutes' => 40,
            'lat' => 51.5080, 'lng' => -0.1280,
        ]);

        $this->experience($destination, [
            'slug' => 'long-day-out',
            'title' => 'Long day out',
            'min_duration_minutes' => 240,
            'expected_duration_minutes' => 300,
            'max_duration_minutes' => 420,
            'lat' => 51.5085, 'lng' => -0.1285,
        ]);

        $response = $this->postJson('/api/discovery/time-boxed', [
            'destination_id' => $destination->id,
            'lat' => 51.5074,
            'lng' => -0.1278,
            'window_minutes' => 60,
        ]);

        $response->assertOk();
        $titles = array_column($response->json('data'), 'title');

        $this->assertContains('Short stop', $titles);
        $this->assertNotContains('Long day out', $titles);
    }

    public function test_travel_time_counts_against_the_window(): void
    {
        $destination = $this->destination();

        /* A 40-minute visit that is a long walk away does not fit a 45-minute window. */
        $this->experience($destination, [
            'slug' => 'far-away',
            'title' => 'Far away',
            'min_duration_minutes' => 40,
            'expected_duration_minutes' => 40,
            'lat' => 51.5600, 'lng' => -0.1278,
        ]);

        $response = $this->postJson('/api/discovery/time-boxed', [
            'destination_id' => $destination->id,
            'lat' => 51.5074,
            'lng' => -0.1278,
            'window_minutes' => 45,
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertNotNull($response->json('notice'));
    }

    public function test_a_later_anchor_caps_the_window_the_traveller_asked_for(): void
    {
        $destination = $this->destination();
        $guest = GuestSession::create(['token' => Str::random(40)]);
        $actor = new Actor(guestSessionId: $guest->id);

        $journey = $this->journey($destination, $actor, ['reason' => 'conference']);

        $now = CarbonImmutable::now();
        $journey->anchors()->create([
            'type' => 'restaurant',
            'title' => 'Team dinner',
            'starts_at' => $now->addMinutes(90),
            'ends_at' => $now->addMinutes(210),
            'buffer_before_minutes' => 15,
        ]);

        $this->experience($destination, ['slug' => 'anything', 'title' => 'Anything']);

        $response = $this->postJson('/api/discovery/time-boxed', [
            'destination_id' => $destination->id,
            'journey_id' => $journey->id,
            'lat' => 51.5074,
            'lng' => -0.1278,
            'window_minutes' => 300,
        ], ['X-Guest-Token' => $guest->token]);

        $response->assertOk();
        $this->assertLessThanOrEqual(75, $response->json('context.window_minutes'));
        $this->assertSame('Team dinner', $response->json('context.next_anchor.title'));
    }
}
