<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Analytics\Models\BehaviouralEvent;
use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Enums\DestinationImportStage;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use App\Domains\Destinations\Jobs\ImportDestination;
use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Destinations\Services\DestinationCandidateToken;
use App\Domains\Destinations\ValueObjects\DestinationCandidate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DestinationActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([ImportDestination::class]);
    }

    public function test_an_unseeded_city_can_be_activated_without_waiting_for_import(): void
    {
        $response = $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.coverage_status', 'queued')
            ->assertJsonPath('data.stage', 'queued')
            ->assertJsonPath('data.poll_after_seconds', 3);
        $this->assertDatabaseHas('destinations', [
            'name' => 'Reykjavík',
            'country_code' => 'IS',
            'coverage_status' => 'queued',
            'discovery_external_id' => 'relation/12345',
        ]);
        $this->assertDatabaseCount('destination_imports', 1);
        Queue::assertPushed(ImportDestination::class, 1);
        $this->assertSame(1, BehaviouralEvent::where('type', 'destination_activation_requested')->count());
    }

    public function test_the_requesting_guest_can_resume_import_progress(): void
    {
        $activation = $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ])->assertAccepted();
        $guestToken = $activation->headers->get('X-Guest-Token');

        $this->getJson('/api/destination-imports/'.$activation->json('data.import_id'), [
            'X-Guest-Token' => $guestToken,
        ])->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.stage', 'queued')
            ->assertJsonPath('data.poll_after_seconds', 3)
            ->assertJsonMissingPath('data.error_context');
    }

    public function test_running_progress_exposes_truthful_counters_but_not_private_diagnostics(): void
    {
        $activation = $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ])->assertAccepted();
        $import = DestinationImport::findOrFail($activation->json('data.import_id'));
        $import->update([
            'status' => DestinationImportStatus::Running,
            'stage' => DestinationImportStage::ResolvingPlaces,
            'records_seen' => 14,
            'places_created' => 4,
            'places_matched' => 7,
            'error_context' => ['provider_body' => 'private'],
            'started_at' => now(),
        ]);

        $this->getJson('/api/destination-imports/'.$import->id, [
            'X-Guest-Token' => $activation->headers->get('X-Guest-Token'),
        ])->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.stage', 'resolving_places')
            ->assertJsonPath('data.records_seen', 14)
            ->assertJsonPath('data.places_created', 4)
            ->assertJsonPath('data.places_matched', 7)
            ->assertJsonMissingPath('data.error_context');
    }

    public function test_another_guest_cannot_inspect_an_active_import(): void
    {
        $activation = $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ])->assertAccepted();

        $this->getJson('/api/destination-imports/'.$activation->json('data.import_id'))->assertNotFound();
    }

    public function test_owner_can_retry_a_retryable_failed_import(): void
    {
        $activation = $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ])->assertAccepted();
        $import = DestinationImport::findOrFail($activation->json('data.import_id'));
        $import->update([
            'status' => DestinationImportStatus::Failed,
            'stage' => DestinationImportStage::Failed,
            'retryable' => true,
            'finished_at' => now(),
        ]);
        $import->destination->update(['coverage_status' => DestinationCoverageStatus::Failed]);

        $response = $this->postJson('/api/destination-imports/'.$import->id.'/retry', [], [
            'X-Guest-Token' => $activation->headers->get('X-Guest-Token'),
        ])->assertAccepted();

        $this->assertNotSame($import->id, $response->json('data.import_id'));
        $response->assertJsonPath('data.status', 'queued');
        $this->assertDatabaseCount('destination_imports', 2);
        Queue::assertPushed(ImportDestination::class, 2);
    }

    public function test_another_guest_cannot_retry_a_failed_import(): void
    {
        $activation = $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ])->assertAccepted();
        DestinationImport::findOrFail($activation->json('data.import_id'))->update([
            'status' => DestinationImportStatus::Failed,
            'stage' => DestinationImportStage::Failed,
            'retryable' => true,
            'finished_at' => now(),
        ]);

        $this->postJson('/api/destination-imports/'.$activation->json('data.import_id').'/retry')
            ->assertNotFound();

        $this->assertDatabaseCount('destination_imports', 1);
    }

    public function test_repeated_activation_reuses_the_same_destination_and_import(): void
    {
        $token = $this->candidateToken();
        $first = $this->postJson('/api/destinations/activate', ['candidate_token' => $token])->assertAccepted();

        $second = $this->postJson('/api/destinations/activate', ['candidate_token' => $token])->assertAccepted();

        $this->assertSame($first->json('data.destination_id'), $second->json('data.destination_id'));
        $this->assertSame($first->json('data.import_id'), $second->json('data.import_id'));
        $this->assertDatabaseCount('destination_imports', 1);
    }

    public function test_database_rejects_two_active_imports_for_one_destination(): void
    {
        $activation = $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ])->assertAccepted();

        $this->expectException(QueryException::class);

        DestinationImport::create([
            'destination_id' => $activation->json('data.destination_id'),
            'status' => DestinationImportStatus::Queued,
            'stage' => DestinationImportStage::Queued,
            'provider_key' => 'osm',
        ]);
    }

    public function test_an_already_ready_destination_does_not_start_an_import(): void
    {
        $this->seed(DatabaseSeeder::class);
        $paris = Destination::where('slug', 'paris')->firstOrFail();
        $token = app(DestinationCandidateToken::class)->issue(new DestinationCandidate(
            provider: 'nominatim',
            externalId: 'relation/7444',
            name: 'Paris',
            region: 'Île-de-France',
            country: 'France',
            countryCode: 'FR',
            lat: 48.8566,
            lng: 2.3522,
            kind: 'city',
        ));

        $response = $this->postJson('/api/destinations/activate', ['candidate_token' => $token]);

        $response->assertOk()
            ->assertJsonPath('data.destination_id', $paris->id)
            ->assertJsonPath('data.coverage_status', DestinationCoverageStatus::Ready->value)
            ->assertJsonPath('data.import_id', null);
        $this->assertDatabaseCount('destination_imports', 0);
    }

    public function test_a_modified_candidate_token_is_rejected(): void
    {
        $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken().'changed',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('candidate_token');

        $this->assertDatabaseCount('destinations', 0);
        $this->assertDatabaseCount('destination_imports', 0);
    }

    public function test_daily_actor_limit_rejects_another_new_activation_with_retry_guidance(): void
    {
        config()->set('experience.destination_activation.daily_actor_limit', 1);

        $first = $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ])->assertAccepted();

        $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken('relation/67890', 'Oslo', 'NO'),
        ], ['X-Guest-Token' => $first->headers->get('X-Guest-Token')])
            ->assertTooManyRequests()
            ->assertJsonStructure(['message', 'retry_after_seconds'])
            ->assertHeader('Retry-After');

        $this->assertDatabaseCount('destination_imports', 1);
    }

    public function test_rollout_can_be_closed_except_for_an_allowed_city(): void
    {
        config()->set('experience.destination_activation.rollout_percentage', 0);
        config()->set('experience.destination_activation.allowed_cities', ['Reykjavík']);

        $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ])->assertAccepted();

        $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken('relation/67890', 'Oslo', 'NO'),
        ])->assertForbidden()
            ->assertJsonPath('code', 'destination_activation_not_in_rollout');
    }

    public function test_an_allowed_candidate_bypasses_a_closed_rollout(): void
    {
        config()->set('experience.destination_activation.rollout_percentage', 0);
        config()->set('experience.destination_activation.allowed_cities', []);
        config()->set('experience.destination_activation.allowed_candidates', [
            'nominatim:relation/67890',
        ]);

        $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken('relation/67890', 'Oslo', 'NO'),
        ])->assertAccepted();

        $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ])->assertForbidden()
            ->assertJsonPath('code', 'destination_activation_not_in_rollout');
    }

    public function test_the_kill_switch_returns_service_unavailable(): void
    {
        config()->set('experience.destination_activation.enabled', false);

        $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ])->assertStatus(503);
    }

    public function test_global_daily_limit_applies_across_different_guests(): void
    {
        config()->set('experience.destination_activation.daily_global_limit', 1);
        config()->set('experience.destination_activation.daily_actor_limit', 10);
        config()->set('experience.destination_activation.daily_ip_limit', 10);

        $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken(),
        ])->assertAccepted();

        $this->postJson('/api/destinations/activate', [
            'candidate_token' => $this->candidateToken('relation/67890', 'Oslo', 'NO'),
        ], ['X-Forwarded-For' => '203.0.113.50'])
            ->assertTooManyRequests()
            ->assertJsonStructure(['message', 'retry_after_seconds']);

        $this->assertDatabaseCount('destination_imports', 1);
    }

    private function candidateToken(
        string $externalId = 'relation/12345',
        string $name = 'Reykjavík',
        string $countryCode = 'IS',
    ): string {
        return app(DestinationCandidateToken::class)->issue(new DestinationCandidate(
            provider: 'nominatim',
            externalId: $externalId,
            name: $name,
            region: 'Capital Region',
            country: 'Iceland',
            countryCode: $countryCode,
            lat: 64.1466,
            lng: -21.9426,
            kind: 'city',
        ));
    }
}
