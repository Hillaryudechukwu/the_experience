<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\Neighbourhood;
use App\Domains\Experiences\Models\Experience;
use App\Domains\ExternalSources\Providers\GooglePlacesProvider;
use App\Domains\Places\Actions\RefreshGooglePhoto;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    /**
     * One refresh attempt per name per window, and the same window for
     * remembering that Google has no bytes for a name, so a dead photo costs
     * one Google call per window rather than one per view.
     */
    private const REFRESH_WINDOW_SECONDS = 600;

    /**
     * Longest a refresh may hold the lock. It must outlast the slowest
     * refresh, a place lookup at OutboundHttp's 20-second timeout tried three
     * times plus the writes, or a waiting request takes the lock mid-refresh
     * and answers 404 for a photo about to work.
     */
    private const REFRESH_LOCK_SECONDS = 90;

    /** How long a request waits for another's refresh before giving up with a 404. */
    private const REFRESH_WAIT_SECONDS = 10;

    public function __construct(
        private readonly GooglePlacesProvider $google,
        private readonly RefreshGooglePhoto $refresh,
    ) {}

    public function show(string $name): RedirectResponse
    {
        $name = $this->catalogueName($name);

        abort_if($name === null, 404);

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

        if ($uri !== null) {
            return $uri;
        }

        /* Misses for the same name queue here, so a request that arrives
           while another is fetching a replacement waits for it and serves the
           new photo, rather than answering 404 for a photo about to work. */
        $mutex = Cache::lock(self::key('refreshing', $name), self::REFRESH_LOCK_SECONDS);

        try {
            $mutex->block(self::REFRESH_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return null;
        }

        try {
            $current = $this->catalogueName($name);

            if ($current !== null && $current !== $name) {
                return $this->mediaUri($current);
            }

            $window = self::key('refresh', $name);

            if (! Cache::add($window, true, self::REFRESH_WINDOW_SECONDS)) {
                return null;
            }

            try {
                $stored = $this->refresh->refresh(GooglePlacesProvider::PHOTO_PREFIX.$name);
            } catch (Throwable $e) {
                Cache::forget($window);

                throw $e;
            }
        } finally {
            $mutex->release();
        }

        if ($stored === null) {
            return null;
        }

        return $this->mediaUri(substr($stored, strlen(GooglePlacesProvider::PHOTO_PREFIX)));
    }

    /**
     * Photo names run to several hundred characters, past the cache table's
     * 255-character key column, so keys carry a digest rather than the name.
     */
    private static function key(string $kind, string $name): string
    {
        return 'place-photo:'.$kind.':'.hash('sha256', $name);
    }

    /**
     * A miss is cached too, as an empty string, since Cache::remember never
     * stores null: otherwise every view of a dead photo would call Google.
     */
    private function mediaUri(string $name): ?string
    {
        $key = self::key('uri', $name);
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $uri = $this->google->mediaUriFor($name);

        Cache::put($key, $uri ?? '', $uri === null ? self::REFRESH_WINDOW_SECONDS : self::CACHE_SECONDS);

        return $uri;
    }

    /**
     * The newest name for this photo that the catalogue still holds, or null
     * when it holds none of them.
     *
     * Following aliases only to names we store keeps this from becoming an
     * open media proxy, and survives a name that has come back round.
     */
    private function catalogueName(string $name): ?string
    {
        foreach (array_reverse(RefreshGooglePhoto::aliasChain($name)) as $candidate) {
            if ($this->isCataloguePhoto($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isCataloguePhoto(string $name): bool
    {
        $stored = GooglePlacesProvider::PHOTO_PREFIX.$name;

        return Experience::query()->where('image_url', $stored)->exists()
            || Neighbourhood::query()->where('image_url', $stored)->exists()
            || Destination::query()->where('hero_image_url', $stored)->exists();
    }
}
