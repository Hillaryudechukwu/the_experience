<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Identity\Models\GuestSession;
use App\Domains\Itineraries\Services\ItineraryPlanner;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\TimeWindow;
use App\Domains\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/** Acceptance 56: an itinerary never overlaps a fixed anchor. */
class ItineraryTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_the_planner_builds_around_anchors_and_never_through_them(): void
    {
        [$actor, $guest, $trip, $journey, $date] = $this->scenario();

        $itinerary = app(ItineraryPlanner::class)->generate($actor, $trip, [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);

        $items = $itinerary->days->flatMap->items;

        $this->assertGreaterThan(0, $items->where('kind', 'experience')->count(), 'The planner should fill the free window.');

        foreach ($items->where('kind', '!=', 'anchor') as $item) {
            $window = new TimeWindow(CarbonImmutable::parse($item->starts_at), CarbonImmutable::parse($item->ends_at));

            foreach ($journey->anchors as $anchor) {
                $this->assertFalse(
                    $window->overlaps($anchor->blockedWindow()),
                    sprintf('"%s" overlaps the protected window of "%s".', $item->title, $anchor->title),
                );
            }
        }
    }

    public function test_anchors_appear_in_the_plan_as_locked_items(): void
    {
        [$actor, $guest, $trip, $journey, $date] = $this->scenario();

        $itinerary = app(ItineraryPlanner::class)->generate($actor, $trip, [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);

        $anchors = $itinerary->days->flatMap->items->where('kind', 'anchor');

        $this->assertCount(2, $anchors);
        $this->assertTrue($anchors->every(fn ($item) => $item->locked === true));
    }

    public function test_manually_adding_an_item_over_an_anchor_is_refused(): void
    {
        [$actor, $guest, $trip, $journey, $date] = $this->scenario();

        $itinerary = app(ItineraryPlanner::class)->generate($actor, $trip, [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);

        $experienceId = $trip->journey->destination->experiences()->first()->id;

        $response = $this->postJson("/api/itineraries/{$itinerary->id}/items", [
            'experience_id' => $experienceId,
            'date' => $date->toDateString(),
            'starts_at' => $date->setTime(10, 0)->toIso8601String(),
        ], ['X-Guest-Token' => $guest->token]);

        $response->assertStatus(422);
        $this->assertStringContainsString('clashes', $response->json('message'));
    }

    public function test_an_item_in_a_genuinely_free_slot_is_accepted(): void
    {
        [$actor, $guest, $trip, $journey, $date] = $this->scenario();

        $itinerary = app(ItineraryPlanner::class)->generate($actor, $trip, [
            'from' => $date->toDateString(),
            'to' => $date->toDateString(),
        ]);

        $experienceId = $trip->journey->destination->experiences()->first()->id;

        $this->postJson("/api/itineraries/{$itinerary->id}/items", [
            'experience_id' => $experienceId,
            'date' => $date->toDateString(),
            'starts_at' => $date->setTime(17, 30)->toIso8601String(),
        ], ['X-Guest-Token' => $guest->token])->assertCreated();
    }

    /** @return array{0:Actor,1:GuestSession,2:Trip,3:\App\Domains\Journeys\Models\Journey,4:CarbonImmutable} */
    private function scenario(): array
    {
        $destination = $this->destination();
        $guest = GuestSession::create(['token' => Str::random(40)]);
        $actor = new Actor(guestSessionId: $guest->id);

        $date = CarbonImmutable::now('UTC')->addDay()->startOfDay();

        $journey = $this->journey($destination, $actor, [
            'reason' => 'conference',
            'starts_on' => $date->toDateString(),
            'ends_on' => $date->toDateString(),
            'daily_budget_minor' => 10000,
        ]);

        $journey->anchors()->create([
            'type' => 'conference',
            'title' => 'Conference programme',
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(16, 0),
            'buffer_before_minutes' => 30,
        ]);

        $journey->anchors()->create([
            'type' => 'restaurant',
            'title' => 'Team dinner',
            'starts_at' => $date->setTime(20, 0),
            'ends_at' => $date->setTime(22, 0),
            'buffer_before_minutes' => 20,
        ]);

        foreach ([['near-stop', 51.5080, -0.1280, 60], ['second-stop', 51.5095, -0.1300, 45], ['third-stop', 51.5060, -0.1250, 50]] as [$slug, $lat, $lng, $duration]) {
            $this->experience($destination, [
                'slug' => $slug,
                'title' => ucfirst(str_replace('-', ' ', $slug)),
                'min_duration_minutes' => (int) ($duration * 0.6),
                'expected_duration_minutes' => $duration,
                'max_duration_minutes' => $duration * 2,
                'lat' => $lat,
                'lng' => $lng,
                'interest_affinity' => ['culture' => 80, 'history' => 70],
                'uniqueness' => 70,
                'iconic_weight' => 70,
                'value_signal' => 80,
                'categories' => ['quick_experience', 'culture'],
            ]);
        }

        $journey->refresh()->load('anchors');

        $trip = Trip::create(array_merge($actor->ownerAttributes(), [
            'journey_id' => $journey->id,
            'title' => 'Test trip',
        ]));

        return [$actor, $guest, $trip->load('journey.destination'), $journey, $date];
    }
}
