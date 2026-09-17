<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\PlaceDataProvider;
use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\Services\OutboundHttp;
use App\Domains\ExternalSources\Support\OpeningHoursParser;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * OpenStreetMap place data via the Overpass API.
 *
 * Real, global, free and keyless, which makes it the honest default for the
 * "one reliable place-data integration" the MVP calls for. The data is ODbL
 * licensed, so attribution() is displayed wherever these places appear.
 *
 * What OSM gives us: names, coordinates, addresses, websites, phone numbers,
 * opening hours and wheelchair tags. What it does not give us: ratings. Those
 * fields stay null, and the scorer treats a missing rating as neutral rather
 * than poor — which is exactly the behaviour we want.
 */
class OverpassPlaceProvider implements PlaceDataProvider
{
    /** Our kind vocabulary mapped onto OSM tag filters. */
    private const KINDS = [
        'attraction' => [['tourism', 'attraction']],
        'museum' => [['tourism', 'museum']],
        'gallery' => [['tourism', 'gallery']],
        'viewpoint' => [['tourism', 'viewpoint']],
        'artwork' => [['tourism', 'artwork']],
        'zoo' => [['tourism', 'zoo'], ['tourism', 'aquarium']],
        'theme_park' => [['tourism', 'theme_park']],
        /* Kept apart from `historic` on purpose: a castle and a street-corner
           memorial plaque are both tagged historic in OSM, but they are not
           remotely the same proposition for a traveller's afternoon. */
        'historic' => [
            ['historic', 'castle'], ['historic', 'ruins'],
            ['historic', 'archaeological_site'], ['historic', 'city_gate'],
        ],
        'memorial' => [['historic', 'monument'], ['historic', 'memorial']],
        'worship' => [['amenity', 'place_of_worship']],
        'market' => [['amenity', 'marketplace']],
        'theatre' => [['amenity', 'theatre'], ['amenity', 'arts_centre']],
        'park' => [['leisure', 'park'], ['leisure', 'garden']],
        'nightlife' => [['amenity', 'bar'], ['amenity', 'pub'], ['amenity', 'nightclub']],
        'food' => [['amenity', 'restaurant'], ['amenity', 'cafe']],
    ];

    private const DEFAULT_KINDS = [
        'attraction', 'museum', 'gallery', 'viewpoint', 'historic',
        'memorial', 'worship', 'market', 'theatre', 'park',
    ];

    public function __construct(private readonly OutboundHttp $http) {}

    public function key(): string
    {
        return 'osm';
    }

    public function isConfigured(): bool
    {
        return (bool) config('experience.place_data.osm.overpass_url');
    }

    public function attribution(): string
    {
        return '© OpenStreetMap contributors (ODbL)';
    }

    public function searchNearby(GeoPoint $centre, int $radiusMetres, array $kinds = [], int $limit = 60): Collection
    {
        $kinds = $kinds === [] ? self::DEFAULT_KINDS : array_values(array_intersect($kinds, array_keys(self::KINDS)));

        if ($kinds === []) {
            return collect();
        }

        /*
         * Queried in small batches rather than as one large union. A fifteen
         * clause query over central London reliably exceeds Overpass's server
         * side timeout, and it answers that with HTTP 200 and an empty result
         * set — a silent failure that looks exactly like "this city has no
         * museums". Smaller queries succeed, cache independently, and degrade
         * one batch at a time instead of all at once.
         */
        $elements = [];

        foreach (array_chunk($kinds, 3) as $batch) {
            $cacheKey = sprintf(
                'osm:nearby:%.3f:%.3f:%d:%s',
                $centre->lat,
                $centre->lng,
                $radiusMetres,
                implode(',', $batch),
            );

            try {
                $payload = Cache::remember(
                    $cacheKey,
                    (int) config('experience.place_data.osm.cache_seconds', 86400),
                    fn () => $this->query($this->buildQuery($centre, $radiusMetres, $batch, $limit)),
                );

                $elements = array_merge($elements, $payload['elements'] ?? []);
            } catch (\Throwable $e) {
                Log::warning('overpass.batch_failed', ['kinds' => $batch, 'message' => $e->getMessage()]);
            }
        }

        return collect($elements)
            ->map(fn (array $element) => $this->toCandidate($element))
            ->filter()
            /* The bounding box is a square around the requested circle, so trim
               the corners back to the radius the caller actually asked for. */
            ->filter(fn (PlaceCandidate $candidate) => $centre->distanceTo($candidate->point) <= $radiusMetres)
            ->unique(fn (PlaceCandidate $candidate) => $candidate->providerId)
            ->sortBy(fn (PlaceCandidate $candidate) => $centre->distanceTo($candidate->point))
            ->take($limit)
            ->values();
    }

    public function getPlace(string $providerId): ?PlaceCandidate
    {
        /* Provider ids are "node/123", "way/456", "relation/789". */
        if (! preg_match('#^(node|way|relation)/(\d+)$#', $providerId, $m)) {
            return null;
        }

        $payload = Cache::remember(
            "osm:place:{$providerId}",
            (int) config('experience.place_data.osm.cache_seconds', 86400),
            fn () => $this->query("[out:json][timeout:30];{$m[1]}({$m[2]});out center tags 1;"),
        );

        $element = $payload['elements'][0] ?? null;

        return $element === null ? null : $this->toCandidate($element);
    }

    /**
     * Builds a bounding-box query over nodes and ways.
     *
     * Two choices here are the difference between a query that answers and one
     * that times out on the public instance:
     *
     *   bbox over around()  a radius filter is evaluated per element, while a
     *                       bounding box is served straight from the spatial
     *                       index.
     *   node/way over nwr   relations are by far the most expensive geometry to
     *                       resolve, and almost nothing a traveller visits is
     *                       mapped only as a relation.
     *
     * The box is a square around the requested circle, so results are trimmed
     * back to the true radius once they arrive.
     */
    private function buildQuery(GeoPoint $centre, int $radiusMetres, array $kinds, int $limit): string
    {
        [$south, $west, $north, $east] = $this->boundingBox($centre, $radiusMetres);

        $clauses = [];

        foreach ($kinds as $kind) {
            foreach (self::KINDS[$kind] as [$tag, $value]) {
                $clauses[] = sprintf('node["%s"="%s"]["name"];way["%s"="%s"]["name"];', $tag, $value, $tag, $value);
            }
        }

        return sprintf(
            '[out:json][timeout:60][bbox:%.5f,%.5f,%.5f,%.5f];(%s);out center tags %d;',
            $south,
            $west,
            $north,
            $east,
            implode('', $clauses),
            $limit,
        );
    }

    /** @return array{0:float,1:float,2:float,3:float} south, west, north, east */
    private function boundingBox(GeoPoint $centre, int $radiusMetres): array
    {
        $latDelta = $radiusMetres / 111_320;
        $lngDelta = $radiusMetres / (111_320 * max(0.01, cos(deg2rad($centre->lat))));

        return [
            $centre->lat - $latDelta,
            $centre->lng - $lngDelta,
            $centre->lat + $latDelta,
            $centre->lng + $lngDelta,
        ];
    }

    private function query(string $overpassQl): array
    {
        $response = $this->http
            ->for($this->key(), 40)
            ->asForm()
            ->post((string) config('experience.place_data.osm.overpass_url'), ['data' => $overpassQl]);

        if ($response->failed()) {
            /* Overpass is a shared, frequently saturated public service. A 429
               or 504 is load, not a bug in the query, and the message should
               say so rather than sending someone hunting through their QL. */
            $reason = match (true) {
                $response->status() === 429 => 'rate limited by the public Overpass instance',
                $response->status() >= 500 => 'the public Overpass instance is busy',
                default => 'request rejected',
            };

            throw new \RuntimeException("Overpass returned {$response->status()} ({$reason}).");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new \RuntimeException('Overpass returned a response that was not JSON.');
        }

        /* Overpass reports server-side timeouts and memory exhaustion as a
           "remark" alongside HTTP 200 and an empty element list. Left
           unchecked that reads as "there is nothing here", which is the most
           misleading answer the system could give. */
        if (isset($body['remark'])) {
            throw new \RuntimeException('Overpass could not complete the query: ' . $body['remark']);
        }

        return $body;
    }

    private function toCandidate(array $element): ?PlaceCandidate
    {
        $tags = $element['tags'] ?? [];
        $name = $tags['name:en'] ?? $tags['name'] ?? null;

        if ($name === null) {
            return null;
        }

        $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
        $lng = $element['lon'] ?? $element['center']['lon'] ?? null;

        if ($lat === null || $lng === null) {
            return null;
        }

        $hours = OpeningHoursParser::parse($tags['opening_hours'] ?? null);

        return new PlaceCandidate(
            provider: $this->key(),
            providerId: sprintf('%s/%s', $element['type'], $element['id']),
            name: $name,
            point: new GeoPoint((float) $lat, (float) $lng),
            address: $this->address($tags),
            kind: $this->kind($tags),
            openingHours: $hours['schedule'] ?? null,
            openingHoursConfidence: $hours['confidence'] ?? null,
            website: $tags['website'] ?? $tags['contact:website'] ?? null,
            phone: $tags['phone'] ?? $tags['contact:phone'] ?? null,
            rating: null,          // OSM does not carry ratings, and we will not invent one
            ratingCount: null,
            accessibility: $this->accessibility($tags),
            externalRefs: array_filter([
                'wikidata' => $tags['wikidata'] ?? null,
                'wikipedia' => $tags['wikipedia'] ?? null,
            ]),
            categories: $this->categories($tags),
            raw: ['tags' => $tags],
        );
    }

    private function address(array $tags): ?string
    {
        $parts = array_filter([
            trim(($tags['addr:housenumber'] ?? '') . ' ' . ($tags['addr:street'] ?? '')),
            $tags['addr:city'] ?? null,
            $tags['addr:postcode'] ?? null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function kind(array $tags): string
    {
        foreach (self::KINDS as $kind => $filters) {
            foreach ($filters as [$tag, $value]) {
                if (($tags[$tag] ?? null) === $value) {
                    return $kind;
                }
            }
        }

        return 'attraction';
    }

    /**
     * Only positively tagged accessibility is reported. An absent tag becomes
     * an absent key, never a false — "we do not know" and "no" are different
     * answers and a wheelchair user deserves to be told which one this is.
     */
    private function accessibility(array $tags): array
    {
        $claims = [];

        if (isset($tags['wheelchair'])) {
            $claims['wheelchair_accessible'] = match ($tags['wheelchair']) {
                'yes' => true,
                'no' => false,
                'limited' => 'limited',
                default => null,
            };
        }

        if (isset($tags['toilets:wheelchair'])) {
            $claims['accessible_toilets'] = $tags['toilets:wheelchair'] === 'yes';
        }

        if (isset($tags['tactile_paving'])) {
            $claims['tactile_paving'] = $tags['tactile_paving'] === 'yes';
        }

        return array_filter($claims, fn ($v) => $v !== null);
    }

    /** @return list<string> */
    private function categories(array $tags): array
    {
        $categories = [];

        foreach (['tourism', 'historic', 'amenity', 'leisure', 'shop'] as $tag) {
            if (isset($tags[$tag])) {
                $categories[] = "{$tag}:{$tags[$tag]}";
            }
        }

        if (($tags['fee'] ?? null) === 'no') {
            $categories[] = 'free';
        }

        return $categories;
    }
}
