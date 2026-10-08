<?php

declare(strict_types=1);

use App\Domains\ExternalSources\Contracts\PlaceDataProvider;
use App\Domains\ExternalSources\Contracts\PlaceEnricher;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\DestinationController;
use App\Http\Controllers\Api\DestinationImportController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\ExperienceController;
use App\Http\Controllers\Api\ItineraryItemController;
use App\Http\Controllers\Api\JourneyController;
use App\Http\Controllers\Api\PassportController;
use App\Http\Controllers\Api\PrivacyController;
use App\Http\Controllers\Api\TravellerProfileController;
use App\Http\Controllers\Api\TripController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Interlude — API surface (spec s21).
 *
 * Everything here works for a guest: the ResolveActor middleware mints a guest
 * session on first contact and returns it in X-Guest-Token. Registering later
 * adopts whatever the guest already did.
 */

Route::middleware(['guest.actor', 'throttle:api'])->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    /* Guests included: the erasure is the same, they simply have no password
       to re-enter and no user row at the end of it. */
    Route::delete('auth/account', [AuthController::class, 'destroyAccount']);

    Route::get('traveller/profile', [TravellerProfileController::class, 'show']);
    Route::patch('traveller/profile', [TravellerProfileController::class, 'update']);
    Route::get('traveller/experience-dna', [TravellerProfileController::class, 'dna']);

    Route::post('journeys', [JourneyController::class, 'store']);
    Route::get('journeys/{journey}', [JourneyController::class, 'show']);
    Route::patch('journeys/{journey}', [JourneyController::class, 'update']);
    Route::post('journeys/{journey}/mission', [JourneyController::class, 'mission']);
    Route::post('journeys/{journey}/anchors', [JourneyController::class, 'storeAnchor']);
    Route::delete('journeys/{journey}/anchors/{anchor}', [JourneyController::class, 'destroyAnchor']);

    Route::get('destinations', [DestinationController::class, 'index']);
    Route::post('destinations/activate', [DestinationController::class, 'activate'])->middleware('throttle:6,1');
    Route::get('destination-imports/{import}', [DestinationImportController::class, 'show'])->middleware('throttle:30,1');
    Route::post('destination-imports/{import}/retry', [DestinationImportController::class, 'retry'])->middleware('throttle:10,1');
    Route::get('destinations/{destination}', [DestinationController::class, 'show']);

    Route::post('discovery/now', [DiscoveryController::class, 'now']);
    Route::post('discovery/time-boxed', [DiscoveryController::class, 'timeBoxed']);
    Route::post('discovery/mood', [DiscoveryController::class, 'mood']);
    Route::post('discovery/search', [DiscoveryController::class, 'search']);
    Route::post('discovery/surprise-me', [DiscoveryController::class, 'surpriseMe']);

    Route::get('experiences/saved', [ExperienceController::class, 'saved']);
    Route::get('experiences/{experience}', [ExperienceController::class, 'show']);
    Route::get('experiences/{experience}/availability', [ExperienceController::class, 'availability']);
    Route::get('experiences/{experience}/offers', [ExperienceController::class, 'offers']);
    Route::post('experiences/{experience}/save', [ExperienceController::class, 'save']);
    Route::delete('experiences/{experience}/save', [ExperienceController::class, 'unsave']);
    Route::post('experiences/{experience}/complete', [ExperienceController::class, 'complete']);

    Route::post('trips', [TripController::class, 'store']);
    Route::get('trips/{trip}', [TripController::class, 'show']);
    Route::post('trips/{trip}/generate-itinerary', [TripController::class, 'generateItinerary']);
    Route::post('trips/{trip}/replan', [TripController::class, 'replan']);
    Route::post('trips/{trip}/itineraries/{itinerary}/accept', [TripController::class, 'acceptReplan']);

    Route::post('itineraries/{itinerary}/items', [ItineraryItemController::class, 'store']);
    Route::patch('itinerary-items/{item}', [ItineraryItemController::class, 'update']);
    Route::delete('itinerary-items/{item}', [ItineraryItemController::class, 'destroy']);

    Route::get('bookings', [BookingController::class, 'index']);
    Route::post('bookings', [BookingController::class, 'store']);
    Route::get('bookings/{booking}', [BookingController::class, 'show']);
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);

    Route::get('passport', [PassportController::class, 'index']);
    Route::post('passport/journal/{experience}', [PassportController::class, 'journal']);
    Route::get('passport/recap/{journey}', [PassportController::class, 'recap']);

    Route::post('assistant/message', [AssistantController::class, 'message']);
    Route::get('assistant/conversations/{conversation}', [AssistantController::class, 'history']);

    Route::post('events', [AnalyticsController::class, 'store']);

    Route::get('privacy/export', [PrivacyController::class, 'export']);
    Route::delete('privacy/location-history', [PrivacyController::class, 'forgetLocationHistory']);
    Route::delete('privacy/data', [PrivacyController::class, 'destroyAccountData']);
});

/* Operations. Sanctum-authenticated and gated by the admin ability. */
Route::middleware(['guest.actor', 'auth:sanctum', 'ability:admin'])->prefix('admin')->group(function () {
    Route::get('providers', [AdminController::class, 'providers']);
    Route::get('destination-imports', [AdminController::class, 'destinationImports']);
    Route::post('destination-imports/{import}/retry', [AdminController::class, 'retryDestinationImport']);
    Route::get('destination-quality', [AdminController::class, 'destinationQuality']);
    Route::get('sync-failures', [AdminController::class, 'syncFailures']);
    Route::post('sync-failures/{failure}/resolve', [AdminController::class, 'resolveSyncFailure']);
    Route::get('merge-candidates', [AdminController::class, 'mergeCandidates']);
    Route::patch('external-entities/{entity}', [AdminController::class, 'remapProviderEntity']);
    Route::get('recommendation-sets/{set}', [AdminController::class, 'explainRecommendationSet']);
});

/* Liveness plus provider capability, so the app can degrade knowingly. */
Route::get('health', function (
    ProviderRegistry $registry,
    PlaceDataProvider $placeData,
    PlaceEnricher $placeEnricher,
) {
    $heartbeat = (int) Cache::get('health:queue-worker-heartbeat', 0);
    $queueLagSeconds = DB::table('jobs')
        ->whereNull('reserved_at')
        ->min('available_at');
    $queueLagSeconds = $queueLagSeconds === null ? 0 : max(0, now()->timestamp - (int) $queueLagSeconds);

    return response()->json([
        'status' => $heartbeat > now()->subMinutes(3)->timestamp ? 'ok' : 'degraded',
        'engine_version' => config('experience.engine_version'),
        'providers' => $registry->status(),
        'weather_driver' => config('experience.weather.driver'),
        'assistant_driver' => config('experience.assistant.driver'),
        'queue_driver' => config('queue.default'),
        'destination_pipeline' => [
            'activation_enabled' => (bool) config('experience.destination_activation.enabled'),
            'rollout_percentage' => (int) config('experience.destination_activation.rollout_percentage'),
            'place_provider' => $placeData->key(),
            'place_provider_configured' => $placeData->isConfigured(),
            'content_enricher' => $placeEnricher->key(),
            'geocoder_configured' => filled(config('experience.place_data.osm.nominatim_url')),
            'queue_worker_alive' => $heartbeat > now()->subMinutes(3)->timestamp,
            'queue_lag_seconds' => $queueLagSeconds,
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ],
    ]);
});
