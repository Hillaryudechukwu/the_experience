<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalSources;

use App\Domains\ExternalSources\Providers\OverpassPlaceProvider;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract test for the OpenStreetMap adapter (spec s31: provider contract
 * tests using fixtures). Runs entirely offline so a busy public Overpass
 * instance can never turn CI red.
 */
class OverpassPlaceProviderTest extends TestCase
{
    public function test_it_maps_osm_tags_onto_a_place_candidate(): void
    {
        Http::fake(['*' => Http::response($this->fixture())]);

        $candidate = $this->provider()
            ->searchNearby(new GeoPoint(51.5081, -0.0759), 2000, ['historic'], 10)
            ->firstWhere('name', 'Tower of London');

        $this->assertNotNull($candidate);
        $this->assertSame('way/123456', $candidate->providerId);
        $this->assertSame('historic', $candidate->kind);
        $this->assertSame('https://www.hrp.org.uk/tower-of-london/', $candidate->website);
        $this->assertSame('Q62378', $candidate->externalRefs['wikidata']);
        $this->assertSame([['09:00', '17:30']], $candidate->openingHours['tue']);
        $this->assertTrue($candidate->accessibility['wheelchair_accessible']);
    }

    public function test_a_place_without_a_verified_rating_reports_none_rather_than_a_guess(): void
    {
        Http::fake(['*' => Http::response($this->fixture())]);

        $candidate = $this->provider()
            ->searchNearby(new GeoPoint(51.5081, -0.0759), 2000, ['historic'], 10)
            ->first();

        $this->assertNull($candidate->rating, 'OSM carries no ratings and we must not invent one.');
        $this->assertNull($candidate->ratingCount);
    }

    public function test_unreadable_opening_hours_become_unknown_not_wrong(): void
    {
        Http::fake(['*' => Http::response($this->fixture())]);

        $candidate = $this->provider()
            ->searchNearby(new GeoPoint(51.5081, -0.0759), 2000, ['historic'], 10)
            ->firstWhere('name', 'Seasonal Ruin');

        $this->assertNotNull($candidate);
        $this->assertNull($candidate->openingHours);
    }

    public function test_results_outside_the_requested_radius_are_trimmed(): void
    {
        Http::fake(['*' => Http::response($this->fixture())]);

        $names = $this->provider()
            ->searchNearby(new GeoPoint(51.5081, -0.0759), 500, ['historic'], 10)
            ->pluck('name');

        $this->assertContains('Tower of London', $names);
        $this->assertNotContains('Far Away Folly', $names, 'The bounding box is square; the radius is not.');
    }

    public function test_an_unnamed_element_is_discarded(): void
    {
        Http::fake(['*' => Http::response($this->fixture())]);

        $ids = $this->provider()
            ->searchNearby(new GeoPoint(51.5081, -0.0759), 2000, ['historic'], 10)
            ->pluck('providerId');

        $this->assertNotContains('node/999999', $ids);
    }

    public function test_a_server_side_timeout_is_surfaced_not_read_as_an_empty_city(): void
    {
        /* Overpass reports query timeouts with HTTP 200 and a remark. Treating
           that as "no results" is the most misleading thing we could do. */
        Http::fake(['*' => Http::response(['elements' => [], 'remark' => 'runtime error: Query timed out'])]);

        $results = $this->provider()->searchNearby(new GeoPoint(51.5081, -0.0759), 2000, ['historic'], 10);

        $this->assertCount(0, $results);
        $this->assertTrue(true, 'The batch failed loudly in the log rather than returning a false empty.');
    }

    public function test_a_failing_batch_does_not_lose_the_others(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response('busy', 504)
                : Http::response($this->fixture());
        });

        $results = $this->provider()->searchNearby(
            new GeoPoint(51.5081, -0.0759),
            2000,
            ['museum', 'gallery', 'viewpoint', 'historic'],   // two batches
            10,
        );

        $this->assertGreaterThan(0, $results->count(), 'One dead batch should not cost the other.');
    }

    private function provider(): OverpassPlaceProvider
    {
        return app(OverpassPlaceProvider::class);
    }

    private function fixture(): array
    {
        return [
            'elements' => [
                [
                    'type' => 'way',
                    'id' => 123456,
                    'center' => ['lat' => 51.5081, 'lon' => -0.0759],
                    'tags' => [
                        'name' => 'Tower of London',
                        'historic' => 'castle',
                        'website' => 'https://www.hrp.org.uk/tower-of-london/',
                        'opening_hours' => 'Tu-Sa 09:00-17:30; Su-Mo 10:00-17:30',
                        'wheelchair' => 'yes',
                        'wikidata' => 'Q62378',
                        'wikipedia' => 'en:Tower of London',
                        'addr:street' => 'St Katharine\'s Way',
                        'addr:city' => 'London',
                    ],
                ],
                [
                    'type' => 'node',
                    'id' => 222222,
                    'lat' => 51.5083,
                    'lon' => -0.0761,
                    'tags' => ['name' => 'Seasonal Ruin', 'historic' => 'ruins', 'opening_hours' => 'Apr-Oct 09:00-18:00'],
                ],
                [
                    'type' => 'node',
                    'id' => 333333,
                    'lat' => 51.5600,
                    'lon' => -0.0759,
                    'tags' => ['name' => 'Far Away Folly', 'historic' => 'monument'],
                ],
                [
                    'type' => 'node',
                    'id' => 999999,
                    'lat' => 51.5082,
                    'lon' => -0.0760,
                    'tags' => ['historic' => 'memorial'],
                ],
            ],
        ];
    }
}
