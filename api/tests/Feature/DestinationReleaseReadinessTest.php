<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Enums\DestinationImportStage;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Destinations\Services\DestinationReadinessEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

class DestinationReleaseReadinessTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_discovery_returns_structured_progress_for_an_importing_destination(): void
    {
        $destination = $this->destination(['coverage_status' => DestinationCoverageStatus::Importing]);
        $import = DestinationImport::create([
            'destination_id' => $destination->id,
            'status' => DestinationImportStatus::Running,
            'stage' => DestinationImportStage::DiscoveringPlaces,
            'provider_key' => 'osm',
        ]);

        $this->postJson('/api/discovery/now', ['destination_id' => $destination->id])
            ->assertConflict()
            ->assertHeader('Retry-After', '3')
            ->assertJsonPath('code', 'destination_preparing')
            ->assertJsonPath('import_id', $import->id)
            ->assertJsonPath('coverage_status', 'importing');
    }

    public function test_limited_destination_is_allowed_into_discovery(): void
    {
        $destination = $this->destination(['coverage_status' => DestinationCoverageStatus::Limited]);
        $this->experience($destination, ['status' => 'published']);

        $this->postJson('/api/discovery/now', ['destination_id' => $destination->id])
            ->assertOk()
            ->assertJsonStructure(['data', 'context', 'recommendation_set_id']);
    }

    public function test_readiness_evaluator_distinguishes_ready_limited_and_failed(): void
    {
        config()->set('experience.destination_activation.ready_minimum_published', 3);
        config()->set('experience.destination_activation.ready_minimum_kinds', 2);
        $ready = $this->destination();
        foreach (['museum', 'park', 'market'] as $kind) {
            $experience = $this->experience($ready, ['status' => 'published']);
            $experience->place->update(['kind' => $kind, 'data_source' => 'osm']);
            $experience->update(['data_source' => 'osm']);
        }
        $limited = $this->destination();
        $limitedExperience = $this->experience($limited, ['status' => 'published']);
        $limitedExperience->place->update(['kind' => 'museum', 'data_source' => 'osm']);
        $limitedExperience->update(['data_source' => 'osm']);
        $failed = $this->destination();
        $this->experience($failed, ['status' => 'needs_content', 'why_it_matters' => '']);
        $evaluator = app(DestinationReadinessEvaluator::class);

        $this->assertSame(DestinationCoverageStatus::Ready, $evaluator->evaluate($ready)['status']);
        $this->assertSame(DestinationCoverageStatus::Limited, $evaluator->evaluate($limited)['status']);
        $this->assertSame(DestinationCoverageStatus::Failed, $evaluator->evaluate($failed)['status']);
    }
}
