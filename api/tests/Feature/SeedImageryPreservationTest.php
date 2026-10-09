<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Experiences\Models\Experience;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deploy runs db:seed on every release. Seed must not erase photography that
 * backfill or ingest already attached — otherwise the catalogue flickers back
 * to gradient cards after every ship.
 */
class SeedImageryPreservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseeding_keeps_backfilled_experience_and_city_photos(): void
    {
        $this->seed(DatabaseSeeder::class);

        $experience = Experience::query()->where('status', 'published')->firstOrFail();
        $destination = Destination::query()->where('slug', $experience->destination->slug)->firstOrFail();

        $experience->update([
            'image_url' => 'https://upload.wikimedia.org/example/experience.jpg',
            'image_attribution' => ['credit' => 'Test Photographer'],
        ]);
        $destination->update([
            'hero_image_url' => 'https://upload.wikimedia.org/example/city.jpg',
            'hero_image_attribution' => ['credit' => 'City Photographer'],
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            'https://upload.wikimedia.org/example/experience.jpg',
            $experience->fresh()->image_url,
        );
        $this->assertSame(
            'https://upload.wikimedia.org/example/city.jpg',
            $destination->fresh()->hero_image_url,
        );
    }
}
