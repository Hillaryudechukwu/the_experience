<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Services;

use App\Domains\ExternalSources\Contracts\ExperienceProvider;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProviderRegistry
{
    /** @var array<string, ExperienceProvider> */
    private array $providers = [];

    public function __construct(private readonly ProviderHealthMonitor $health) {}

    public function register(ExperienceProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): ?ExperienceProvider
    {
        return $this->providers[$key] ?? null;
    }

    /** @return list<ExperienceProvider> Providers that are configured and not circuit-broken. */
    public function usable(?string $capability = null): array
    {
        return array_values(array_filter(
            $this->providers,
            fn (ExperienceProvider $p) => $p->isConfigured()
                && $this->health->isAvailable($p->key())
                && ($capability === null || in_array($capability, $p->capabilities(), true)),
        ));
    }

    /** @return array<string, array{configured:bool,available:bool,capabilities:list<string>}> */
    public function status(): array
    {
        $status = [];
        foreach ($this->providers as $key => $provider) {
            $status[$key] = [
                'configured' => $provider->isConfigured(),
                'available' => $this->health->isAvailable($key),
                'capabilities' => $provider->capabilities(),
            ];
        }

        return $status;
    }

    /**
     * Run a provider call with health tracking and a fallback value.
     * Failure degrades one capability rather than the whole request.
     */
    public function attempt(string $provider, Closure $call, mixed $fallback = null): mixed
    {
        if (! $this->health->isAvailable($provider)) {
            Log::info('provider.skipped_circuit_open', ['provider' => $provider]);

            return $fallback;
        }

        $started = microtime(true);

        try {
            $result = $call();
            $this->health->recordSuccess($provider, (int) ((microtime(true) - $started) * 1000));

            return $result;
        } catch (Throwable $e) {
            $this->health->recordFailure($provider, $e);

            return $fallback;
        }
    }
}
