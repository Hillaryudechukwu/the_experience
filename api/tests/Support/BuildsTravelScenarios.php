<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Experiences\Models\ExperienceCategory;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Places\Models\Place;
use App\Domains\Shared\ValueObjects\Actor;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Str;

/**
 * Fixed scenarios for recommendation regression tests (spec s31).
 *
 * Deliberately hand-built rather than seeded from the demo catalogue, so a
 * change to seed content cannot silently change what these tests assert.
 */
trait BuildsTravelScenarios
{
    protected function destination(array $attributes = []): Destination
    {
        return Destination::create(array_merge([
            'slug' => 'testville-' . Str::random(6),
            'name' => 'Testville',
            'country' => 'Testland',
            'country_code' => 'GB',
            'timezone' => 'Europe/London',
            'lat' => 51.5074,
            'lng' => -0.1278,
            'currency' => 'GBP',
            'languages' => ['en'],
        ], $attributes));
    }

    protected function experience(Destination $destination, array $attributes = []): Experience
    {
        $this->seed(CategorySeeder::class);

        $slug = $attributes['slug'] ?? 'exp-' . Str::random(8);
        $categories = $attributes['categories'] ?? [];
        unset($attributes['categories']);

        $place = Place::create([
            'destination_id' => $destination->id,
            'slug' => 'place-' . $slug,
            'name' => $attributes['title'] ?? 'Test place',
            'normalised_name' => 'test place',
            'lat' => $attributes['lat'] ?? 51.5074,
            'lng' => $attributes['lng'] ?? -0.1278,
            'timezone' => $destination->timezone,
            'opening_hours' => $attributes['opening_hours'] ?? $this->openAllWeek(),
            'opening_hours_source' => 'test_fixture',
            'opening_hours_verified_at' => CarbonImmutable::now(),
            'rating' => array_key_exists('rating', $attributes) ? $attributes['rating'] : 4.5,
            'rating_count' => array_key_exists('rating_count', $attributes) ? $attributes['rating_count'] : 5000,
            'rating_source' => array_key_exists('rating', $attributes) && $attributes['rating'] === null ? null : 'test_fixture',
            'rating_verified_at' => array_key_exists('rating', $attributes) && $attributes['rating'] === null ? null : CarbonImmutable::now(),
            'accessibility' => $attributes['accessibility'] ?? [],
        ]);

        $experience = Experience::create(array_merge([
            'destination_id' => $destination->id,
            'place_id' => $place->id,
            'slug' => $slug,
            'title' => 'Test experience',
            'summary' => 'A test experience.',
            'why_it_matters' => 'Because the test says so.',
            'min_duration_minutes' => 30,
            'expected_duration_minutes' => 60,
            'max_duration_minutes' => 120,
            'is_free' => true,
            'currency' => 'GBP',
            'weather_exposure' => 'indoor',
            'energy_level' => 'low',
            'uniqueness' => 50,
            'tourist_concentration' => 50,
            'value_signal' => 50,
            'iconic_weight' => 50,
            'child_friendly_score' => 50,
            'romance_score' => 50,
            'social_score' => 50,
            'interest_affinity' => [],
            'mood_affinity' => [],
            'best_time_of_day' => [],
            'data_source' => 'test_fixture',
            'verified_at' => CarbonImmutable::now(),
        ], collect($attributes)->except(['lat', 'lng', 'opening_hours', 'rating', 'rating_count', 'accessibility'])->all()));

        if ($categories !== []) {
            $experience->categories()->sync(ExperienceCategory::whereIn('key', $categories)->pluck('id')->all());
        }

        return $experience->fresh(['place', 'categories']);
    }

    protected function journey(Destination $destination, Actor $actor, array $attributes = []): Journey
    {
        return Journey::create(array_merge($actor->ownerAttributes(), [
            'destination_id' => $destination->id,
            'reason' => 'holiday',
            'adults' => 2,
            'children' => 0,
            'familiarity' => 'never',
            'currency' => 'GBP',
        ], $attributes));
    }

    protected function openAllWeek(string $from = '00:00', string $to = '23:59'): array
    {
        $schedule = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $schedule[$day] = [[$from, $to]];
        }

        return $schedule;
    }

    protected function guestHeaders(?string $token = null): array
    {
        return $token === null ? [] : ['X-Guest-Token' => $token];
    }
}
