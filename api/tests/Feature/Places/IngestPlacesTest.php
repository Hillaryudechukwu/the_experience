<?php

declare(strict_types=1);

namespace Tests\Feature\Places;

use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\ExternalSources\Models\ProviderSyncFailure;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Places\Models\Place;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/** End-to-end ingestion, with every outbound call faked. */
class IngestPlacesTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);
    }

    public function test_it_creates_canonical_places_and_draft_experiences(): void
    {
        Http::fake($this->responses());

        $destination = $this->destination();

        $result = $this->artisanIngest($destination);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['failed']);

        $place = Place::where('name', 'Tower of London')->firstOrFail();
        $this->assertSame('osm', $place->data_source);
        $this->assertSame('© OpenStreetMap contributors (ODbL)', $place->attribution);
        $this->assertNotNull($place->opening_hours);
        $this->assertSame('osm', $place->opening_hours_source);

        $experience = Experience::where('place_id', $place->id)->firstOrFail();
        $this->assertStringContainsString('historic citadel', $experience->why_it_matters);
        $this->assertSame('Wikipedia', $experience->content_source_name);
        $this->assertSame('CC BY-SA 3.0', $experience->image_attribution['licence']);
        $this->assertSame('published', $experience->status);
    }

    public function test_a_place_with_no_description_is_withheld_from_discovery(): void
    {
        Http::fake($this->responses());

        $destination = $this->destination();
        $this->artisanIngest($destination);

        $bench = Experience::whereHas('place', fn ($q) => $q->where('name', 'Unremarkable Bench'))->firstOrFail();

        $this->assertSame('needs_content', $bench->status);

        /* And therefore never reaches a traveller. */
        $published = Experience::where('destination_id', $destination->id)->where('status', 'published')->pluck('title');
        $this->assertNotContains('Unremarkable Bench', $published);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        Http::fake($this->responses());

        $destination = $this->destination();

        $this->artisanIngest($destination);
        $second = $this->artisanIngest($destination);

        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['matched']);
        $this->assertSame(2, Place::count());
        $this->assertSame(2, Experience::count());
    }

    public function test_provider_facts_are_refreshed_but_editorial_is_never_overwritten(): void
    {
        Http::fake($this->responses());

        $destination = $this->destination();

        /* Something a person wrote, on a place the sync is about to match. */
        $existing = $this->experience($destination, [
            'slug' => 'tower-hand-written',
            'title' => 'Tower of London',
            'lat' => 51.5081,
            'lng' => -0.0759,
            'why_it_matters' => 'A thousand years of English power politics happened on this one site.',
        ]);
        $existing->place->update(['website' => null, 'opening_hours' => null]);

        $this->artisanIngest($destination);

        $place = $existing->place->fresh();
        $experience = $existing->fresh();

        $this->assertSame('https://www.hrp.org.uk/tower-of-london/', $place->website, 'Provider facts win.');
        $this->assertNotNull($place->opening_hours);
        $this->assertStringContainsString(
            'English power politics',
            $experience->why_it_matters,
            'A Wikipedia extract must not replace something a person wrote.',
        );
        $this->assertNotNull($experience->image_url, 'But a missing image is still filled in.');
    }

    public function test_external_ids_are_mapped_without_becoming_primary_keys(): void
    {
        Http::fake($this->responses());

        $destination = $this->destination();
        $this->artisanIngest($destination);

        $place = Place::where('name', 'Tower of London')->firstOrFail();

        $this->assertDatabaseHas('external_entities', [
            'provider' => 'osm',
            'provider_id' => 'way/123456',
            'entity_type' => 'place',
            'entity_id' => $place->id,
        ]);

        $this->assertDatabaseHas('external_entities', [
            'provider' => 'wikidata',
            'provider_id' => 'Q62378',
            'entity_id' => $place->id,
        ]);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $place->id, 'Our own ids stay UUIDs.');
    }

    public function test_one_bad_record_does_not_abort_the_run(): void
    {
        $destination = $this->destination();

        /* A record whose name exceeds the column width fails on write. */
        /* Same key, so this replaces the default Overpass stub. */
        Http::fake(array_merge($this->responses(), [
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    $this->towerElement(),
                    [
                        'type' => 'node',
                        'id' => 777,
                        'lat' => 51.5082,
                        'lon' => -0.0758,
                        'tags' => ['name' => str_repeat('x', 400), 'historic' => 'memorial'],
                    ],
                ],
            ]),
        ]));

        $result = $this->artisanIngest($destination);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseCount('provider_sync_failures', 1);
        $this->assertSame('map', ProviderSyncFailure::first()->stage);
        $this->assertSame('completed_with_failures', $result['run']->status);
    }

    private function artisanIngest($destination): array
    {
        /* Explicit: this exercises the OpenStreetMap adapter, whatever the
           environment happens to have configured as the default. */
        $action = new \App\Domains\Places\Actions\IngestPlaces(
            app(\App\Domains\ExternalSources\Providers\OverpassPlaceProvider::class),
            app(\App\Domains\Places\Services\PlaceResolver::class),
            app(\App\Domains\Places\Services\ExperienceDraftFactory::class),
            app(\App\Domains\ExternalSources\Contracts\PlaceEnricher::class),
        );

        /* Wide enough to reach the fixture coordinates from the test city centre. */
        return $action->run($destination, ['radius' => 6000, 'kinds' => ['historic'], 'limit' => 20]);
    }

    private function towerElement(): array
    {
        return [
            'type' => 'way',
            'id' => 123456,
            'center' => ['lat' => 51.5081, 'lon' => -0.0759],
            'tags' => [
                'name' => 'Tower of London',
                'historic' => 'castle',
                'website' => 'https://www.hrp.org.uk/tower-of-london/',
                'opening_hours' => 'Tu-Sa 09:00-17:30',
                'wikidata' => 'Q62378',
                'wikipedia' => 'en:Tower of London',
            ],
        ];
    }

    private function responses(): array
    {
        return [
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    $this->towerElement(),
                    [
                        'type' => 'node',
                        'id' => 654321,
                        'lat' => 51.5083,
                        'lon' => -0.0757,
                        'tags' => ['name' => 'Unremarkable Bench', 'historic' => 'memorial'],
                    ],
                ],
            ]),
            'en.wikipedia.org/*' => Http::response([
                'extract' => 'The Tower of London is a historic citadel on the north bank of the River Thames.',
                'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Tower_of_London']],
            ]),
            'www.wikidata.org/*' => Http::response([
                'claims' => ['P18' => [['mainsnak' => ['datavalue' => ['value' => 'Tower.jpg']]]]],
                'entities' => ['Q62378' => ['sitelinks' => ['enwiki' => ['title' => 'Tower of London']]]],
            ]),
            'commons.wikimedia.org/*' => Http::response([
                'query' => ['pages' => [[
                    'imageinfo' => [[
                        'thumburl' => 'https://upload.wikimedia.org/tower.jpg',
                        'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Tower.jpg',
                        'extmetadata' => [
                            'LicenseShortName' => ['value' => 'CC BY-SA 3.0'],
                            'Artist' => ['value' => 'Someone'],
                        ],
                    ]],
                ]]],
            ]),
        ];
    }
}
