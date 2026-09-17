<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalSources;

use App\Domains\ExternalSources\Contracts\PlaceDataProvider;
use App\Domains\ExternalSources\Providers\GooglePlacesProvider;
use App\Domains\ExternalSources\Providers\OverpassPlaceProvider;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract test for the Google Places adapter. It is inert without a key, so
 * this exercises the response mapping against a recorded fixture — the part
 * that breaks when the upstream shape changes.
 */
class GooglePlacesProviderTest extends TestCase
{
    public function test_it_is_inert_without_a_key_and_osm_stays_the_default(): void
    {
        config(['experience.place_data.google.api_key' => null]);

        $this->assertFalse(app(GooglePlacesProvider::class)->isConfigured());
        $this->assertInstanceOf(OverpassPlaceProvider::class, app()->make(PlaceDataProvider::class));
    }

    public function test_configuring_a_key_promotes_it_to_the_place_data_source(): void
    {
        config(['experience.place_data.google.api_key' => 'test-key']);
        app()->forgetInstance(PlaceDataProvider::class);

        $this->assertInstanceOf(GooglePlacesProvider::class, app()->make(PlaceDataProvider::class));
    }

    public function test_it_maps_a_place_including_ratings_that_osm_cannot_provide(): void
    {
        config(['experience.place_data.google.api_key' => 'test-key']);
        Http::fake(['*' => Http::response($this->fixture())]);

        $candidate = app(GooglePlacesProvider::class)
            ->searchNearby(new GeoPoint(51.5081, -0.0759), 2000, ['historic'], 10)
            ->first();

        $this->assertSame('Tower of London', $candidate->name);
        $this->assertSame(4.6, $candidate->rating);
        $this->assertSame(84000, $candidate->ratingCount);
        $this->assertSame('historic', $candidate->kind);
        $this->assertTrue($candidate->accessibility['wheelchair_accessible']);
        $this->assertFalse($candidate->accessibility['accessible_toilets']);
    }

    public function test_it_translates_google_weekday_periods_into_our_schedule(): void
    {
        config(['experience.place_data.google.api_key' => 'test-key']);
        Http::fake(['*' => Http::response($this->fixture())]);

        $candidate = app(GooglePlacesProvider::class)
            ->searchNearby(new GeoPoint(51.5081, -0.0759), 2000, ['historic'], 10)
            ->first();

        /* Google numbers days from Sunday; ours are named. */
        $this->assertSame([['09:00', '17:30']], $candidate->openingHours['mon']);
        $this->assertSame([['10:00', '17:30']], $candidate->openingHours['sun']);
    }

    public function test_the_api_key_is_sent_as_a_header_not_a_query_parameter(): void
    {
        config(['experience.place_data.google.api_key' => 'test-key']);
        Http::fake(['*' => Http::response($this->fixture())]);

        app(GooglePlacesProvider::class)->searchNearby(new GeoPoint(51.5081, -0.0759), 2000, ['historic'], 10);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Goog-Api-Key', 'test-key')
                && ! str_contains($request->url(), 'test-key');
        });
    }

    private function fixture(): array
    {
        return [
            'places' => [[
                'id' => 'ChIJ8ZnYQ4MFdkgRxbFBGKMQ2Bw',
                'displayName' => ['text' => 'Tower of London'],
                'formattedAddress' => 'London EC3N 4AB, United Kingdom',
                'location' => ['latitude' => 51.5081, 'longitude' => -0.0759],
                'types' => ['historical_landmark', 'tourist_attraction'],
                'rating' => 4.6,
                'userRatingCount' => 84000,
                'websiteUri' => 'https://www.hrp.org.uk/tower-of-london/',
                'accessibilityOptions' => [
                    'wheelchairAccessibleEntrance' => true,
                    'wheelchairAccessibleRestroom' => false,
                ],
                'regularOpeningHours' => [
                    'periods' => [
                        ['open' => ['day' => 1, 'hour' => 9, 'minute' => 0], 'close' => ['day' => 1, 'hour' => 17, 'minute' => 30]],
                        ['open' => ['day' => 0, 'hour' => 10, 'minute' => 0], 'close' => ['day' => 0, 'hour' => 17, 'minute' => 30]],
                    ],
                ],
            ]],
        ];
    }
}
