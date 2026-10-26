<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

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
 */
class PlacePhotoController extends Controller
{
    private const CACHE_SECONDS = 2700;

    public function __construct(private readonly GooglePlacesProvider $google) {}

    public function show(string $name): RedirectResponse
    {
        $cacheKey = 'place-photo:'.$name;
        $uri = Cache::get($cacheKey);

        if (! is_string($uri)) {
            try {
                $uri = $this->google->mediaUriFor($name);
            } catch (Throwable) {
                abort(502, 'The photo could not be fetched right now.');
            }

            abort_if($uri === null, 404);

            Cache::put($cacheKey, $uri, self::CACHE_SECONDS);
        }

        return redirect()->away($uri)->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }
}
