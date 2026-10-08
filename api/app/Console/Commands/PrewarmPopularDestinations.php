<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Destinations\Actions\ActivateDestination;
use App\Domains\Destinations\Models\DestinationCandidateDemand;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Destinations\Services\DestinationCandidateToken;
use App\Domains\Destinations\ValueObjects\DestinationCandidate;
use App\Domains\Shared\ValueObjects\Actor;
use Illuminate\Console\Command;

class PrewarmPopularDestinations extends Command
{
    protected $signature = 'destinations:prewarm
        {--limit= : Maximum destinations to activate this run}
        {--minimum-demand= : Minimum aggregate searches required}
        {--force : Run even when predictive warming is disabled}';

    protected $description = 'Queue bounded imports for the most requested uncovered destinations';

    public function handle(ActivateDestination $activate, DestinationCandidateToken $tokens): int
    {
        if (! $this->option('force') && ! config('experience.destination_activation.prewarm.enabled', false)) {
            $this->components->info('Predictive destination warming is disabled.');

            return self::SUCCESS;
        }

        $dailyLimit = (int) config('experience.destination_activation.prewarm.daily_limit', 3);
        $usedToday = DestinationImport::query()
            ->where('requested_by_type', 'guest')
            ->where('requested_by_id', 'system:prewarm')
            ->whereDate('created_at', today())
            ->count();
        $remaining = max(0, $dailyLimit - $usedToday);
        $limit = min((int) ($this->option('limit') ?: config('experience.destination_activation.prewarm.per_run_limit', 3)), $remaining);

        if ($limit === 0) {
            $this->components->info('The predictive warming daily budget is exhausted.');

            return self::SUCCESS;
        }

        $minimumDemand = (int) ($this->option('minimum-demand') ?: config('experience.destination_activation.prewarm.minimum_demand', 10));
        $demands = DestinationCandidateDemand::query()
            ->whereNull('prewarmed_at')
            ->where('search_count', '>=', $minimumDemand)
            ->orderByDesc('search_count')
            ->orderBy('first_seen_at')
            ->limit($limit)
            ->get();

        foreach ($demands as $demand) {
            $candidate = new DestinationCandidate(
                provider: $demand->provider,
                externalId: $demand->external_id,
                name: $demand->name,
                region: $demand->region,
                country: $demand->country,
                countryCode: $demand->country_code,
                lat: $demand->lat,
                lng: $demand->lng,
                kind: $demand->kind,
            );

            $activate->handle($tokens->issue($candidate), new Actor(guestSessionId: 'system:prewarm'));
            $demand->update(['prewarmed_at' => now()]);
            $this->components->info("Queued {$demand->name}, {$demand->country} ({$demand->search_count} searches).");
        }

        if ($demands->isEmpty()) {
            $this->components->info('No uncovered destination has reached the demand threshold.');
        }

        return self::SUCCESS;
    }
}
