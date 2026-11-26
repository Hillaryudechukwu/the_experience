<?php

declare(strict_types=1);

namespace Tests\Feature\Places;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Places\Actions\RefreshGooglePhoto;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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

        // A 400 is an answer, not a blip: the media endpoint is asked once, not three times.
        $mediaLookups = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/media'))->count();
        $this->assertSame(1, $mediaLookups);

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
                'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID']],
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

    /** A name replaced twice while old feeds are still out: the oldest link follows both hops. */
    public function test_an_old_link_follows_a_name_replaced_twice(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto(self::NAME);
        $second = 'places/ChIJabc123/photos/AXsecond_1';
        $third = 'places/ChIJabc123/photos/AXthird_2';

        Http::fake([
            'places.googleapis.com/v1/'.self::NAME.'/media*' => Http::response(self::STALE_NAME_ERROR, 400),
            'places.googleapis.com/v1/'.$second.'/media*' => Http::response(self::STALE_NAME_ERROR, 400),
            'places.googleapis.com/v1/places/ChIJabc123' => Http::sequence()
                ->push(['photos' => [['name' => $second]]])
                ->push(['photos' => [['name' => $third]]]),
            'places.googleapis.com/v1/'.$third.'/media*' => Http::response(['photoUri' => 'https://lh3.googleusercontent.com/third']),
        ]);

        $this->artisan('experience:refresh-google-photos')->assertSuccessful();
        $this->artisan('experience:refresh-google-photos')->assertSuccessful();

        $this->assertTrue(Experience::query()->where('image_url', 'google-photo:'.$third)->exists());
        $this->get('/api/place-photos/'.self::NAME)->assertRedirect('https://lh3.googleusercontent.com/third');
    }

    /** A rename that fails part way leaves every card on the old name, not some on each. */
    public function test_a_failed_rename_changes_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto(self::NAME);
        Destination::query()->firstOrFail()->forceFill(['hero_image_url' => 'google-photo:'.self::NAME])->save();

        Http::fake([
            'places.googleapis.com/v1/places/ChIJabc123' => Http::response(['photos' => [['name' => 'places/ChIJabc123/photos/AXfresh_789']]]),
        ]);
        Destination::saving(fn () => throw new RuntimeException('database went away'));

        try {
            app(RefreshGooglePhoto::class)->refresh('google-photo:'.self::NAME);
            $this->fail('The rename should have failed.');
        } catch (RuntimeException) {
        }

        $this->assertTrue(Experience::query()->where('image_url', 'google-photo:'.self::NAME)->exists());
        $this->assertSame(self::NAME, RefreshGooglePhoto::currentName(self::NAME));
    }

    public function test_the_bulk_refresh_reports_an_unreadable_name_as_such(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto('places/ChIJabc123/photos/not.a.photo.id');

        Http::fake([
            'places.googleapis.com/v1/places/ChIJabc123/photos/*' => Http::response(self::STALE_NAME_ERROR, 400),
        ]);

        $this->artisan('experience:refresh-google-photos')
            ->expectsOutputToContain('not a places/{placeId}/photos/{photoId} name')
            ->assertSuccessful();

        // No place is asked for a photo it cannot be matched to.
        Http::assertNotSent(fn ($request) => ! str_contains($request->url(), '/media'));
    }

    /** If Google rewords its message, a stale name must still be refreshed rather than become an outage. */
    public function test_a_reworded_stale_name_error_is_still_refreshed(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto(self::NAME);
        $fresh = 'places/ChIJabc123/photos/AXfresh_789';

        Http::fake([
            'places.googleapis.com/v1/'.self::NAME.'/media*' => Http::response(['error' => [
                'code' => 400, 'message' => 'Request contains an invalid argument.', 'status' => 'INVALID_ARGUMENT',
            ]], 400),
            'places.googleapis.com/v1/places/ChIJabc123' => Http::response(['photos' => [['name' => $fresh]]]),
            'places.googleapis.com/v1/'.$fresh.'/media*' => Http::response(['photoUri' => 'https://lh3.googleusercontent.com/new']),
        ]);

        $this->get('/api/place-photos/'.self::NAME)->assertRedirect('https://lh3.googleusercontent.com/new');
    }

    /** A name that comes back round (A to B, then B to A) is served as itself, not lost in the loop. */
    public function test_a_name_that_comes_back_round_is_still_served(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto(self::NAME);
        $other = 'places/ChIJabc123/photos/AXother_1';

        Http::fake([
            'places.googleapis.com/v1/places/ChIJabc123' => Http::sequence()
                ->push(['photos' => [['name' => $other]]])
                ->push(['photos' => [['name' => self::NAME]]]),
            'places.googleapis.com/v1/'.self::NAME.'/media*' => Http::response(['photoUri' => 'https://lh3.googleusercontent.com/back']),
        ]);

        $refresh = app(RefreshGooglePhoto::class);
        $this->assertSame('google-photo:'.$other, $refresh->refresh('google-photo:'.self::NAME));
        $this->assertSame('google-photo:'.self::NAME, $refresh->refresh('google-photo:'.$other));

        $this->get('/api/place-photos/'.self::NAME)->assertRedirect('https://lh3.googleusercontent.com/back');
    }

    /** The place listing the very name Google rejected is no refresh, and is not reported as one. */
    public function test_getting_the_same_name_back_is_not_a_refresh(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->storeCataloguePhoto(self::NAME);

        Http::fake([
            'places.googleapis.com/v1/'.self::NAME.'/media*' => Http::response(self::STALE_NAME_ERROR, 400),
            'places.googleapis.com/v1/places/ChIJabc123' => Http::response(['photos' => [['name' => self::NAME]]]),
        ]);

        $this->assertNull(app(RefreshGooglePhoto::class)->refresh('google-photo:'.self::NAME));

        $this->artisan('experience:refresh-google-photos')
            ->expectsTable(
                ['Names', 'Valid within a day, skipped', 'Checked', 'Left for next run', 'Still valid', 'Refreshed', 'No new photo', 'Unreadable name', 'Failed'],
                [[1, 0, 1, 0, 0, 0, 1, 0, 0]],
            )
            ->assertSuccessful();
    }

    /** A catalogue larger than --limit is worked through across runs, not stuck on the same first names. */
    public function test_the_bulk_refresh_reaches_names_past_the_limit_on_later_runs(): void
    {
        $this->seed(DatabaseSeeder::class);
        $live = 'places/ChIJabc123/photos/AAlive_1';
        $dead = 'places/ChIJabc123/photos/ZZdead_2';
        $fresh = 'places/ChIJabc123/photos/AXfresh_789';
        [$first, $second] = Experience::query()->where('status', 'published')->take(2)->get()->all();
        $first->forceFill(['image_url' => 'google-photo:'.$live])->save();
        $second->forceFill(['image_url' => 'google-photo:'.$dead])->save();

        Http::fake([
            'places.googleapis.com/v1/'.$live.'/media*' => Http::response(['photoUri' => 'https://lh3.googleusercontent.com/live']),
            'places.googleapis.com/v1/'.$dead.'/media*' => Http::response(self::STALE_NAME_ERROR, 400),
            'places.googleapis.com/v1/places/ChIJabc123' => Http::response(['photos' => [['name' => $fresh]]]),
        ]);

        $this->artisan('experience:refresh-google-photos', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('google-photo:'.$dead, $second->fresh()->image_url);

        $this->artisan('experience:refresh-google-photos', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('google-photo:'.$fresh, $second->fresh()->image_url);
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
