<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Searching for where you are going.
 *
 * The catalogue is four cities, so most of what a traveller types is not in
 * it. What they get back has to distinguish three different situations: we
 * cover it, it exists but we do not cover it, and we cannot find it at all.
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
        $this->assertSame(['Tokyo'], $this->covered('Japan'));
        $this->assertSame(['London'], $this->covered('United Kingdom'));
        $this->assertSame(['Rome'], $this->covered('italy'));
    }

    public function test_a_city_still_finds_itself(): void
    {
        $this->assertSame(['London'], $this->covered('lon'));
    }

    /**
     * The geocoder is only asked when the catalogue came back empty — its
     * usage policy is about a request a second, and spending that on searches
     * that already succeeded would be discourteous as well as slow.
     */
    public function test_the_geocoder_is_not_called_when_the_catalogue_answers(): void
    {
        Http::fake();

        $this->getJson('/api/destinations?q=London')->assertOk()->assertJsonPath('elsewhere', []);

        Http::assertNothingSent();
    }

    public function test_an_uncovered_city_is_reported_separately_from_the_catalogue(): void
    {
        Http::fake(['*' => Http::response([[
            'name' => 'Paris',
            'display_name' => 'Paris, Île-de-France, France',
            'lat' => '48.8566',
            'lon' => '2.3522',
            'addresstype' => 'city',
            'osm_type' => 'relation',
            'osm_id' => 71525,
            'address' => ['country' => 'France', 'country_code' => 'fr'],
        ]])]);

        $response = $this->getJson('/api/destinations?q=Paris')->assertOk();

        /* Not in `data`: it has no experiences, so nothing may offer it as a
           place the app can actually work in. */
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('elsewhere.0.name', 'Paris');
        $response->assertJsonPath('elsewhere.0.country', 'France');
        $response->assertJsonPath('elsewhere.0.covered', false);
        $this->assertArrayNotHasKey('id', $response->json('elsewhere.0'));
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
        $paris = [
            'name' => 'Paris',
            'display_name' => 'Paris, France',
            'lat' => '48.85', 'lon' => '2.35',
            'addresstype' => 'city',
            'osm_type' => 'relation', 'osm_id' => 1,
            'address' => ['country' => 'France', 'country_code' => 'fr'],
        ];

        Http::fake(['*' => Http::response([$paris, $paris + ['osm_id' => 2]])]);

        $this->assertCount(1, $this->getJson('/api/destinations?q=Paris')->json('elsewhere'));
    }

    /**
     * A geocoder that is down, or over its rate limit, must not take the
     * destination list with it — the covered cities are the answer that
     * matters, and OutboundHttp refuses an over-limit call by throwing.
     */
    public function test_an_unreachable_geocoder_leaves_the_catalogue_intact(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $response = $this->getJson('/api/destinations?q=Paris')->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame([], $response->json('elsewhere'));
    }

    /** @return list<string> */
    private function covered(string $term): array
    {
        return array_column(
            $this->getJson('/api/destinations?q=' . urlencode($term))->assertOk()->json('data'),
            'name',
        );
    }
}
