<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\ExternalSources\Contracts\CurrencyProvider;
use App\Domains\ExternalSources\Contracts\PlaceDataProvider;
use App\Domains\ExternalSources\Contracts\PlaceEnricher;
use App\Domains\ExternalSources\Contracts\RoutingProvider;
use App\Domains\ExternalSources\Contracts\WeatherProvider;
use App\Domains\ExternalSources\Providers\DeepLinkAffiliateProvider;
use App\Domains\ExternalSources\Providers\EstimatorRoutingProvider;
use App\Domains\ExternalSources\Providers\FrankfurterCurrencyProvider;
use App\Domains\ExternalSources\Providers\GooglePlacesProvider;
use App\Domains\ExternalSources\Providers\OpenMeteoWeatherProvider;
use App\Domains\ExternalSources\Providers\OsrmRoutingProvider;
use App\Domains\ExternalSources\Providers\OverpassPlaceProvider;
use App\Domains\ExternalSources\Providers\SandboxTicketProvider;
use App\Domains\ExternalSources\Providers\SeededWeatherProvider;
use App\Domains\ExternalSources\Providers\ViatorProvider;
use App\Domains\ExternalSources\Providers\WikimediaEnricher;
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
                'osrm' => $app->make(OsrmRoutingProvider::class),
                'estimator' => $app->make(EstimatorRoutingProvider::class),
                default => throw new InvalidArgumentException('Unknown routing driver: ' . config('experience.routing.driver')),
            };
        });

        /*
         * Place data. OpenStreetMap is the default because it is real, global
         * and needs no key; Google Places takes over when one is configured,
         * bringing the review volume that OSM cannot.
         */
        $this->app->singleton(PlaceDataProvider::class, function ($app) {
            $google = $app->make(GooglePlacesProvider::class);

            if (config('experience.place_data.driver') === 'google' || $google->isConfigured()) {
                return $google;
            }

            return $app->make(OverpassPlaceProvider::class);
        });

        $this->app->singleton(PlaceEnricher::class, fn ($app) => $app->make(WikimediaEnricher::class));

        $this->app->singleton(CurrencyProvider::class, fn ($app) => $app->make(FrankfurterCurrencyProvider::class));
    }

    public function boot(): void
    {
        $sum = array_sum(config('experience.scoring.weights', []));

        if (abs($sum - 1.0) > 0.0001) {
            throw new InvalidArgumentException("Experience score weights must sum to 1.0, got {$sum}.");
        }
    }
}
