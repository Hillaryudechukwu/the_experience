<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Experiences\Models\ExperienceCategory;
use Illuminate\Database\Seeder;

/** Spec s5.1 — the experience classification vocabulary. */
class CategorySeeder extends Seeder
{
    public const CATEGORIES = [
        ['must_experience', 'Must Experience', 'classification'],
        ['iconic', 'Iconic', 'classification'],
        ['hidden_gem', 'Hidden Gem', 'classification'],
        ['local_favourite', 'Local Favourite', 'classification'],
        ['food_experience', 'Food Experience', 'theme'],
        ['culture', 'Culture', 'theme'],
        ['nightlife', 'Nightlife', 'theme'],
        ['family', 'Family', 'theme'],
        ['romantic', 'Romantic', 'theme'],
        ['adventure', 'Adventure', 'theme'],
        ['nature', 'Nature', 'theme'],
        ['shopping', 'Shopping', 'theme'],
        ['free', 'Free', 'format'],
        ['quick_experience', 'Quick Experience', 'format'],
        ['half_day', 'Half-Day', 'format'],
        ['full_day', 'Full-Day', 'format'],
        ['weather_dependent', 'Weather Dependent', 'format'],
        ['book_ahead', 'Book Ahead', 'format'],
        ['beginner_friendly', 'Beginner Friendly', 'format'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $sort => [$key, $label, $kind]) {
            ExperienceCategory::updateOrCreate(
                ['key' => $key],
                ['label' => $label, 'kind' => $kind, 'sort' => $sort],
            );
        }
    }
}
