<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Jobs\EnrichDestinationContent as EnrichDestinationContentJob;
use App\Domains\Destinations\Jobs\ImportDestination;
use App\Domains\Destinations\Models\DestinationCandidateDemand;
use App\Domains\Destinations\Services\DestinationCoverageStateMachine;
use App\Domains\Destinations\Services\DestinationQualityReporter;
use App\Domains\Destinations\Services\DestinationReadinessEvaluator;
use App\Domains\ExternalSources\Contracts\PlaceEnricher;
use App\Domains\ExternalSources\DTO\PlaceEnrichment;
use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\Places\Actions\EnrichDestinationContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

class DestinationOptimisationTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_predictive_warming_queues_only_destinations_over_the_demand_threshold(): void
    {
        Queue::fake([ImportDestination::class]);
        config()->set('experience.destination_activation.prewarm.daily_limit', 1);
        $popular = $this->demand('Reykjavík', 'relation/1', 12);
        $this->demand('Tórshavn', 'relation/2', 2);

        $this->artisan('destinations:prewarm --force --minimum-demand=10')->assertSuccessful();

        $this->assertDatabaseHas('destinations', ['name' => 'Reykjavík', 'coverage_status' => 'queued']);
        $this->assertDatabaseMissing('destinations', ['name' => 'Tórshavn']);
        $this->assertNotNull($popular->fresh()->prewarmed_at);
        Queue::assertPushed(ImportDestination::class, 1);
    }

    public function test_quality_report_identifies_an_enrichment_backlog(): void
    {
        $destination = $this->destination(['coverage_status' => DestinationCoverageStatus::Limited]);
        $this->experience($destination, [
            'status' => 'needs_content',
            'summary' => '',
            'why_it_matters' => '',
            'image_url' => null,
            'content_source_url' => null,
        ]);

        $row = app(DestinationQualityReporter::class)->report()->firstWhere('destination.id', $destination->id);

        $this->assertTrue($row['needs_curation']);
        $this->assertContains('content_enrichment_backlog', $row['issues']);
        $this->assertLessThan(50, $row['quality_score']);
    }

    public function test_content_can_be_retried_without_reingesting_the_canonical_place(): void
    {
        $destination = $this->destination(['coverage_status' => DestinationCoverageStatus::Limited]);
        $experience = $this->experience($destination, [
            'status' => 'needs_content',
            'summary' => '',
            'why_it_matters' => '',
            'image_url' => null,
            'content_source_url' => null,
        ]);
        ExternalEntity::create([
            'provider' => 'osm',
            'provider_id' => 'way/1',
            'entity_type' => 'place',
            'entity_id' => $experience->place_id,
            'metadata' => [
                'provider' => 'osm',
                'provider_id' => 'way/1',
                'name' => $experience->place->name,
                'lat' => $experience->place->lat,
                'lng' => $experience->place->lng,
                'external_refs' => ['wikidata' => 'Q1'],
            ],
        ]);
        $enricher = Mockery::mock(PlaceEnricher::class);
        $enricher->shouldReceive('enrich')->once()->andReturn(new PlaceEnrichment(
            summary: 'A grounded description from a source.',
            sourceName: 'Wikipedia',
            sourceUrl: 'https://example.test/source',
        ));
        $this->app->instance(PlaceEnricher::class, $enricher);

        $result = app(EnrichDestinationContent::class)->run($destination);

        $this->assertSame(1, $result['enriched']);
        $this->assertSame('published', $experience->fresh()->status);
        $this->assertSame('https://example.test/source', $experience->fresh()->content_source_url);
        $this->assertDatabaseCount('places', 1);
    }

    public function test_enrichment_retry_can_recover_a_failed_destination(): void
    {
        $destination = $this->destination(['coverage_status' => DestinationCoverageStatus::Failed]);
        $experience = $this->experience($destination, [
            'status' => 'needs_content',
            'summary' => '',
            'why_it_matters' => '',
            'content_source_url' => null,
        ]);
        ExternalEntity::create([
            'provider' => 'osm',
            'provider_id' => 'way/recovery',
            'entity_type' => 'place',
            'entity_id' => $experience->place_id,
            'metadata' => [
                'provider' => 'osm',
                'provider_id' => 'way/recovery',
                'name' => $experience->place->name,
                'lat' => $experience->place->lat,
                'lng' => $experience->place->lng,
                'external_refs' => ['wikidata' => 'Q2'],
            ],
        ]);
        $enricher = Mockery::mock(PlaceEnricher::class);
        $enricher->shouldReceive('enrich')->once()->andReturn(new PlaceEnrichment(
            summary: 'A sourced description that makes this experience publishable.',
            sourceName: 'Wikipedia',
            sourceUrl: 'https://example.test/recovery',
        ));
        $this->app->instance(PlaceEnricher::class, $enricher);

        (new EnrichDestinationContentJob($destination->id))->handle(
            app(EnrichDestinationContent::class),
            app(DestinationReadinessEvaluator::class),
            app(DestinationCoverageStateMachine::class),
        );

        $this->assertSame(DestinationCoverageStatus::Limited, $destination->fresh()->coverage_status);
        $this->assertSame('published', $experience->fresh()->status);
    }

    private function demand(string $name, string $externalId, int $count): DestinationCandidateDemand
    {
        return DestinationCandidateDemand::create([
            'provider' => 'nominatim',
            'external_id' => $externalId,
            'name' => $name,
            'country' => 'Iceland',
            'country_code' => 'IS',
            'lat' => 64.1466,
            'lng' => -21.9426,
            'kind' => 'city',
            'search_count' => $count,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
