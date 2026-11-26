<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\Neighbourhood;
use App\Domains\Experiences\Models\Experience;
use App\Domains\ExternalSources\Providers\GooglePlacesProvider;
use App\Domains\Places\Actions\RefreshGooglePhoto;
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

    /** How long a replaced name keeps answering for feeds already on a phone. */
    private const RENAMED_SECONDS = 7 * 86400;

    /** One refresh attempt per name per window, so a dead photo costs one Google call, not one per view. */
    private const REFRESH_WINDOW_SECONDS = 600;

    public function __construct(
        private readonly GooglePlacesProvider $google,
        private readonly RefreshGooglePhoto $refresh,
    ) {}

    public function show(string $name): RedirectResponse
    {
        $name = Cache::get(self::key('renamed', $name), $name);

        abort_unless($this->isCataloguePhoto($name), 404);

        try {
            $uri = $this->resolve($name);
        } catch (Throwable) {
            abort(502, 'The photo could not be fetched right now.');
        }

        abort_if(! is_string($uri) || $uri === '', 404);

        return redirect()->away($uri)->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }

    /**
     * A current link for the stored name, replacing the name itself when
     * Google no longer recognises it.
     *
     * Google's photo names are not permanent. Left alone, a name that has gone
     * stale answers 404 for ever and the card shows a placeholder; here the
     * place is asked for a fresh photo and the catalogue is corrected, so the
     * next request, and every other card using it, works.
     */
    private function resolve(string $name): ?string
    {
        $uri = $this->mediaUri($name);

        if (is_string($uri) && $uri !== '') {
            return $uri;
        }

        $lock = self::key('refresh', $name);

        if (! Cache::add($lock, true, self::REFRESH_WINDOW_SECONDS)) {
            return null;
        }

        try {
            $stored = $this->refresh->refresh(GooglePlacesProvider::PHOTO_PREFIX.$name);
        } catch (Throwable $e) {
            Cache::forget($lock);

            throw $e;
        }

        if ($stored === null) {
            return null;
        }

        $fresh = substr($stored, strlen(GooglePlacesProvider::PHOTO_PREFIX));

        Cache::put(self::key('renamed', $name), $fresh, self::RENAMED_SECONDS);

        return $this->mediaUri($fresh);
    }

    /**
     * Photo names run to several hundred characters, past the cache table's
     * 255-character key column, so keys carry a digest rather than the name.
     */
    private static function key(string $kind, string $name): string
    {
        return 'place-photo:'.$kind.':'.hash('sha256', $name);
    }

    private function mediaUri(string $name): ?string
    {
        return Cache::remember(self::key('uri', $name), self::CACHE_SECONDS, function () use ($name): ?string {
            return $this->google->mediaUriFor($name);
        });
    }

    private function isCataloguePhoto(string $name): bool
    {
        $stored = GooglePlacesProvider::PHOTO_PREFIX.$name;

        return Experience::query()->where('image_url', $stored)->exists()
            || Neighbourhood::query()->where('image_url', $stored)->exists()
            || Destination::query()->where('hero_image_url', $stored)->exists();
    }
}
