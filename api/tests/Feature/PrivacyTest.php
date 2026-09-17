<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Journeys\Models\JourneyContextSnapshot;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance 65: location use is optional and the traveller can control or
 * delete stored journey/location information.
 */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
    }

    public function test_recommendations_work_without_any_location(): void
    {
        $response = $this->postJson('/api/discovery/now', ['destination' => 'london'], ['X-Guest-Token' => $this->token]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
        $this->assertSame('city', $response->json('context.location_precision'));
    }

    public function test_a_traveller_can_ask_for_approximate_location_handling(): void
    {
        $response = $this->postJson('/api/discovery/now', [
            'destination' => 'london',
            'lat' => 51.5074,
            'lng' => -0.1278,
            'location_precision' => 'approximate',
        ], ['X-Guest-Token' => $this->token]);

        $response->assertOk();
        $this->assertSame('approximate', $response->json('context.location_precision'));
    }

    public function test_stored_context_rounds_coordinates_rather_than_keeping_a_trail(): void
    {
        $destinationId = $this->getJson('/api/destinations?q=london')->json('data.0.id');

        $journeyId = $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'holiday',
        ], ['X-Guest-Token' => $this->token])->json('data.id');

        $this->postJson('/api/discovery/now', [
            'journey_id' => $journeyId,
            'lat' => 51.50734567,
            'lng' => -0.12789123,
        ], ['X-Guest-Token' => $this->token])->assertOk();

        $snapshot = JourneyContextSnapshot::firstOrFail();

        $this->assertSame(51.507, (float) $snapshot->lat);
        $this->assertSame(-0.128, (float) $snapshot->lng);
    }

    public function test_a_traveller_can_delete_their_stored_location_context(): void
    {
        $destinationId = $this->getJson('/api/destinations?q=london')->json('data.0.id');

        $journeyId = $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'holiday',
        ], ['X-Guest-Token' => $this->token])->json('data.id');

        $this->postJson('/api/discovery/now', ['journey_id' => $journeyId, 'lat' => 51.5, 'lng' => -0.12], ['X-Guest-Token' => $this->token]);

        $this->assertGreaterThan(0, JourneyContextSnapshot::count());

        $response = $this->deleteJson('/api/privacy/location-history', [], ['X-Guest-Token' => $this->token]);

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('deleted_snapshots'));
        $this->assertSame(0, JourneyContextSnapshot::count());
    }

    public function test_a_traveller_can_export_everything_held_about_them(): void
    {
        $destinationId = $this->getJson('/api/destinations?q=london')->json('data.0.id');
        $this->postJson('/api/journeys', ['destination_id' => $destinationId, 'reason' => 'holiday'], ['X-Guest-Token' => $this->token]);

        $export = $this->getJson('/api/privacy/export', ['X-Guest-Token' => $this->token]);

        $export->assertOk();
        foreach (['profile', 'journeys', 'saved_experiences', 'completed_experiences', 'behavioural_events', 'context_snapshots'] as $section) {
            $this->assertArrayHasKey($section, $export->json('data'));
        }
        $this->assertCount(1, $export->json('data.journeys'));
    }

    public function test_a_traveller_can_delete_their_data(): void
    {
        $destinationId = $this->getJson('/api/destinations?q=london')->json('data.0.id');
        $this->postJson('/api/journeys', ['destination_id' => $destinationId, 'reason' => 'holiday'], ['X-Guest-Token' => $this->token]);

        $this->deleteJson('/api/privacy/data', [], ['X-Guest-Token' => $this->token])->assertOk();

        $this->assertDatabaseCount('journeys', 0);
        $this->assertDatabaseCount('behavioural_events', 0);
    }
}
