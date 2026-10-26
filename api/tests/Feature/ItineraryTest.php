<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ExternalSources\Models\ProviderProduct;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Itineraries\Services\ItineraryPlanner;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\TimeWindow;
use App\Domains\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
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

    /** A ticket the traveller bought is a fixed point, not a suggestion to repeat. */
    public function test_a_booked_ticket_is_planned_around_and_not_suggested_again(): void
    {
        $this->seed(DatabaseSeeder::class);
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $headers = ['X-Guest-Token' => $token];
        $destinationId = $this->getJson('/api/destinations?q=london')->json('data.0.id');
        $day = CarbonImmutable::now('Europe/London')->addDays(4)->toDateString();

        $journeyId = $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'holiday',
            'starts_on' => $day,
            'ends_on' => $day,
        ], $headers)->assertCreated()->json('data.id');
        $tripId = $this->postJson('/api/trips', ['journey_id' => $journeyId], $headers)->assertCreated()->json('data.id');

        $product = ProviderProduct::where('provider', 'sandbox')
            ->whereHas('experience', fn ($q) => $q->where('destination_id', $destinationId))
            ->firstOrFail();
        $this->postJson('/api/bookings', [
            'provider' => 'sandbox',
            'provider_product_id' => $product->provider_product_id,
            'quantity' => 1,
            'starts_at' => "{$day}T11:30:00",
        ], $headers + ['Idempotency-Key' => 'planned-booking'])->assertCreated();

        $items = collect($this->postJson("/api/trips/{$tripId}/generate-itinerary", [], $headers)
            ->assertCreated()->json('data.days.0.items'));

        $forProduct = $items->filter(fn (array $item) => ($item['experience']['id'] ?? null) === $product->experience_id);

        $this->assertCount(1, $forProduct);
        $this->assertSame('anchor', $forProduct->first()['kind']);
        $this->assertTrue($forProduct->first()['locked']);
        $this->assertTrue(CarbonImmutable::parse($forProduct->first()['starts_at'])->equalTo(CarbonImmutable::parse("{$day} 11:30", 'Europe/London')));
    }

    /** A ticket in another city must not lock a slot on this plan. */
    public function test_a_booking_in_another_city_is_not_planned_here(): void
    {
        $this->seed(DatabaseSeeder::class);
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $headers = ['X-Guest-Token' => $token];
        $londonId = $this->getJson('/api/destinations?q=london')->json('data.0.id');
        $romeId = $this->getJson('/api/destinations?q=rome')->json('data.0.id');
        $day = CarbonImmutable::now('Europe/London')->addDays(4)->toDateString();

        $journeyId = $this->postJson('/api/journeys', [
            'destination_id' => $londonId,
            'reason' => 'holiday',
            'starts_on' => $day,
            'ends_on' => $day,
        ], $headers)->assertCreated()->json('data.id');
        $tripId = $this->postJson('/api/trips', ['journey_id' => $journeyId], $headers)->assertCreated()->json('data.id');

        $romeProduct = ProviderProduct::where('provider', 'sandbox')
            ->whereHas('experience', fn ($q) => $q->where('destination_id', $romeId))
            ->firstOrFail();
        $this->postJson('/api/bookings', [
            'provider' => 'sandbox',
            'provider_product_id' => $romeProduct->provider_product_id,
            'quantity' => 1,
            'starts_at' => "{$day}T11:30:00",
        ], $headers + ['Idempotency-Key' => 'rome-booking'])->assertCreated();

        $items = collect($this->postJson("/api/trips/{$tripId}/generate-itinerary", [], $headers)
            ->assertCreated()->json('data.days.0.items'));

        $this->assertTrue(
            $items->every(fn (array $item) => ($item['experience']['id'] ?? null) !== $romeProduct->experience_id),
            'A Rome ticket must not appear as a locked item on a London plan.',
        );
    }

    /** The busiest days are the ones that most need lunch kept free. */
    public function test_a_planned_day_keeps_lunch(): void
    {
        $this->seed(DatabaseSeeder::class);
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $headers = ['X-Guest-Token' => $token];
        $destinationId = $this->getJson('/api/destinations?q=london')->json('data.0.id');
        $day = CarbonImmutable::now('Europe/London')->addDays(4)->toDateString();

        $journeyId = $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'holiday',
            'starts_on' => $day,
            'ends_on' => $day,
        ], $headers)->assertCreated()->json('data.id');
        $tripId = $this->postJson('/api/trips', ['journey_id' => $journeyId], $headers)->assertCreated()->json('data.id');

        $items = collect($this->postJson("/api/trips/{$tripId}/generate-itinerary", [], $headers)
            ->assertCreated()->json('data.days.0.items'));

        $this->assertNotEmpty($items->where('kind', 'experience'));
        $this->assertNotNull($items->first(fn (array $item) => $item['kind'] === 'meal' && $item['title'] === 'Lunch'));
    }

    public function test_generating_without_overrides_uses_journey_stay_dates(): void
    {
        $destination = $this->destination();
        $guest = GuestSession::create(['token' => Str::random(40)]);
        $actor = new Actor(guestSessionId: $guest->id);

        $start = CarbonImmutable::now('UTC')->addDay()->startOfDay();
        $end = $start->addDays(6);

        $journey = $this->journey($destination, $actor, [
            'reason' => 'holiday',
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
        ]);

        foreach ([['near-stop', 51.5080, -0.1280, 60], ['second-stop', 51.5095, -0.1300, 45]] as [$slug, $lat, $lng, $duration]) {
            $this->experience($destination, [
                'slug' => $slug,
                'title' => ucfirst(str_replace('-', ' ', $slug)),
                'min_duration_minutes' => (int) ($duration * 0.6),
                'expected_duration_minutes' => $duration,
                'max_duration_minutes' => $duration * 2,
                'lat' => $lat,
                'lng' => $lng,
                'interest_affinity' => ['culture' => 80],
                'uniqueness' => 70,
                'iconic_weight' => 70,
                'value_signal' => 80,
                'categories' => ['quick_experience', 'culture'],
            ]);
        }

        $trip = Trip::create(array_merge($actor->ownerAttributes(), [
            'journey_id' => $journey->id,
            'title' => 'Week in town',
        ]));

        $response = $this->postJson("/api/trips/{$trip->id}/generate-itinerary", [], [
            'X-Guest-Token' => $guest->token,
        ]);

        $response->assertCreated();
        $this->assertCount(7, $response->json('data.days'));
        $this->assertSame($start->toDateString(), $response->json('data.days.0.date'));
        $this->assertSame($end->toDateString(), $response->json('data.days.6.date'));
    }

    /** @return array{0:Actor,1:GuestSession,2:Trip,3:Journey,4:CarbonImmutable} */
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
