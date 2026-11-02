<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Experiences\Models\Experience;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue health for Gate 4 launch-city walks (PAID_LAUNCH_GATES_JEM G4).
 *
 * Prints published counts and coverage gaps so an owner can decide whether a
 * city is worth £1.99 — it does not invent that verdict.
 */
class LaunchCityReportCommand extends Command
{
    protected $signature = 'experience:launch-city-report
                            {slug : Destination slug (e.g. london)}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Report published experience coverage for a launch-city worth walk';

    public function handle(): int
    {
        $slug = strtolower((string) $this->argument('slug'));
        $destination = Destination::query()->where('slug', $slug)->first();

        if ($destination === null) {
            $this->error("No destination with slug [{$slug}].");

            return self::FAILURE;
        }

        $published = Experience::query()
            ->where('destination_id', $destination->id)
            ->where('status', 'published');

        $total = (clone $published)->count();
        $withPhoto = (clone $published)->whereNotNull('image_url')->where('image_url', '!=', '')->count();
        $withPrice = (clone $published)->where(function ($query) {
            $query->where('is_free', true)->orWhereNotNull('price_from_minor');
        })->count();
        $categoryCount = (int) DB::table('experience_category_links')
            ->join('experiences', 'experiences.id', '=', 'experience_category_links.experience_id')
            ->where('experiences.destination_id', $destination->id)
            ->where('experiences.status', 'published')
            ->distinct()
            ->count('experience_category_links.experience_category_id');
        $maxDays = (int) config('experience.itinerary.max_planned_days');

        $payload = [
            'slug' => $destination->slug,
            'name' => $destination->name,
            'coverage_status' => $destination->coverage_status?->value ?? $destination->coverage_status,
            'published_experiences' => $total,
            'with_photo' => $withPhoto,
            'with_photo_percent' => $total === 0 ? 0 : (int) round(100 * $withPhoto / $total),
            'with_price_or_free' => $withPrice,
            'distinct_categories' => $categoryCount,
            'itinerary_max_planned_days' => $maxDays,
            'notes' => [
                'Group votes and offline mode are not in this launch.',
                'Imagery backfill: php artisan experience:backfill-imagery '.$destination->slug,
                'Owner Gate 4 walk still required — this report is not a GO.',
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("{$destination->name} ({$destination->slug})");
        $this->line('Coverage: '.($payload['coverage_status'] ?? 'unknown'));
        $this->table(
            ['Metric', 'Value'],
            [
                ['Published experiences', (string) $total],
                ['With photo', "{$withPhoto} ({$payload['with_photo_percent']}%)"],
                ['With price or free', (string) $withPrice],
                ['Distinct categories', (string) $categoryCount],
                ['Itinerary max days', (string) $maxDays],
            ],
        );
        foreach ($payload['notes'] as $note) {
            $this->comment('• '.$note);
        }

        return self::SUCCESS;
    }
}
