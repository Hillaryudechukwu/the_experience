<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Enums\DestinationImportStage;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use App\Domains\Destinations\Jobs\ImportDestination;
use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\DestinationImport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DestinationImportAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_filter_destination_imports_without_exposing_private_diagnostics(): void
    {
        $this->seed(DatabaseSeeder::class);
        $destination = Destination::where('slug', 'paris')->firstOrFail();
        DestinationImport::create([
            'destination_id' => $destination->id,
            'status' => DestinationImportStatus::Failed,
            'stage' => DestinationImportStage::Failed,
            'provider_key' => 'google_places',
            'error_code' => 'provider_rate_limited',
            'error_context' => ['provider_body' => 'private'],
        ]);
        DestinationImport::create([
            'destination_id' => $destination->id,
            'status' => DestinationImportStatus::Succeeded,
            'stage' => DestinationImportStage::Ready,
            'provider_key' => 'osm',
        ]);
        $admin = User::create([
            'name' => 'Ops',
            'email' => 'imports@example.com',
            'password' => bcrypt('secret-secret'),
        ]);
        $token = $admin->createToken('ops', ['admin'])->plainTextToken;

        $response = $this->getJson('/api/admin/destination-imports?status=failed&provider=google_places', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.error_code', 'provider_rate_limited')
            ->assertJsonMissingPath('data.0.error_context')
            ->assertJsonPath('data.0.destination.name', 'Paris');
    }

    public function test_destination_import_operations_are_closed_to_travellers(): void
    {
        $this->getJson('/api/admin/destination-imports')->assertUnauthorized();
    }

    public function test_an_admin_can_retry_a_failed_import_without_creating_parallel_work(): void
    {
        Queue::fake([ImportDestination::class]);
        $this->seed(DatabaseSeeder::class);
        $destination = Destination::where('slug', 'paris')->firstOrFail();
        $destination->update(['coverage_status' => DestinationCoverageStatus::Failed]);
        $failed = DestinationImport::create([
            'destination_id' => $destination->id,
            'status' => DestinationImportStatus::Failed,
            'stage' => DestinationImportStage::Failed,
            'provider_key' => 'google_places',
            'finished_at' => now(),
        ]);
        $admin = User::create([
            'name' => 'Ops',
            'email' => 'retry@example.com',
            'password' => bcrypt('secret-secret'),
        ]);
        $token = $admin->createToken('ops', ['admin'])->plainTextToken;

        $response = $this->postJson("/api/admin/destination-imports/{$failed->id}/retry", [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertAccepted()->assertJsonPath('data.status', 'queued');
        $this->assertSame(DestinationCoverageStatus::Queued, $destination->fresh()->coverage_status);
        $this->assertDatabaseCount('destination_imports', 2);
        Queue::assertPushed(ImportDestination::class, 1);
    }
}
