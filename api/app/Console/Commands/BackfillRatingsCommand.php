<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Destinations\Models\Destination;
use App\Domains\ExternalSources\Providers\GooglePlacesProvider;
use App\Domains\Places\Actions\BackfillPlaceRatings;
use Illuminate\Console\Command;

/**
 * Google bills per request, so this command is explicit about what it will
 * spend before it spends it. --limit is the cap, and the estimate is printed
 * and confirmed rather than buried in documentation.
 */
class BackfillRatingsCommand extends Command
{
    protected $signature = 'experience:backfill-ratings
                            {destination : Destination slug, or "all"}
                            {--limit=50 : Maximum places to look up in this run}
                            {--force : Re-check places that already have a recent rating}
                            {--stale-after=30 : Days after which an existing rating is re-checked}
                            {--published-only : Only places backing a published experience}';

    protected $description = 'Add Google ratings to canonical places that have none';

    public function handle(GooglePlacesProvider $google, BackfillPlaceRatings $action): int
    {
        if (! $google->isConfigured()) {
            $this->error('GOOGLE_PLACES_API_KEY is not set, so there is nothing to ask.');

            return self::FAILURE;
        }

        $slug = $this->argument('destination');
        $destinations = $slug === 'all'
            ? Destination::orderBy('name')->get()
            : Destination::where('slug', $slug)->get();

        if ($destinations->isEmpty()) {
            $this->error("No destination matches '{$slug}'.");

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $ceiling = $limit * $destinations->count();

        $this->warn(sprintf(
            'This will make up to %d billed Google Places requests (%d per destination × %d).',
            $ceiling,
            $limit,
            $destinations->count(),
        ));

        if ($this->input->isInteractive() && ! $this->confirm('Continue?', true)) {
            $this->line('Nothing was requested.');

            return self::SUCCESS;
        }

        $totals = ['matched' => 0, 'unmatched' => 0, 'skipped' => 0, 'failed' => 0, 'requests' => 0];

        foreach ($destinations as $destination) {
            $this->info("Backfilling ratings for {$destination->name}…");

            $result = $action->run($destination, [
                'limit' => $limit,
                'force' => (bool) $this->option('force'),
                'stale_after_days' => (int) $this->option('stale-after'),
                'published_only' => (bool) $this->option('published-only'),
            ]);

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $result[$key];
            }

            $this->table(
                ['requests', 'rated', 'no confident match', 'matched but unrated', 'failed'],
                [[$result['requests'], $result['matched'], $result['unmatched'], $result['skipped'], $result['failed']]],
            );
        }

        $this->newLine();
        $this->line(sprintf(
            '%d billed requests made. %d places now carry a Google rating.',
            $totals['requests'],
            $totals['matched'],
        ));
        $this->line('Attribution required wherever this data appears: ' . $google->attribution());

        return self::SUCCESS;
    }
}
