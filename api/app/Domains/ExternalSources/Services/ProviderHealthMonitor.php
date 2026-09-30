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
    /**
     * Remembered for the life of the request.
     *
     * isAvailable() is asked once per provider per capability lookup, and the
     * registry consults it on every call it brokers — eight queries on a
     * four-card discovery response, all returning the same rows written
     * milliseconds apart. A circuit that opens mid-request is not a case worth
     * a query per check: the recorders below clear the entry they touch, so
     * anything that actually changes is still seen.
     *
     * @var array<string, bool>
     */
    private array $available = [];

    public function isAvailable(string $provider): bool
    {
        return $this->available[$provider] ??= (function () use ($provider) {
            $health = ProviderHealth::where('provider', $provider)->first();

            return $health === null || ! $health->isCircuitOpen();
        })();
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
        /* Whatever we are about to write may change availability, so the
           memoised answer for this provider stops being trustworthy here. */
        unset($this->available[$provider]);

        return ProviderHealth::firstOrCreate(['provider' => $provider], ['status' => 'unknown']);
    }
}
