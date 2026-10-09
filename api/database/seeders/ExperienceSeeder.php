<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\Neighbourhood;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Experiences\Models\ExperienceCategory;
use App\Domains\Experiences\Models\ExperienceRelationship;
use App\Domains\Places\Models\Place;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SeedHelpers;
use Illuminate\Database\Seeder;

/**
 * Canonical places and experiences.
 *
 * IMPORTANT: this is demonstration content. Every row is written with
 * data_source = "seed_demo" and that provenance travels all the way to the API
 * response, so the app shows the traveller where a fact came from rather than
 * implying it is a live supplier feed. Replace this seeder with a real
 * place-data adapter (spec s9.2) before any production use; nothing else in the
 * codebase has to change, because callers read provenance rather than assume it.
 */
class ExperienceSeeder extends Seeder
{
    use SeedHelpers;

    public function run(): void
    {
        $verified = CarbonImmutable::now();
        $categories = ExperienceCategory::pluck('id', 'key');

        foreach ($this->data() as $citySlug => $city) {
            $destination = Destination::where('slug', $citySlug)->firstOrFail();
            $neighbourhoods = Neighbourhood::where('destination_id', $destination->id)->pluck('id', 'slug');

            foreach ($city['experiences'] as $row) {
                $place = Place::updateOrCreate(
                    ['slug' => $citySlug . '-' . $row['slug']],
                    [
                        'destination_id' => $destination->id,
                        'neighbourhood_id' => $neighbourhoods[$row['neighbourhood']] ?? null,
                        'name' => $row['place'],
                        'normalised_name' => $this->normalise($row['place']),
                        'kind' => $row['kind'] ?? 'attraction',
                        'address' => $row['address'] ?? null,
                        'lat' => $row['lat'],
                        'lng' => $row['lng'],
                        'timezone' => $destination->timezone,
                        'opening_hours' => $row['hours'],
                        'opening_hours_source' => 'seed_demo',
                        'opening_hours_verified_at' => $verified,
                        'rating' => $row['rating'][0] ?? null,
                        'rating_count' => $row['rating'][1] ?? null,
                        'rating_source' => isset($row['rating']) ? 'seed_demo' : null,
                        'rating_verified_at' => isset($row['rating']) ? $verified : null,
                        'accessibility' => $row['accessibility'] ?? [],
                        'accessibility_source' => isset($row['accessibility']) ? 'seed_demo' : null,
                        'canonical_confidence' => 100,
                        'resolution_status' => 'confirmed',
                    ],
                );

                $experience = Experience::updateOrCreate(
                    ['slug' => $citySlug . '-' . $row['slug']],
                    [
                        'destination_id' => $destination->id,
                        'place_id' => $place->id,
                        'neighbourhood_id' => $place->neighbourhood_id,
                        'title' => $row['title'],
                        'summary' => $row['summary'],
                        'why_it_matters' => $row['why'],
                        'best_for' => $row['best_for'] ?? null,
                        'what_to_wear' => $row['wear'] ?? null,
                        'traveller_tip' => $row['tip'] ?? null,
                        'min_duration_minutes' => $row['duration'][0],
                        'expected_duration_minutes' => $row['duration'][1],
                        'max_duration_minutes' => $row['duration'][2],
                        'is_free' => ($row['price'] ?? null) === null,
                        'price_from_minor' => $row['price'] ?? null,
                        'price_to_minor' => $row['price_to'] ?? null,
                        'currency' => $destination->currency,
                        'price_source' => isset($row['price']) ? 'seed_demo' : null,
                        'price_verified_at' => isset($row['price']) ? $verified : null,
                        'weather_exposure' => $row['exposure'],
                        'energy_level' => $row['energy'] ?? 'medium',
                        'uniqueness' => $row['uniqueness'],
                        'tourist_concentration' => $row['tourist'],
                        'value_signal' => $row['value'],
                        'iconic_weight' => $row['iconic'],
                        'requires_booking' => $row['booking'] ?? false,
                        'booking_lead_time_hours' => $row['lead'] ?? null,
                        'queue_risk' => $row['queue'] ?? 30,
                        'child_min_age' => $row['child_min'] ?? null,
                        'child_friendly_score' => $row['child'],
                        'romance_score' => $row['romance'],
                        'social_score' => $row['social'],
                        'has_toilets' => $row['toilets'] ?? true,
                        'food_on_site' => $row['food'] ?? false,
                        'interest_affinity' => $row['affinity'],
                        'mood_affinity' => $row['mood'] ?? [],
                        'accessibility' => $row['accessibility'] ?? [],
                        'best_time_of_day' => $row['best_time'] ?? [],
                        'know_before_you_go' => $row['kbyg'] ?? [],
                        /* Do not set image_url here. Seed is editorial text;
                           photography is filled by experience:backfill-imagery
                           (or ingest enrich). Writing null would wipe photos
                           on every deploy seed. */
                        'data_source' => 'seed_demo',
                        'verified_at' => $verified,
                        'status' => 'published',
                    ],
                );

                $experience->categories()->sync(
                    collect($row['categories'])->map(fn (string $key) => $categories[$key])->filter()->all(),
                );
            }

            $this->linkGraph($citySlug, $city['graph'] ?? []);
        }
    }

    /** Spec s10 — the Experience Graph. */
    private function linkGraph(string $citySlug, array $edges): void
    {
        $ids = Experience::where('slug', 'like', $citySlug . '-%')->pluck('id', 'slug');

        foreach ($edges as [$from, $type, $to, $weight]) {
            $fromId = $ids[$citySlug . '-' . $from] ?? null;
            $toId = $ids[$citySlug . '-' . $to] ?? null;

            if ($fromId === null || $toId === null) {
                continue;
            }

            ExperienceRelationship::updateOrCreate(
                ['from_experience_id' => $fromId, 'to_experience_id' => $toId, 'type' => $type],
                ['weight' => $weight],
            );

            /* Symmetric relationships are stored both ways so traversal is cheap. */
            if (in_array($type, ['near', 'walkable_to', 'often_combined_with', 'similar_to', 'same_neighbourhood'], true)) {
                ExperienceRelationship::updateOrCreate(
                    ['from_experience_id' => $toId, 'to_experience_id' => $fromId, 'type' => $type],
                    ['weight' => $weight],
                );
            }
        }
    }

    private function data(): array
    {
        return [
            'london' => require database_path('seeders/data/london.php'),
            'rome' => require database_path('seeders/data/rome.php'),
            'new-york' => require database_path('seeders/data/new-york.php'),
            'tokyo' => require database_path('seeders/data/tokyo.php'),
        ];
    }
}
