<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance 52: a traveller can create a profile with interests, budget and pace.
 * Acceptance 53: a journey can store reason, companions, mission and anchors.
 */
class TravellerAndJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_traveller_profile_stores_interests_budget_and_pace(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        $response = $this->patchJson('/api/traveller/profile', [
            'interests' => ['history' => 91, 'food' => 86, 'architecture' => 78, 'nightlife' => 31],
            'travel_pace' => 'moderate',
            'walking_tolerance' => 'medium',
            'budget_level' => 'moderate',
            'daily_experience_budget_minor' => 3500,
        ], ['X-Guest-Token' => $token]);

        $response->assertOk();
        $this->assertSame('moderate', $response->json('data.travel_pace'));
        $this->assertSame(3500, $response->json('data.daily_experience_budget.minor'));
        $this->assertSame('history', $response->json('data.interests.0.interest'));
    }

    public function test_experience_dna_summarises_the_profile_in_plain_language(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        $this->patchJson('/api/traveller/profile', [
            'interests' => ['history' => 91, 'food' => 86, 'architecture' => 78],
            'travel_pace' => 'moderate',
            'daily_experience_budget_minor' => 3500,
        ], ['X-Guest-Token' => $token]);

        $dna = $this->getJson('/api/traveller/experience-dna', ['X-Guest-Token' => $token]);

        $dna->assertOk();
        $this->assertSame('Curious Explorer', $dna->json('data.travel_style'));
        $this->assertSame('3 major experiences a day', $dna->json('data.preferred_pace'));
        $this->assertSame(91, $dna->json('data.interests.0.percent'));
        $this->assertTrue($dna->json('data.is_complete'));
    }

    public function test_a_journey_stores_reason_companions_mission_and_anchors(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $destinationId = $this->getJson('/api/destinations?q=rome')->json('data.0.id');

        $journey = $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'anniversary',
            'adults' => 2,
            'children' => 0,
            'familiarity' => 'once',
            'budget_total_minor' => 80000,
            'mission_text' => 'A romantic few days. We want one really memorable dinner and to avoid very crowded tours.',
            'must_do' => ['Pantheon'],
            'avoid' => ['nightclubs'],
        ], ['X-Guest-Token' => $token]);

        $journey->assertCreated();
        $this->assertSame('anniversary', $journey->json('data.reason'));
        $this->assertSame('Anniversary', $journey->json('data.reason_label'));
        $this->assertContains('romantic', array_column($journey->json('data.mission_goals'), 'goal'));
        $this->assertContains('memorable_dining', array_column($journey->json('data.mission_goals'), 'goal'));

        $journeyId = $journey->json('data.id');

        $anchor = $this->postJson("/api/journeys/{$journeyId}/anchors", [
            'type' => 'restaurant',
            'title' => 'Anniversary dinner',
            'starts_at' => '2026-10-10T20:00:00+02:00',
            'ends_at' => '2026-10-10T22:30:00+02:00',
            'buffer_before_minutes' => 30,
        ], ['X-Guest-Token' => $token]);

        $anchor->assertCreated();

        /* The anchor's protected window starts before the booking itself:
           30 minutes of the traveller's own buffer plus the engine's safety margin. */
        $this->assertTrue(
            strtotime($anchor->json('data.protected_from')) < strtotime($anchor->json('data.starts_at')),
        );

        /* Local time is preserved: 20:00 in Rome is 18:00 UTC. */
        $this->assertSame('18:00', date('H:i', strtotime($anchor->json('data.starts_at'))));
    }

    public function test_an_anchor_time_without_an_offset_is_read_in_the_destinations_timezone(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $destinationId = $this->getJson('/api/destinations?q=tokyo')->json('data.0.id');

        $journeyId = $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'business',
        ], ['X-Guest-Token' => $token])->json('data.id');

        /* "09:00" means 09:00 in Tokyo, whatever timezone the phone is in.
           Tokyo is UTC+9, so that is 00:00 UTC. */
        $anchor = $this->postJson("/api/journeys/{$journeyId}/anchors", [
            'type' => 'meeting',
            'title' => 'Client meeting',
            'starts_at' => '2026-10-12T09:00:00',
            'ends_at' => '2026-10-12T11:00:00',
        ], ['X-Guest-Token' => $token]);

        $anchor->assertCreated();
        $this->assertSame('2026-10-12T00:00:00+00:00', $anchor->json('data.starts_at'));
    }

    public function test_a_mission_can_be_added_later_and_is_reinterpreted(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $destinationId = $this->getJson('/api/destinations?q=london')->json('data.0.id');

        $journeyId = $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'family',
            'adults' => 2,
            'children' => 1,
            'child_ages' => [6],
        ], ['X-Guest-Token' => $token])->json('data.id');

        $mission = $this->postJson("/api/journeys/{$journeyId}/mission", [
            'mission_text' => 'I want my child to really experience London.',
        ], ['X-Guest-Token' => $token]);

        $mission->assertOk();
        $this->assertContains('interactive', array_column($mission->json('data.goals'), 'goal'));
        $this->assertStringContainsString('never override', $mission->json('data.note'));
    }

    public function test_a_journey_stores_stay_dates(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $destinationId = $this->getJson('/api/destinations?q=rome')->json('data.0.id');

        $journey = $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'holiday',
            'starts_on' => '2026-10-09',
            'ends_on' => '2026-10-15',
        ], ['X-Guest-Token' => $token]);

        $journey->assertCreated();
        $this->assertSame('2026-10-09', $journey->json('data.starts_on'));
        $this->assertSame('2026-10-15', $journey->json('data.ends_on'));
    }
}
