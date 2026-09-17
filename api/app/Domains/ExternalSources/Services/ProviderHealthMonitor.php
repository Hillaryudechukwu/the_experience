<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Services;

use App\Domains\ExternalSources\Models\ProviderHealth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provider reliability + circuit breaking (spec s22).
 *
 * A failing supplier degrades its own capability; it never takes down an
 * unrelated part of the response (acceptance 59).
 */
class ProviderHealthMonitor
{
    public function isAvailable(string $provider): bool
    {
        $health = ProviderHealth::where('provider', $provider)->first();

        return $health === null || ! $health->isCircuitOpen();
    }

    public function recordSuccess(string $provider, int $latencyMs): void
    {
        $health = $this->record($provider);

        $health->fill([
            'status' => 'healthy',
            'last_successful_request_at' => CarbonImmutable::now(),
            'consecutive_failures' => 0,
            'failure_rate' => round($health->failure_rate * 0.8, 4),
            'avg_latency_ms' => $health->avg_latency_ms === null
                ? $latencyMs
                : (int) round(($health->avg_latency_ms * 0.7) + ($latencyMs * 0.3)),
            'circuit_open_until' => null,
        ])->save();
    }

    public function recordFailure(string $provider, Throwable $e): void
    {
        $health = $this->record($provider);
        $failures = $health->consecutive_failures + 1;
        $threshold = (int) config('experience.providers.circuit_breaker.failure_threshold', 5);

        $health->fill([
            'status' => $failures >= $threshold ? 'down' : 'degraded',
            'last_failure_at' => CarbonImmutable::now(),
            'consecutive_failures' => $failures,
            'failure_rate' => round(min(1, ($health->failure_rate * 0.8) + 0.2), 4),
            'circuit_open_until' => $failures >= $threshold
                ? CarbonImmutable::now()->addSeconds((int) config('experience.providers.circuit_breaker.open_seconds', 120))
                : null,
        ])->save();

        Log::warning('provider.failure', [
            'provider' => $provider,
            'consecutive_failures' => $failures,
            'message' => $e->getMessage(),
        ]);
    }

    private function record(string $provider): ProviderHealth
    {
        return ProviderHealth::firstOrCreate(['provider' => $provider], ['status' => 'unknown']);
    }
}
