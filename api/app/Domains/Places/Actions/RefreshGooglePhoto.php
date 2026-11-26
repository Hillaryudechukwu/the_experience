<?php

declare(strict_types=1);

namespace App\Domains\Places\Actions;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\Neighbourhood;
use App\Domains\Experiences\Models\Experience;
use App\Domains\ExternalSources\Providers\GooglePlacesProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a Google photo name that Google no longer recognises.
 *
 * A photo's resource name is not permanent: Google answers a stale one with
 * 400 "The photo resource in the request is invalid" and asks for it to be
 * fetched again from the place. The place id is embedded in the name
 * (`places/{placeId}/photos/{photoId}`), so a fresh name needs no new matching
 * and cannot land on the wrong building.
 */
class RefreshGooglePhoto
{
    /** How long a replaced name keeps answering for feeds already on a phone. */
    public const RENAMED_SECONDS = 7 * 86400;

    /** Enough hops for a name replaced several times within RENAMED_SECONDS, and a stop for a loop. */
    private const MAX_RENAME_HOPS = 5;

    public function __construct(private readonly GooglePlacesProvider $google) {}

    /**
     * Fetch a current photo for the place behind a stored reference and write
     * it everywhere the old one was used.
     *
     * @param  string  $stored  The column value, including the "google-photo:" prefix.
     * @return string|null The new stored value, or null when the place has no photo to give.
     */
    public function refresh(string $stored): ?string
    {
        $placeId = self::placeIdOf($stored);

        if ($placeId === null || ! $this->google->isConfigured()) {
            return null;
        }

        $photo = $this->google->photoFor($placeId);

        if ($photo === null) {
            return null;
        }

        $attribution = array_filter([
            'creator' => $photo['creator'],
            'licence' => 'Google',
            'licence_url' => 'https://www.google.com/intl/en/policies/terms/',
            'source_url' => $photo['creator_url'],
        ], fn ($value) => $value !== null);

        /* All or nothing: a half-applied rename would leave some cards on a
           name Google rejects while the alias below points the rest away. */
        DB::transaction(function () use ($stored, $photo, $attribution): void {
            foreach (Experience::query()->where('image_url', $stored)->get() as $experience) {
                $experience->forceFill(['image_url' => $photo['url'], 'image_attribution' => $attribution])->save();
            }

            foreach (Neighbourhood::query()->where('image_url', $stored)->get() as $area) {
                $area->forceFill(['image_url' => $photo['url'], 'image_attribution' => $attribution])->save();
            }

            foreach (Destination::query()->where('hero_image_url', $stored)->get() as $destination) {
                $destination->forceFill(['hero_image_url' => $photo['url'], 'hero_image_attribution' => $attribution])->save();
            }
        });

        if ($photo['url'] !== $stored) {
            Cache::put(
                self::renamedKey(self::nameOf($stored)),
                self::nameOf($photo['url']),
                self::RENAMED_SECONDS,
            );
        }

        return $photo['url'];
    }

    /**
     * The name a replaced photo name now goes by, or the name itself.
     *
     * Feeds already on a phone keep linking to the old name for a while, and
     * the catalogue no longer holds it, so the replacement is remembered here
     * whichever path, a phone's request or the bulk command, made it.
     */
    public static function currentName(string $name): string
    {
        /* A name can be replaced more than once while old links are still
           out there (A to B, then B to C), so follow the aliases to the end. */
        $seen = [$name => true];

        for ($hop = 0; $hop < self::MAX_RENAME_HOPS; $hop++) {
            $renamed = Cache::get(self::renamedKey($name));

            if (! is_string($renamed) || $renamed === '' || isset($seen[$renamed])) {
                break;
            }

            $seen[$renamed] = true;
            $name = $renamed;
        }

        return $name;
    }

    /**
     * Whether a stored value names a place this can ask for a fresh photo.
     *
     * The bulk command checks this first so an unreadable name is reported
     * as one, not as a place that has run out of photos.
     */
    public static function canRefresh(string $stored): bool
    {
        return self::placeIdOf($stored) !== null;
    }

    /** Photo names run past the cache table's 255-character key column, so the key is a digest. */
    private static function renamedKey(string $name): string
    {
        return 'place-photo:renamed:'.hash('sha256', $name);
    }

    private static function nameOf(string $stored): string
    {
        return substr($stored, strlen(GooglePlacesProvider::PHOTO_PREFIX));
    }

    private static function placeIdOf(string $stored): ?string
    {
        $pattern = '#^'.preg_quote(GooglePlacesProvider::PHOTO_PREFIX, '#').'places/([A-Za-z0-9_-]+)/photos/[A-Za-z0-9_-]+$#';

        return preg_match($pattern, $stored, $matches) === 1 ? $matches[1] : null;
    }
}
