<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\Neighbourhood;
use App\Domains\Experiences\Models\Experience;
use App\Domains\ExternalSources\Providers\GooglePlacesProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A place photo, by the resource name stored instead of an expiring link.
 *
 * Google's signed photo URLs last hours, not weeks. This resolves a current one
 * and redirects to it, caching the answer for less than its lifetime so a
 * popular card costs one Google call per window rather than one per view.
 *
 * Only names we already store are resolved: otherwise this would be an open
 * proxy for Google's media billing.
 */
class PlacePhotoController extends Controller
{
    private const CACHE_SECONDS = 2700;

    public function __construct(private readonly GooglePlacesProvider $google) {}

    public function show(string $name): RedirectResponse
    {
        abort_unless($this->isCataloguePhoto($name), 404);

        $cacheKey = 'place-photo:'.$name;

        try {
            $uri = Cache::remember($cacheKey, self::CACHE_SECONDS, function () use ($name): ?string {
                return $this->google->mediaUriFor($name);
            });
        } catch (Throwable) {
            abort(502, 'The photo could not be fetched right now.');
        }

        abort_if(! is_string($uri) || $uri === '', 404);

        return redirect()->away($uri)->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }

    private function isCataloguePhoto(string $name): bool
    {
        $stored = GooglePlacesProvider::PHOTO_PREFIX.$name;

        return Experience::query()->where('image_url', $stored)->exists()
            || Neighbourhood::query()->where('image_url', $stored)->exists()
            || Destination::query()->where('hero_image_url', $stored)->exists();
    }
}
