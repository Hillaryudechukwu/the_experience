<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Destinations\Models\Destination;
use App\Domains\ExternalSources\Contracts\PlaceDataProvider;
use App\Domains\ExternalSources\Contracts\PlaceEnricher;
use App\Domains\Places\Actions\IngestPlaces;
use App\Domains\Places\Services\ExperienceDraftFactory;
use App\Domains\Places\Services\PlaceResolver;
use Illuminate\Console\Command;

class SyncPlacesCommand extends Command
{
    protected $signature = 'experience:sync-places
                            {destination : Destination slug, or "all"}
                            {--radius= : Search radius in metres}
                            {--kinds=* : Restrict to specific kinds, e.g. museum park}
                            {--limit=120 : Maximum records to request}
                            {--no-enrich : Skip Wikipedia and Commons enrichment}
                            {--refresh-derived : Recompute derived values on records this pipeline generated}';

    protected $description = 'Ingest real places from the configured place-data provider';

    public function handle(
        PlaceDataProvider $places,
        PlaceResolver $resolver,
        ExperienceDraftFactory $drafts,
        PlaceEnricher $enricher,
    ): int {
        if (! $places->isConfigured()) {
            $this->error("Place data provider [{$places->key()}] is not configured.");

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

        $action = new IngestPlaces(
            $places,
            $resolver,
            $drafts,
            $this->option('no-enrich') ? null : $enricher,
        );

        foreach ($destinations as $destination) {
            $this->info("Syncing {$destination->name} from {$places->key()}…");

            $result = $action->run($destination, [
                'radius' => $this->option('radius') ? (int) $this->option('radius') : null,
                'kinds' => $this->option('kinds'),
                'limit' => (int) $this->option('limit'),
                'enrich' => ! $this->option('no-enrich'),
                'refresh_derived' => (bool) $this->option('refresh-derived'),
            ]);

            $this->table(
                ['seen', 'created', 'matched', 'needs review', 'enriched', 'failed'],
                [[
                    $result['run']->records_seen,
                    $result['created'],
                    $result['matched'],
                    $result['needs_review'],
                    $result['enriched'],
                    $result['failed'],
                ]],
            );

            if ($result['needs_review'] > 0) {
                $this->warn("{$result['needs_review']} ambiguous matches are waiting in /api/admin/merge-candidates.");
            }
        }

        $this->newLine();
        $this->line('Attribution required wherever this data appears: ' . $places->attribution());

        return self::SUCCESS;
    }
}
