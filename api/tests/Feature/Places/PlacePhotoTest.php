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
