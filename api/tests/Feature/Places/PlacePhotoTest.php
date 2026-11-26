<?php

declare(strict_types=1);

namespace Tests\Feature\Places;

use App\Domains\Experiences\Models\Experience;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Google photos are stored by name and resolved when asked for.
 *
 * Storing Google's signed photoUri meant every Google image went dead within
 * days: the cards fell back to placeholders while still crediting the
 * photographer.
 */
class PlacePhotoTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'places/ChIJabc123/photos/AXphoto_456';

    private const STALE_NAME_ERROR = ['error' => [
        'code' => 400,
        'message' => 'The photo resource in the request is invalid. Please fetch a new photo resource name.',
        'status' => 'INVALID_ARGUMENT',
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        config(['experience.place_data.google.api_key' => 'test-key']);
    }

    public function test_a_stored_photo_redirects_to_a_fresh_link_and_is_cached(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto(self::NAME);

        Http::fake([
            'places.googleapis.com/v1/'.self::NAME.'/media*' => Http::response(['photoUri' => 'https://lh3.googleusercontent.com/fresh']),
        ]);

        $this->get('/api/place-photos/'.self::NAME)->assertRedirect('https://lh3.googleusercontent.com/fresh');
        $this->get('/api/place-photos/'.self::NAME)->assertRedirect('https://lh3.googleusercontent.com/fresh');

        Http::assertSentCount(1);
    }

    public function test_a_photo_google_no_longer_has_is_not_found(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto(self::NAME);

        Http::fake(['places.googleapis.com/*' => Http::response([], 404)]);

        $this->get('/api/place-photos/'.self::NAME)->assertNotFound();
    }

    public function test_a_name_google_rejects_is_replaced_from_the_place_and_served(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto(self::NAME);
        $fresh = 'places/ChIJabc123/photos/AXfresh_789';

        Http::fake([
            'places.googleapis.com/v1/'.self::NAME.'/media*' => Http::response(self::STALE_NAME_ERROR, 400),
            'places.googleapis.com/v1/places/ChIJabc123' => Http::response([
                'photos' => [['name' => $fresh, 'authorAttributions' => [['displayName' => 'Ada', 'uri' => 'https://maps.google.com/ada']]]],
            ]),
            'places.googleapis.com/v1/'.$fresh.'/media*' => Http::response(['photoUri' => 'https://lh3.googleusercontent.com/new']),
        ]);

        $this->get('/api/place-photos/'.self::NAME)->assertRedirect('https://lh3.googleusercontent.com/new');

        $experience = Experience::query()->where('image_url', 'google-photo:'.$fresh)->firstOrFail();
        $this->assertSame('Ada', $experience->image_attribution['creator']);

        // The link a feed already on a phone holds keeps working.
        $this->get('/api/place-photos/'.self::NAME)->assertRedirect('https://lh3.googleusercontent.com/new');
        // And so does the new one.
        $this->get('/api/place-photos/'.$fresh)->assertRedirect('https://lh3.googleusercontent.com/new');
    }

    /** Google's current names run to ~400 characters, past the cache table's key column. */
    public function test_a_very_long_photo_name_survives_a_database_cache(): void
    {
        config(['cache.default' => 'database']);
        $this->seed(DatabaseSeeder::class);
        $long = 'places/ChIJabc123/photos/'.str_repeat('A1_-', 100);
        $this->storeCataloguePhoto($long);

        Http::fake([
            'places.googleapis.com/v1/'.$long.'/media*' => Http::response(['photoUri' => 'https://lh3.googleusercontent.com/long']),
        ]);

        $this->get('/api/place-photos/'.$long)->assertRedirect('https://lh3.googleusercontent.com/long');
    }

    public function test_a_dead_photo_costs_one_refresh_attempt_per_window(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto(self::NAME);

        Http::fake([
            'places.googleapis.com/v1/'.self::NAME.'/media*' => Http::response(self::STALE_NAME_ERROR, 400),
            'places.googleapis.com/v1/places/ChIJabc123' => Http::response(['photos' => []]),
        ]);

        $this->get('/api/place-photos/'.self::NAME)->assertNotFound();
        $callsAfterFirstView = count(Http::recorded());
        $this->get('/api/place-photos/'.self::NAME)->assertNotFound();

        // The second view costs Google nothing: the miss is remembered for the window.
        $this->assertCount($callsAfterFirstView, Http::recorded());

        // The place is only asked for a new photo once, however often the dead one is requested.
        $placeLookups = collect(Http::recorded())
            ->filter(fn ($pair) => ! str_contains($pair[0]->url(), '/media'))
            ->count();

        $this->assertSame(1, $placeLookups);
    }

    /** A bad or rotated key is our outage, not a stale name: no place should be re-photographed over it. */
    public function test_a_rejected_api_key_is_an_outage_not_a_stale_name(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto(self::NAME);

        Http::fake([
            'places.googleapis.com/*' => Http::response(['error' => [
                'code' => 400,
                'message' => 'API key not valid. Please pass a valid API key.',
                'status' => 'INVALID_ARGUMENT',
            ]], 400),
        ]);

        $this->get('/api/place-photos/'.self::NAME)->assertStatus(502);

        // Only the media endpoint was called; the place was never asked for a replacement.
        Http::assertNotSent(fn ($request) => ! str_contains($request->url(), '/media'));
        $this->assertTrue(Experience::query()->where('image_url', 'google-photo:'.self::NAME)->exists());
    }

    public function test_links_on_phones_keep_working_after_the_bulk_refresh(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto(self::NAME);
        $fresh = 'places/ChIJabc123/photos/AXfresh_789';

        Http::fake([
            'places.googleapis.com/v1/'.self::NAME.'/media*' => Http::response(self::STALE_NAME_ERROR, 400),
            'places.googleapis.com/v1/places/ChIJabc123' => Http::response(['photos' => [['name' => $fresh]]]),
            'places.googleapis.com/v1/'.$fresh.'/media*' => Http::response(['photoUri' => 'https://lh3.googleusercontent.com/new']),
        ]);

        $this->artisan('experience:refresh-google-photos')->assertSuccessful();

        $this->assertTrue(Experience::query()->where('image_url', 'google-photo:'.$fresh)->exists());
        $this->get('/api/place-photos/'.self::NAME)->assertRedirect('https://lh3.googleusercontent.com/new');
    }

    /** Names we do not store must not become a free Google media proxy. */
    public function test_an_unknown_photo_name_is_refused_without_calling_google(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['photoUri' => 'https://lh3.googleusercontent.com/stolen'])]);

        $this->get('/api/place-photos/'.self::NAME)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_cards_point_at_the_redirect_rather_than_the_stored_reference(): void
    {
        $this->seed(DatabaseSeeder::class);
        $experience = Experience::query()->where('status', 'published')->firstOrFail();
        $experience->forceFill(['image_url' => 'google-photo:'.self::NAME])->save();

        $image = $this->getJson("/api/experiences/{$experience->id}")->json('data.descriptive.image_url');

        $this->assertSame(url('api/place-photos/'.self::NAME), $image);
    }

    private function storeCataloguePhoto(string $name): void
    {
        Experience::query()->where('status', 'published')->firstOrFail()
            ->forceFill(['image_url' => 'google-photo:'.$name])
            ->save();
    }
}
