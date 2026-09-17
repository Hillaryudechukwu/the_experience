<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\ExternalSources\Contracts\RoutingProvider;
use App\Domains\ExternalSources\Contracts\WeatherProvider;
use App\Domains\ExternalSources\Providers\DeepLinkAffiliateProvider;
use App\Domains\ExternalSources\Providers\EstimatorRoutingProvider;
use App\Domains\ExternalSources\Providers\OpenMeteoWeatherProvider;
use App\Domains\ExternalSources\Providers\SandboxTicketProvider;
use App\Domains\ExternalSources\Providers\SeededWeatherProvider;
use App\Domains\ExternalSources\Providers\ViatorProvider;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class ExperienceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class, function ($app) {
            $registry = new ProviderRegistry($app->make(\App\Domains\ExternalSources\Services\ProviderHealthMonitor::class));

            $registry->register($app->make(SandboxTicketProvider::class));
            $registry->register($app->make(DeepLinkAffiliateProvider::class));
            $registry->register($app->make(ViatorProvider::class));

            return $registry;
        });

        $this->app->singleton(WeatherProvider::class, function ($app) {
            return match (config('experience.weather.driver')) {
                'open_meteo' => $app->make(OpenMeteoWeatherProvider::class),
                'seeded' => $app->make(SeededWeatherProvider::class),
                default => throw new InvalidArgumentException('Unknown weather driver: ' . config('experience.weather.driver')),
            };
        });

        $this->app->singleton(RoutingProvider::class, function ($app) {
            return match (config('experience.routing.driver')) {
                'estimator' => $app->make(EstimatorRoutingProvider::class),
                default => throw new InvalidArgumentException('Unknown routing driver: ' . config('experience.routing.driver')),
            };
        });
    }

    public function boot(): void
    {
        $sum = array_sum(config('experience.scoring.weights', []));

        if (abs($sum - 1.0) > 0.0001) {
            throw new InvalidArgumentException("Experience score weights must sum to 1.0, got {$sum}.");
        }
    }
}
