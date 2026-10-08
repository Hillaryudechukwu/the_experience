<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Searching for where you are going.
 *
 * The catalogue covers major tourism cities. What travellers get back still
 * has to distinguish three situations: we cover it, it exists but we do not
 * cover it, and we cannot find it at all.
 */
class DestinationSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** A country is a reasonable thing to type, and used to find nothing. */
    public function test_a_country_finds_the_cities_in_it(): void
    {
        $this->assertSame(['Kyoto', 'Osaka', 'Tokyo'], $this->covered('Japan'));
        $this->assertSame(['Edinburgh', 'London'], $this->covered('United Kingdom'));
        $this->assertSame(['Florence', 'Milan', 'Rome', 'Venice'], $this->covered('italy'));
    }

    public function test_a_city_still_finds_itself(): void
    {
        $this->assertSame(['London'], $this->covered('London'));
    }

    /**
     * The geocoder is only asked when the catalogue came back empty — its
     * usage policy is about a request a second, and spending that on searches
     * that already succeeded would be discourteous as well as slow.
     */
    public function test_the_geocoder_is_not_called_when_the_catalogue_answers(): void
    {
        Http::fake();

        $this->getJson('/api/destinations?q=Paris')->assertOk()
            ->assertJsonPath('data.0.name', 'Paris')
            ->assertJsonPath('elsewhere', []);

        Http::assertNothingSent();
    }

    public function test_worldwide_search_can_disambiguate_a_covered_city_name(): void
    {
        Http::fake(['*' => Http::response([[
            'name' => 'Paris',
            'display_name' => 'Paris, Texas, United States',
            'lat' => '33.6609',
            'lon' => '-95.5555',
            'addresstype' => 'city',
            'osm_type' => 'relation',
            'osm_id' => 115357,
            'address' => ['country' => 'United States', 'country_code' => 'us', 'state' => 'Texas'],
        ]])]);

        $response = $this->getJson('/api/destinations?q=Paris&worldwide=1')->assertOk();

        $response->assertJsonPath('data.0.name', 'Paris');
        $response->assertJsonPath('elsewhere.0.display_name', 'Paris, Texas, United States');
    }

    public function test_an_uncovered_city_is_reported_separately_from_the_catalogue(): void
    {
        Http::fake(['*' => Http::response([[
            'name' => 'Reykjavík',
            'display_name' => 'Reykjavík, Iceland',
            'lat' => '64.1466',
            'lon' => '-21.9426',
            'addresstype' => 'city',
            'osm_type' => 'relation',
            'osm_id' => 12345,
            'address' => ['country' => 'Iceland', 'country_code' => 'is'],
        ]])]);

        $response = $this->getJson('/api/destinations?q=Reykjavik')->assertOk();

        /* Not in `data`: it has no experiences, so nothing may offer it as a
           place the app can actually work in. */
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('elsewhere.0.name', 'Reykjavík');
        $response->assertJsonPath('elsewhere.0.country', 'Iceland');
        $response->assertJsonPath('elsewhere.0.covered', false);
        $response->assertJsonPath('elsewhere.0.kind', 'destination_candidate');
        $response->assertJsonPath('elsewhere.0.coverage_status', 'discoverable');
        $this->assertIsString($response->json('elsewhere.0.candidate_token'));
        $this->assertNotSame('', $response->json('elsewhere.0.candidate_token'));
        $this->assertArrayNotHasKey('id', $response->json('elsewhere.0'));
        $this->assertDatabaseHas('destination_candidate_demands', [
            'provider' => 'nominatim',
            'external_id' => 'relation/12345',
            'search_count' => 1,
        ]);
    }

    /** Nominatim answers a vague query with anything, including a postbox. */
    public function test_things_you_cannot_travel_to_are_discarded(): void
    {
        Http::fake(['*' => Http::response([
            [
                'name' => 'Some Street',
                'display_name' => 'Some Street, Paris',
                'lat' => '48.85', 'lon' => '2.35',
                'addresstype' => 'road',
                'osm_type' => 'way', 'osm_id' => 1,
                'address' => ['country' => 'France', 'country_code' => 'fr'],
            ],
            [
                'name' => 'Lyon',
                'display_name' => 'Lyon, France',
                'lat' => '45.76', 'lon' => '4.83',
                'addresstype' => 'city',
                'osm_type' => 'relation', 'osm_id' => 2,
                'address' => ['country' => 'France', 'country_code' => 'fr'],
            ],
        ])]);

        $elsewhere = $this->getJson('/api/destinations?q=Lyon')->assertOk()->json('elsewhere');

        $this->assertSame(['Lyon'], array_column($elsewhere, 'name'));
    }

    /** The same city arrives twice — the settlement and its admin area. */
    public function test_duplicates_are_collapsed(): void
    {
        $reykjavik = [
            'name' => 'Reykjavík',
            'display_name' => 'Reykjavík, Iceland',
            'lat' => '64.14', 'lon' => '-21.94',
            'addresstype' => 'city',
            'osm_type' => 'relation', 'osm_id' => 1,
            'address' => ['country' => 'Iceland', 'country_code' => 'is'],
        ];

        Http::fake(['*' => Http::response([$reykjavik, $reykjavik + ['osm_id' => 2]])]);

        $this->assertCount(1, $this->getJson('/api/destinations?q=Reykjavik')->json('elsewhere'));
    }

    public function test_repeated_search_by_the_same_actor_counts_demand_once_per_day(): void
    {
        Cache::clear();
        Http::fake(['*' => Http::response([[
            'name' => 'Reykjavík',
            'display_name' => 'Reykjavík, Iceland',
            'lat' => '64.1466',
            'lon' => '-21.9426',
            'addresstype' => 'city',
            'osm_type' => 'relation',
            'osm_id' => 12345,
            'address' => ['country' => 'Iceland', 'country_code' => 'is'],
        ]])]);

        $this->getJson('/api/destinations?q=Reykjavik')->assertOk();
        $this->getJson('/api/destinations?q=Reykjavik')->assertOk();

        $this->assertDatabaseHas('destination_candidate_demands', [
            'external_id' => 'relation/12345',
            'search_count' => 1,
        ]);
    }

    /**
     * A geocoder that is down, or over its rate limit, must not take the
     * destination list with it — the covered cities are the answer that
     * matters, and OutboundHttp refuses an over-limit call by throwing.
     */
    public function test_an_unreachable_geocoder_leaves_the_catalogue_intact(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $response = $this->getJson('/api/destinations?q=Reykjavik')->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame([], $response->json('elsewhere'));
    }

    /** @return list<string> */
    private function covered(string $term): array
    {
        return array_column(
            $this->getJson('/api/destinations?q='.urlencode($term))->assertOk()->json('data'),
            'name',
        );
    }
}
