<?php

declare(strict_types=1);

namespace App\Domains\Places\Support;

use App\Domains\ExternalSources\Providers\GooglePlacesProvider;

/** What an image column holds, turned into something a phone can load. */
final class ImageUrl
{
    /**
     * Stored Google photo references become our own redirect endpoint, which
     * resolves a fresh link on request. Everything else is already a URL.
     */
    public static function forClient(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        if (str_starts_with($stored, GooglePlacesProvider::PHOTO_PREFIX)) {
            return url('api/place-photos/'.substr($stored, strlen(GooglePlacesProvider::PHOTO_PREFIX)));
        }

        return $stored;
    }
}
