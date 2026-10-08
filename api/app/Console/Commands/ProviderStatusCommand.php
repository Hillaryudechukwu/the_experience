<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\ExternalSources\Contracts\PlaceDataProvider;
use App\Domains\ExternalSources\Contracts\PlaceEnricher;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Prints which commercial providers are ready — without printing secrets.
 *
 * Used by the P4 smoke runbook before a staging or production check, so an
 * operator can see "Viator is on, deeplink is off" without opening .env.
 */
class ProviderStatusCommand extends Command
{
    protected $signature = 'experience:provider-status
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Show configured providers, drivers, and queue health without secrets';

    public function handle(
        ProviderRegistry $registry,
        PlaceDataProvider $placeData,
        PlaceEnricher $placeEnricher,
    ): int {
        $heartbeat = (int) Cache::get('health:queue-worker-heartbeat', 0);
        $queueWorkerAlive = $heartbeat > now()->subMinutes(3)->timestamp;
        $queueLag = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $queueLagSeconds = $queueLag === null ? 0 : max(0, now()->timestamp - (int) $queueLag);

        $providers = $registry->status();
        $nonSandboxConfigured = collect($providers)
            ->except('sandbox')
            ->contains(fn (array $row) => ($row['configured'] ?? false) === true);
        $sandboxConfigured = ($providers['sandbox']['configured'] ?? false) === true;
        $queueDriver = (string) config('queue.default');
        // sync is fine for CI/local; durable drivers need a living worker heartbeat.
        $queueHealthy = $queueDriver === 'sync' || $queueWorkerAlive;

        $payload = [
            'place_provider' => $placeData->key(),
            'place_provider_configured' => $placeData->isConfigured(),
            'content_enricher' => $placeEnricher->key(),
            'weather_driver' => config('experience.weather.driver'),
            'assistant_driver' => config('experience.assistant.driver'),
            'queue_driver' => $queueDriver,
            'queue_worker_alive' => $queueWorkerAlive,
            'queue_lag_seconds' => $queueLagSeconds,
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'providers' => $providers,
            'p4_gates' => [
                'google_or_osm_accepted' => $placeData->isConfigured() || $placeData->key() === 'osm',
                // Prod wants Viator/deeplink; sandbox alone satisfies review/CI per JEM.
                'commercial_fulfilment' => $nonSandboxConfigured || $sandboxConfigured,
                'live_commercial_provider' => $nonSandboxConfigured,
                'assistant_driver_set' => filled(config('experience.assistant.driver')),
                'queue_healthy' => $queueHealthy,
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Place data: '.$placeData->key().($placeData->isConfigured() ? ' (configured)' : ' (not configured)'));
            $this->info('Enricher: '.$placeEnricher->key());
            $this->info('Weather: '.config('experience.weather.driver'));
            $this->info('Assistant: '.config('experience.assistant.driver'));
            $this->info('Queue: '.config('queue.default').($queueWorkerAlive ? ' · worker alive' : ' · worker STALE'));
            $this->newLine();
            $this->table(
                ['Provider', 'Configured', 'Available', 'Capabilities'],
                collect($payload['providers'])->map(fn (array $row, string $key) => [
                    $key,
                    $row['configured'] ? 'yes' : 'no',
                    $row['available'] ? 'yes' : 'no',
                    implode(', ', $row['capabilities']),
                ])->values()->all(),
            );

            $this->newLine();
            $this->line('P4 gates:');
            foreach ($payload['p4_gates'] as $gate => $ok) {
                $this->line(($ok ? '[ok] ' : '[!!] ').$gate);
            }
        }

        // live_commercial_provider is advisory for CI/review (sandbox alone is ok);
        // require it only when APP_ENV=production.
        $required = collect($payload['p4_gates'])
            ->except(app()->environment('production') ? [] : ['live_commercial_provider']);

        return $required->every(fn ($ok) => $ok) ? self::SUCCESS : self::FAILURE;
    }
}
