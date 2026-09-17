<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Analytics\Models\BehaviouralEvent;
use App\Domains\Experiences\Models\Experience;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance 63: analytics record recommendation impressions, saves, bookings
 * and completions.
 */
class AnalyticsAndPassportTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
    }

    public function test_every_recommendation_shown_is_recorded_as_an_impression(): void
    {
        $response = $this->postJson('/api/discovery/now', ['destination' => 'london'], ['X-Guest-Token' => $this->token]);

        $shown = count($response->json('data'));

        $this->assertSame($shown, BehaviouralEvent::where('type', 'impression')->count());
        $this->assertSame(
            $response->json('recommendation_set_id'),
            BehaviouralEvent::where('type', 'impression')->first()->recommendation_set_id,
        );
    }

    public function test_the_reasons_behind_a_ranking_are_stored_for_later_inspection(): void
    {
        $setId = $this->postJson('/api/discovery/now', ['destination' => 'london'], ['X-Guest-Token' => $this->token])
            ->json('recommendation_set_id');

        $this->assertDatabaseHas('recommendation_sets', ['id' => $setId, 'surface' => 'now']);
        $this->assertDatabaseHas('recommendations', ['recommendation_set_id' => $setId, 'rank' => 1]);
        $this->assertGreaterThan(0, \App\Domains\Recommendations\Models\RecommendationReason::count());
    }

    public function test_saves_and_completions_are_recorded_and_build_the_passport(): void
    {
        $experience = Experience::where('slug', 'london-borough-market')->firstOrFail();

        $this->postJson("/api/experiences/{$experience->id}/save", [], ['X-Guest-Token' => $this->token])->assertCreated();
        $this->postJson("/api/experiences/{$experience->id}/complete", [], ['X-Guest-Token' => $this->token])->assertCreated();

        $this->assertDatabaseHas('behavioural_events', ['type' => 'save']);
        $this->assertDatabaseHas('behavioural_events', ['type' => 'complete']);

        $passport = $this->getJson('/api/passport', ['X-Guest-Token' => $this->token]);

        $passport->assertOk();
        $this->assertSame(1, $passport->json('data.totals.experiences'));
        $this->assertSame(1, $passport->json('data.totals.cities'));
        $this->assertSame('London', $passport->json('data.cities.0.name'));
    }

    public function test_a_journal_entry_is_private_unless_the_traveller_chooses_otherwise(): void
    {
        $experience = Experience::where('slug', 'london-borough-market')->firstOrFail();
        $this->postJson("/api/experiences/{$experience->id}/complete", [], ['X-Guest-Token' => $this->token]);

        $response = $this->postJson("/api/passport/journal/{$experience->id}", [
            'rating' => 5,
            'best_part' => 'The cheese stall.',
            'private_note' => 'Do not tell anyone about this.',
        ], ['X-Guest-Token' => $this->token]);

        $response->assertCreated();
        $this->assertFalse($response->json('data.is_public'));
        $this->assertTrue($response->json('data.has_private_note'));
        $this->assertStringNotContainsString('Do not tell anyone', $response->getContent());
    }

    public function test_the_client_can_post_a_batch_of_events(): void
    {
        $experience = Experience::first();

        $this->postJson('/api/events', [
            'events' => [
                ['type' => 'view', 'subject_type' => 'experience', 'subject_id' => $experience->id, 'surface' => 'map'],
                ['type' => 'navigate', 'subject_type' => 'experience', 'subject_id' => $experience->id],
                ['type' => 'reject_recommendation', 'properties' => ['reason' => 'too far']],
            ],
        ], ['X-Guest-Token' => $this->token])->assertStatus(202);

        $this->assertSame(3, BehaviouralEvent::whereIn('type', ['view', 'navigate', 'reject_recommendation'])->count());
    }

    public function test_an_unknown_event_type_is_refused(): void
    {
        $this->postJson('/api/events', [
            'events' => [['type' => 'definitely_not_a_real_event']],
        ], ['X-Guest-Token' => $this->token])->assertStatus(422);
    }
}
