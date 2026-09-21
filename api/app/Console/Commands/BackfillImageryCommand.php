<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Places\Actions\BackfillImagery;
use Illuminate\Console\Command;

/**
 * Fills in the photography the interface is built around.
 *
 * Wikimedia costs nothing and is asked first; Google is billed per request, so
 * the ceiling is stated before anything is spent, exactly as the ratings pass
 * does. A place that neither source can picture keeps its null.
 */
class BackfillImageryCommand extends Command
{
    protected $signature = 'experience:backfill-imagery
                            {destination : Destination slug, or "all"}
                            {--limit=60 : Maximum experiences to look up per destination}
                            {--all-statuses : Include experiences that are not published}';

    protected $description = 'Find licensed photographs for experiences, cities and neighbourhoods that have none';

    public function handle(BackfillImagery $action): int
    {
        $slug = $this->argument('destination');
        $destinations = $slug === 'all'
            ? Destination::orderBy('name')->get()
            : Destination::where('slug', $slug)->get();

        if ($destinations->isEmpty()) {
            $this->error("No destination matches '{$slug}'.");

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');

        $this->warn(sprintf(
            'Up to %d experiences per destination (%d total). Wikimedia is free; anything it cannot picture falls through to a billed Google Places lookup.',
            $limit,
            $limit * $destinations->count(),
        ));

        if ($this->input->isInteractive() && ! $this->confirm('Continue?', true)) {
            $this->line('Nothing was requested.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($destinations as $destination) {
            $result = $action->run($destination, [
                'limit' => $limit,
                'published_only' => ! $this->option('all-statuses'),
            ]);

            $rows[] = [
                $destination->name,
                $result['experiences'],
                $result['destinations'] > 0 ? 'yes' : '—',
                $result['neighbourhoods'],
                $result['missed'],
                $result['failed'],
            ];
        }

        $this->table(['Destination', 'Experiences', 'City hero', 'Areas', 'No photo found', 'Failed'], $rows);

        return self::SUCCESS;
    }
}
