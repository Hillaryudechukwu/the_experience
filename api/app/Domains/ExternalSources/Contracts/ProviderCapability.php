<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Contracts;

/** Spec s9.3 — what a given provider can actually do. */
final class ProviderCapability
{
    public const CONTENT = 'content';
    public const SEARCH = 'search';
    public const LIVE_AVAILABILITY = 'live_availability';
    public const LIVE_PRICING = 'live_pricing';
    public const REDIRECT_BOOKING = 'redirect_booking';
    public const NATIVE_BOOKING = 'native_booking';
    public const CANCELLATION = 'cancellation';
    public const REVIEWS = 'reviews';
    public const IMAGES = 'images';

    public const ALL = [
        self::CONTENT, self::SEARCH, self::LIVE_AVAILABILITY, self::LIVE_PRICING,
        self::REDIRECT_BOOKING, self::NATIVE_BOOKING, self::CANCELLATION,
        self::REVIEWS, self::IMAGES,
    ];
}
