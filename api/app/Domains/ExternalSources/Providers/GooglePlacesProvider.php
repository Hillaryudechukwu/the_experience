<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\PlaceDataProvider;
use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\Services\OutboundHttp;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Google Places (New) API.
 *
 * The specification names this as the preferred place-data source, and it
 * brings the one thing OpenStreetMap cannot: ratings with meaningful volume,
 * which the quality component can actually use.
 *
 * Inert until GOOGLE_PLACES_API_KEY is set, at which point it becomes the place
 * data driver. Field masks are explicit because Google bills per field group —
 * asking for everything on every call is a real cost, not just untidy.
 *
 * Note on caching: Google's terms restrict how long most Places content may be
 * stored, so responses are cached briefly for request coalescing rather than
 * retained, and place ids (which may be cached indefinitely) are what we keep
 * in external_entities.
 */
class GooglePlacesProvider implements PlaceDataProvider
{
    private const BASE = 'https://places.googleapis.com/v1';

    private const FIELDS = 'places.id,places.displayName,places.formattedAddress,places.location,'
        . 'places.types,places.rating,places.userRatingCount,places.regularOpeningHours,'
        . 'places.websiteUri,places.internationalPhoneNumber,places.accessibilityOptions';

    /**
     * Our kind vocabulary mapped onto Google's place types.
     *
     * Order matters: a place is usually tagged with several types, and
     * `tourist_attraction` is Google's catch-all that sits alongside the
     * specific one. Matching it first would file every castle and museum as a
     * generic attraction, which then drives the wrong duration, exposure and
     * interest affinity downstream. The catch-all is therefore checked last.
     */
    private const KINDS = [
        'museum' => ['museum'],
        'gallery' => ['art_gallery'],
        'historic' => ['historical_landmark'],
        'worship' => ['church', 'mosque', 'synagogue', 'hindu_temple'],
        'market' => ['market'],
        'theatre' => ['performing_arts_theater'],
        'park' => ['park', 'national_park'],
        'zoo' => ['zoo', 'aquarium'],
        'nightlife' => ['bar', 'night_club'],
        'food' => ['restaurant', 'cafe'],
        'attraction' => ['tourist_attraction'],
    ];

    public function __construct(private readonly OutboundHttp $http) {}

    public function key(): string
    {
        return 'google_places';
    }

    public function isConfigured(): bool
    {
        return (bool) config('experience.place_data.google.api_key');
    }

    public function attribution(): string
    {
        return 'Place data © Google';
    }

    public function searchNearby(GeoPoint $centre, int $radiusMetres, array $kinds = [], int $limit = 60): Collection
    {
        $types = $this->includedTypes($kinds);

        $payload = Cache::remember(
            sprintf('google:nearby:%.3f:%.3f:%d:%s', $centre->lat, $centre->lng, $radiusMetres, implode(',', $types)),
            (int) config('experience.place_data.google.cache_seconds', 900),
            function () use ($centre, $radiusMetres, $types, $limit) {
                $response = $this->http
                    ->for($this->key())
                    ->withHeaders([
                        'X-Goog-Api-Key' => (string) config('experience.place_data.google.api_key'),
                        'X-Goog-FieldMask' => self::FIELDS,
                    ])
                    ->post(self::BASE . '/places:searchNearby', [
                        'includedTypes' => $types,
                        'maxResultCount' => min(20, $limit),
                        'locationRestriction' => [
                            'circle' => [
                                'center' => ['latitude' => $centre->lat, 'longitude' => $centre->lng],
                                'radius' => min(50000, $radiusMetres),
                            ],
                        ],
                    ]);

                if ($response->failed()) {
                    throw new \RuntimeException("Google Places returned {$response->status()}: " . $response->body());
                }

                return $response->json() ?? [];
            },
        );

        return collect($payload['places'] ?? [])
            ->map(fn (array $place) => $this->toCandidate($place))
            ->filter()
            ->values();
    }

    /**
     * Text search, used to find a known place's Google counterpart.
     *
     * The field mask is deliberately narrower than the nearby-search one:
     * ratings sit in Google's most expensive SKU tier, so this asks for the
     * identity fields plus the two things OpenStreetMap cannot give us, and
     * nothing else.
     *
     * @return Collection<int, PlaceCandidate>
     */
    public function findByText(string $query, GeoPoint $bias, int $radiusMetres = 400, int $limit = 5): Collection
    {
        $response = $this->http
            ->for($this->key())
            ->withHeaders([
                'X-Goog-Api-Key' => (string) config('experience.place_data.google.api_key'),
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.location,places.types,'
                    . 'places.rating,places.userRatingCount,places.accessibilityOptions,'
                    /* Same billing tier as the rating, so this costs nothing
                       extra and closes the larger gap: over half the ingested
                       places have no opening hours at all, and businesses keep
                       their Google listing far more current than their OSM one. */
                    . 'places.regularOpeningHours',
            ])
            ->post(self::BASE . '/places:searchText', [
                'textQuery' => $query,
                'maxResultCount' => $limit,
                'locationBias' => [
                    'circle' => [
                        'center' => ['latitude' => $bias->lat, 'longitude' => $bias->lng],
                        'radius' => $radiusMetres,
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Google Places text search returned {$response->status()}: " . mb_substr($response->body(), 0, 200));
        }

        return collect($response->json('places') ?? [])
            ->map(fn (array $place) => $this->toCandidate($place))
            ->filter()
            ->values();
    }

    public function getPlace(string $providerId): ?PlaceCandidate
    {
        $response = $this->http
            ->for($this->key())
            ->withHeaders([
                'X-Goog-Api-Key' => (string) config('experience.place_data.google.api_key'),
                'X-Goog-FieldMask' => str_replace('places.', '', self::FIELDS),
            ])
            ->get(self::BASE . '/places/' . $providerId);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new \RuntimeException("Google Places returned {$response->status()}.");
        }

        return $this->toCandidate($response->json() ?? []);
    }

    /**
     * A photograph of a place, with the attribution Google's terms require.
     *
     * Two calls, because Google separates the photo reference from the bytes.
     * `skipHttpRedirect` is the important flag: without it the media endpoint
     * answers with a 302, so the only URL we could store is the one carrying
     * our API key — which would then be published to every phone running the
     * app. With it we get the final googleusercontent URL back as JSON, and
     * the key never leaves the server.
     *
     * @return array{url:string,creator:?string,creator_url:?string}|null
     */
    public function photoFor(string $providerId, int $maxWidth = 1600): ?array
    {
        $key = (string) config('experience.place_data.google.api_key');

        $details = $this->http
            ->for($this->key())
            ->withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'photos',
            ])
            ->get(self::BASE . '/places/' . $providerId);

        if ($details->status() === 404) {
            return null;
        }

        if ($details->failed()) {
            throw new \RuntimeException("Google Places photos returned {$details->status()}.");
        }

        $photo = $details->json('photos.0');

        if (! is_array($photo) || ! is_string($photo['name'] ?? null)) {
            return null;
        }

        $media = $this->http
            ->for($this->key())
            ->withHeaders(['X-Goog-Api-Key' => $key])
            ->get(self::BASE . '/' . $photo['name'] . '/media', [
                'maxWidthPx' => $maxWidth,
                'skipHttpRedirect' => 'true',
            ]);

        if ($media->failed()) {
            throw new \RuntimeException("Google photo media returned {$media->status()}.");
        }

        $url = $media->json('photoUri');

        if (! is_string($url) || $url === '') {
            return null;
        }

        $author = $photo['authorAttributions'][0] ?? [];

        return [
            'url' => $url,
            'creator' => is_string($author['displayName'] ?? null) ? $author['displayName'] : null,
            'creator_url' => is_string($author['uri'] ?? null) ? $author['uri'] : null,
        ];
    }

    /** @return list<string> */
    private function includedTypes(array $kinds): array
    {
        $kinds = $kinds === [] ? array_keys(self::KINDS) : array_intersect($kinds, array_keys(self::KINDS));

        $types = [];
        foreach ($kinds as $kind) {
            $types = array_merge($types, self::KINDS[$kind]);
        }

        return array_values(array_unique($types)) ?: ['tourist_attraction'];
    }

    private function toCandidate(array $place): ?PlaceCandidate
    {
        $name = $place['displayName']['text'] ?? null;
        $lat = $place['location']['latitude'] ?? null;
        $lng = $place['location']['longitude'] ?? null;

        if ($name === null || $lat === null || $lng === null) {
            return null;
        }

        return new PlaceCandidate(
            provider: $this->key(),
            providerId: (string) $place['id'],
            name: $name,
            point: new GeoPoint((float) $lat, (float) $lng),
            address: $place['formattedAddress'] ?? null,
            kind: $this->kind($place['types'] ?? []),
            openingHours: $this->openingHours($place['regularOpeningHours'] ?? null),
            openingHoursConfidence: isset($place['regularOpeningHours']) ? 1.0 : null,
            website: $place['websiteUri'] ?? null,
            phone: $place['internationalPhoneNumber'] ?? null,
            rating: isset($place['rating']) ? (float) $place['rating'] : null,
            ratingCount: isset($place['userRatingCount']) ? (int) $place['userRatingCount'] : null,
            accessibility: $this->accessibility($place['accessibilityOptions'] ?? []),
            externalRefs: [],
            categories: array_map(fn (string $t) => "google:{$t}", $place['types'] ?? []),
            raw: [],
        );
    }

    private function kind(array $types): string
    {
        foreach (self::KINDS as $kind => $googleTypes) {
            if (array_intersect($types, $googleTypes) !== []) {
                return $kind;
            }
        }

        return 'attraction';
    }

    /**
     * Google returns periods as weekday numbers with 0 = Sunday. Anything that
     * does not describe a plain weekly pattern is discarded rather than
     * half-translated.
     */
    private function openingHours(?array $hours): ?array
    {
        $periods = $hours['periods'] ?? null;

        if (! is_array($periods) || $periods === []) {
            return null;
        }

        $days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $schedule = [];

        foreach ($periods as $period) {
            $open = $period['open'] ?? null;
            $close = $period['close'] ?? null;

            if ($open === null || ! isset($open['day'], $open['hour'])) {
                continue;
            }

            /* An open period with no close is a 24-hour day. */
            $key = $days[$open['day']] ?? null;

            if ($key === null) {
                continue;
            }

            $schedule[$key][] = $close === null
                ? ['00:00', '23:59']
                : [
                    sprintf('%02d:%02d', $open['hour'], $open['minute'] ?? 0),
                    sprintf('%02d:%02d', $close['hour'] ?? 23, $close['minute'] ?? 59),
                ];
        }

        return $schedule === [] ? null : $schedule;
    }

    private function accessibility(array $options): array
    {
        $claims = [];

        if (array_key_exists('wheelchairAccessibleEntrance', $options)) {
            $claims['wheelchair_accessible'] = (bool) $options['wheelchairAccessibleEntrance'];
        }
        if (array_key_exists('wheelchairAccessibleRestroom', $options)) {
            $claims['accessible_toilets'] = (bool) $options['wheelchairAccessibleRestroom'];
        }
        if (array_key_exists('wheelchairAccessibleSeating', $options)) {
            $claims['accessible_seating'] = (bool) $options['wheelchairAccessibleSeating'];
        }

        return $claims;
    }
}
