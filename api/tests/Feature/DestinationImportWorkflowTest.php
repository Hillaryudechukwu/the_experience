<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Analytics\Models\BehaviouralEvent;
use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Enums\DestinationImportStage;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use App\Domains\Destinations\Jobs\ImportDestination;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Destinations\Services\DestinationCoverageStateMachine;
use App\Domains\Destinations\Services\DestinationReadinessEvaluator;
use App\Domains\ExternalSources\Models\ProviderSyncRun;
use App\Domains\Places\Actions\IngestPlaces;
use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

class DestinationImportWorkflowTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_a_successful_import_records_counts_and_marks_the_destination_ready(): void
    {
        $destination = $this->destination(['coverage_status' => DestinationCoverageStatus::Queued]);
        $import = DestinationImport::create([
            'destination_id' => $destination->id,
            'status' => DestinationImportStatus::Queued,
            'stage' => DestinationImportStage::Queued,
            'provider_key' => 'osm',
        ]);
        $run = ProviderSyncRun::create([
            'provider' => 'osm',
            'kind' => 'places:test-city',
            'status' => 'completed',
            'records_seen' => 12,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $ingest = Mockery::mock(IngestPlaces::class);
        $ingest->shouldReceive('run')->once()->andReturn([
            'run' => $run,
            'created' => 8,
            'matched' => 3,
            'needs_review' => 1,
            'enriched' => 7,
            'failed' => 0,
        ]);
        $readiness = Mockery::mock(DestinationReadinessEvaluator::class);
        $readiness->shouldReceive('evaluate')->once()->andReturn([
            'status' => DestinationCoverageStatus::Ready,
            'published' => 7,
            'pending_content' => 4,
            'distinct_kinds' => 4,
        ]);

        (new ImportDestination($import->id))->handle(
            $ingest,
            app(DestinationCoverageStateMachine::class),
            $readiness,
            app(RecordBehaviouralEvent::class),
        );

        $import->refresh();
        $this->assertSame(DestinationImportStatus::Succeeded, $import->status);
        $this->assertSame(DestinationImportStage::Ready, $import->stage);
        $this->assertSame(12, $import->records_seen);
        $this->assertSame(8, $import->places_created);
        $this->assertSame(7, $import->experiences_published);
        $this->assertSame(DestinationCoverageStatus::Ready, $destination->fresh()->coverage_status);
        $this->assertNotNull($destination->fresh()->last_imported_at);
        $this->assertDatabaseHas('behavioural_events', [
            'type' => 'destination_import_completed',
            'subject_id' => $destination->id,
        ]);
    }

    public function test_final_job_failure_records_a_sanitized_failure_state(): void
    {
        $destination = $this->destination(['coverage_status' => DestinationCoverageStatus::Importing]);
        $import = DestinationImport::create([
            'destination_id' => $destination->id,
            'status' => DestinationImportStatus::Running,
            'stage' => DestinationImportStage::DiscoveringPlaces,
            'provider_key' => 'osm',
        ]);

        (new ImportDestination($import->id))->failed(new \RuntimeException('Provider unavailable'));

        $this->assertSame(DestinationImportStatus::Failed, $import->fresh()->status);
        $this->assertSame(DestinationImportStage::Failed, $import->fresh()->stage);
        $this->assertSame('import_failed', $import->fresh()->error_code);
        $this->assertSame(DestinationCoverageStatus::Failed, $destination->fresh()->coverage_status);
        $this->assertNotNull($import->fresh()->finished_at);
        $this->assertSame(1, BehaviouralEvent::where('type', 'destination_import_failed')->count());
    }

    public function test_failed_import_truncates_oversized_error_messages(): void
    {
        $destination = $this->destination(['coverage_status' => DestinationCoverageStatus::Importing]);
        $import = DestinationImport::create([
            'destination_id' => $destination->id,
            'status' => DestinationImportStatus::Running,
            'stage' => DestinationImportStage::DiscoveringPlaces,
            'provider_key' => 'osm',
        ]);

        (new ImportDestination($import->id))->failed(new \RuntimeException(str_repeat('x', 5000)));

        $context = $import->fresh()->error_context;
        $this->assertIsArray($context);
        $this->assertSame(500, mb_strlen($context['message']));
    }

    public function test_stalled_imports_are_failed_by_the_recovery_command(): void
    {
        $destination = $this->destination(['coverage_status' => DestinationCoverageStatus::Importing]);
        $import = DestinationImport::create([
            'destination_id' => $destination->id,
            'status' => DestinationImportStatus::Running,
            'stage' => DestinationImportStage::DiscoveringPlaces,
            'provider_key' => 'osm',
            'started_at' => now()->subMinutes(16),
            'last_heartbeat_at' => now()->subMinutes(16),
        ]);

        $this->artisan('destinations:recover-stale-imports')->assertSuccessful();

        $this->assertSame('worker_stalled', $import->fresh()->error_code);
        $this->assertSame(DestinationImportStatus::Failed, $import->fresh()->status);
        $this->assertSame(DestinationCoverageStatus::Failed, $destination->fresh()->coverage_status);
        $this->assertTrue($import->fresh()->retryable);
        $this->assertNull($import->fresh()->active_destination_id);
    }

    public function test_queued_imports_that_never_start_are_failed_by_recovery(): void
    {
        $destination = $this->destination(['coverage_status' => DestinationCoverageStatus::Queued]);
        $import = DestinationImport::create([
            'destination_id' => $destination->id,
            'status' => DestinationImportStatus::Queued,
            'stage' => DestinationImportStage::Queued,
            'provider_key' => 'osm',
        ]);
        $import->forceFill([
            'created_at' => now()->subMinutes(16),
            'updated_at' => now()->subMinutes(16),
        ])->save();

        $this->artisan('destinations:recover-stale-imports')->assertSuccessful();

        $this->assertSame('queue_stalled', $import->fresh()->error_code);
        $this->assertSame(DestinationImportStatus::Failed, $import->fresh()->status);
        $this->assertSame(DestinationCoverageStatus::Failed, $destination->fresh()->coverage_status);
        $this->assertTrue($import->fresh()->retryable);
    }

    public function test_queue_heartbeat_is_visible_to_health_checks(): void
    {
        Cache::forget('health:queue-worker-heartbeat');

        (new RecordQueueHeartbeat)->handle();

        $this->getJson('/api/health')->assertOk()
            ->assertJsonPath('destination_pipeline.queue_worker_alive', true)
            ->assertJsonStructure(['destination_pipeline' => [
                'place_provider',
                'place_provider_configured',
                'content_enricher',
                'geocoder_configured',
                'queue_lag_seconds',
                'failed_jobs',
            ]]);
    }
}
