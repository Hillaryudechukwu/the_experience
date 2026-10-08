<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Jobs\EnrichDestinationContent;
use App\Domains\Destinations\Models\Destination;
use Illuminate\Console\Command;

class RetryDestinationEnrichment extends Command
{
    protected $signature = 'destinations:retry-enrichment {--limit=20}';

    protected $description = 'Queue independent content enrichment retries for usable destinations';

    public function handle(): int
    {
        $destinations = Destination::query()
            ->whereIn('coverage_status', [
                DestinationCoverageStatus::Failed->value,
                DestinationCoverageStatus::Limited->value,
                DestinationCoverageStatus::Ready->value,
            ])
            ->whereHas('experiences', fn ($query) => $query->where('status', 'needs_content'))
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($destinations as $destination) {
            EnrichDestinationContent::dispatch($destination->id);
        }

        $this->components->info("Queued enrichment for {$destinations->count()} destinations.");

        return self::SUCCESS;
    }
}
