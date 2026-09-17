<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Services\OutboundHttp;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Facades\Cache;

/**
 * Geocoding and reverse geocoding via Nominatim.
 *
 * Used to turn a typed city name, or a phone's coordinates, into a destination
 * we can actually search. Nominatim's usage policy caps this at one request per
 * second, which OutboundHttp enforces, and results are cached for a week
 * because city coordinates do not move.
 */
class NominatimGeocoder
{
    public function __construct(private readonly OutboundHttp $http) {}

    public function key(): string
    {
        return 'nominatim';
    }

    public function attribution(): string
    {
        return 'Geocoding © OpenStreetMap contributors (ODbL)';
    }

    /** @return list<array{name:string,display_name:string,lat:float,lng:float,country:?string,country_code:?string,osm_id:string}> */
    public function search(string $query, int $limit = 5): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        return Cache::remember(
            'nominatim:search:' . mb_strtolower($query) . ":{$limit}",
            (int) config('experience.place_data.osm.geocode_cache_seconds', 604800),
            function () use ($query, $limit) {
                $response = $this->http
                    ->for($this->key())
                    ->get(rtrim((string) config('experience.place_data.osm.nominatim_url'), '/') . '/search', [
                        'q' => $query,
                        'format' => 'jsonv2',
                        'addressdetails' => 1,
                        'limit' => $limit,
                    ]);

                if ($response->failed()) {
                    return [];
                }

                return collect($response->json() ?? [])
                    ->map(fn (array $row) => [
                        'name' => $row['name'] ?? ($row['display_name'] ?? ''),
                        'display_name' => $row['display_name'] ?? '',
                        'lat' => (float) $row['lat'],
                        'lng' => (float) $row['lon'],
                        'country' => $row['address']['country'] ?? null,
                        'country_code' => isset($row['address']['country_code'])
                            ? mb_strtoupper($row['address']['country_code'])
                            : null,
                        'osm_id' => ($row['osm_type'] ?? 'node') . '/' . ($row['osm_id'] ?? ''),
                    ])
                    ->values()
                    ->all();
            },
        );
    }

    /** @return array{city:?string,country:?string,country_code:?string}|null */
    public function reverse(GeoPoint $point): ?array
    {
        return Cache::remember(
            sprintf('nominatim:reverse:%.3f:%.3f', $point->lat, $point->lng),
            (int) config('experience.place_data.osm.geocode_cache_seconds', 604800),
            function () use ($point) {
                $response = $this->http
                    ->for($this->key())
                    ->get(rtrim((string) config('experience.place_data.osm.nominatim_url'), '/') . '/reverse', [
                        'lat' => $point->lat,
                        'lon' => $point->lng,
                        'format' => 'jsonv2',
                        'zoom' => 10,
                    ]);

                if ($response->failed()) {
                    return null;
                }

                $address = $response->json('address') ?? [];

                return [
                    'city' => $address['city'] ?? $address['town'] ?? $address['municipality'] ?? null,
                    'country' => $address['country'] ?? null,
                    'country_code' => isset($address['country_code']) ? mb_strtoupper($address['country_code']) : null,
                ];
            },
        );
    }
}
