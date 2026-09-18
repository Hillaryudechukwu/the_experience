<?php

declare(strict_types=1);

namespace Tests\Feature\Places;

use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\Places\Actions\BackfillPlaceRatings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/**
 * Google supplies the one thing OpenStreetMap cannot: ratings with volume.
 *
 * The matching is the risky part. A rating attached to the wrong place is worse
 * than no rating at all — it would push a traveller towards somewhere on the
 * strength of someone else's reviews — so these tests are mostly about what the
 * backfill refuses to do.
 */
class BackfillPlaceRatingsTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['experience.place_data.google.api_key' => 'test-key']);
    }

    public function test_a_confident_match_writes_the_rating_and_remembers_the_mapping(): void
    {
        $destination = $this->destination();
        $experience = $this->experience($destination, [
            'slug' => 'comedy-store',
            'title' => 'Comedy Store',
            'lat' => 51.5100,
            'lng' => -0.1320,
            'rating' => null,
            'rating_count' => null,
        ]);

        Http::fake(['*' => Http::response($this->searchResult('Comedy Store', 51.5101, -0.1321, 4.7, 3452))]);

        $result = app(BackfillPlaceRatings::class)->run($destination, ['limit' => 10]);

        $this->assertSame(1, $result['matched']);

        $place = $experience->place->fresh();
        $this->assertSame(4.7, (float) $place->rating);
        $this->assertSame(3452, $place->rating_count);
        $this->assertSame('google_places', $place->rating_source);
        $this->assertNotNull($place->rating_verified_at);

        /* Next run is a cheap lookup by id rather than another text search. */
        $this->assertDatabaseHas('external_entities', [
            'provider' => 'google_places',
            'entity_type' => 'place',
            'entity_id' => $place->id,
        ]);
    }

    public function test_a_similarly_named_place_down_the_road_is_not_accepted(): void
    {
        $destination = $this->destination();
        $experience = $this->experience($destination, [
            'slug' => 'st-marys',
            'title' => 'St Mary\'s Church',
            'lat' => 51.5100,
            'lng' => -0.1320,
            'rating' => null,
            'rating_count' => null,
        ]);

        /* Same name, 700m away — a different church. */
        Http::fake(['*' => Http::response($this->searchResult('St Mary\'s Church', 51.5163, -0.1320, 4.8, 900))]);

        $result = app(BackfillPlaceRatings::class)->run($destination, ['limit' => 10]);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['unmatched']);
        $this->assertNull($experience->place->fresh()->rating);
    }

    public function test_a_close_but_differently_named_place_is_not_accepted(): void
    {
        $destination = $this->destination();
        $experience = $this->experience($destination, [
            'slug' => 'seaport-museum',
            'title' => 'South Street Seaport Museum',
            'lat' => 51.5100,
            'lng' => -0.1320,
            'rating' => null,
            'rating_count' => null,
        ]);

        Http::fake(['*' => Http::response($this->searchResult('Pier 17 Rooftop', 51.5101, -0.1321, 4.2, 5000))]);

        $result = app(BackfillPlaceRatings::class)->run($destination, ['limit' => 10]);

        $this->assertSame(1, $result['unmatched']);
        $this->assertNull($experience->place->fresh()->rating);
    }

    public function test_an_already_mapped_place_is_fetched_by_id_rather_than_searched_for(): void
    {
        $destination = $this->destination();
        $experience = $this->experience($destination, [
            'slug' => 'mapped',
            'title' => 'Already Mapped',
            'lat' => 51.5100,
            'lng' => -0.1320,
            'rating' => null,
            'rating_count' => null,
        ]);

        ExternalEntity::create([
            'provider' => 'google_places',
            'provider_id' => 'ChIJ_known',
            'entity_type' => 'place',
            'entity_id' => $experience->place_id,
            'confidence' => 0.95,
        ]);

        Http::fake(['*' => Http::response($this->place('Already Mapped', 51.5100, -0.1320, 4.3, 120))]);

        app(BackfillPlaceRatings::class)->run($destination, ['limit' => 10]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/places/ChIJ_known'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'searchText'));
        $this->assertSame(4.3, (float) $experience->place->fresh()->rating);
    }

    public function test_places_with_a_recent_rating_are_not_paid_for_again(): void
    {
        $destination = $this->destination();
        $this->experience($destination, [
            'slug' => 'already-rated',
            'title' => 'Already Rated',
            'rating' => 4.5,
            'rating_count' => 900,
            'opening_hours' => $this->openAllWeek(),
        ]);

        Http::fake();

        $result = app(BackfillPlaceRatings::class)->run($destination, ['limit' => 10]);

        $this->assertSame(0, $result['requests']);
        Http::assertNothingSent();
    }

    public function test_force_recheks_a_rating_that_is_already_present(): void
    {
        $destination = $this->destination();
        $this->experience($destination, [
            'slug' => 'stale',
            'title' => 'Stale Rating',
            'lat' => 51.5100,
            'lng' => -0.1320,
            'opening_hours' => $this->openAllWeek(),
        ]);

        Http::fake(['*' => Http::response($this->searchResult('Stale Rating', 51.5100, -0.1320, 4.9, 12000))]);

        $result = app(BackfillPlaceRatings::class)->run($destination, ['limit' => 10, 'force' => true]);

        $this->assertSame(1, $result['requests']);
        $this->assertSame(1, $result['matched']);
    }

    public function test_a_match_google_has_no_rating_for_is_counted_not_written(): void
    {
        $destination = $this->destination();
        $experience = $this->experience($destination, [
            'slug' => 'unrated',
            'title' => 'Quiet Corner',
            'lat' => 51.5100,
            'lng' => -0.1320,
            'rating' => null,
            'rating_count' => null,
        ]);

        Http::fake(['*' => Http::response($this->searchResult('Quiet Corner', 51.5100, -0.1320, null, null))]);

        $result = app(BackfillPlaceRatings::class)->run($destination, ['limit' => 10]);

        $this->assertSame(1, $result['skipped']);
        $this->assertNull($experience->place->fresh()->rating);
    }

    public function test_a_failure_is_recorded_and_the_run_continues(): void
    {
        $destination = $this->destination();
        $this->experience($destination, ['slug' => 'a', 'title' => 'Place A', 'lat' => 51.51, 'lng' => -0.132, 'rating' => null, 'rating_count' => null]);

        Http::fake(['*' => Http::response('quota exceeded', 429)]);

        $result = app(BackfillPlaceRatings::class)->run($destination, ['limit' => 10]);

        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseHas('provider_sync_failures', ['stage' => 'rating_match']);
        $this->assertSame('completed_with_failures', $result['run']->status);
    }

    public function test_it_also_fills_opening_hours_where_the_venue_publishes_them(): void
    {
        $destination = $this->destination();
        $experience = $this->experience($destination, [
            'slug' => 'no-hours',
            'title' => 'Venue Without Hours',
            'lat' => 51.5100,
            'lng' => -0.1320,
            'rating' => null,
            'rating_count' => null,
            'opening_hours' => null,
        ]);

        $body = $this->placeBody('Venue Without Hours', 51.5100, -0.1320, 4.4, 200);
        $body['regularOpeningHours'] = [
            'periods' => [
                ['open' => ['day' => 1, 'hour' => 10, 'minute' => 0], 'close' => ['day' => 1, 'hour' => 18, 'minute' => 0]],
            ],
        ];

        Http::fake(['*' => Http::response(['places' => [$body]])]);

        app(BackfillPlaceRatings::class)->run($destination, ['limit' => 10]);

        $place = $experience->place->fresh();
        $this->assertSame([['10:00', '18:00']], $place->opening_hours['mon']);
        $this->assertSame('google_places', $place->opening_hours_source);
        $this->assertNotNull($place->opening_hours_verified_at);
    }

    public function test_a_place_missing_only_hours_is_still_worth_a_lookup(): void
    {
        $destination = $this->destination();
        $this->experience($destination, [
            'slug' => 'rated-no-hours',
            'title' => 'Rated But Hourless',
            'rating' => 4.5,
            'rating_count' => 900,
            'opening_hours' => null,
        ]);

        Http::fake(['*' => Http::response(['places' => []])]);

        $result = app(BackfillPlaceRatings::class)->run($destination, ['limit' => 10]);

        $this->assertSame(1, $result['requests']);
    }

    private function searchResult(string $name, float $lat, float $lng, ?float $rating, ?int $count): array
    {
        return ['places' => [$this->placeBody($name, $lat, $lng, $rating, $count)]];
    }

    private function place(string $name, float $lat, float $lng, ?float $rating, ?int $count): array
    {
        return $this->placeBody($name, $lat, $lng, $rating, $count);
    }

    public function placeBody(string $name, float $lat, float $lng, ?float $rating, ?int $count): array
    {
        return array_filter([
            'id' => 'ChIJ_' . md5($name),
            'displayName' => ['text' => $name],
            'location' => ['latitude' => $lat, 'longitude' => $lng],
            'types' => ['tourist_attraction'],
            'rating' => $rating,
            'userRatingCount' => $count,
        ], fn ($v) => $v !== null);
    }
}
