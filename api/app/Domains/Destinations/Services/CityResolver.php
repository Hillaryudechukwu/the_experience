<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Services;

use App\Domains\Destinations\Actions\RecordDestinationDemand;
use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\ValueObjects\DestinationCandidate;
use App\Domains\ExternalSources\Providers\NominatimGeocoder;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\GeoPoint;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which city a phone is standing in.
 *
 * A covered city near enough wins outright, without asking the geocoder.
 * Otherwise the point is reverse-geocoded and offered as a candidate, so the
 * app can activate it through the same gate a typed search goes through —
 * standing somewhere is not a way around that gate.
 */
class CityResolver
{
    /** The same places a typed search accepts as somewhere to go. */
    private const TRAVELLABLE = ['city', 'town', 'village', 'municipality'];

    public function __construct(
        private readonly NominatimGeocoder $geocoder,
        private readonly DestinationCandidateToken $candidateTokens,
        private readonly RecordDestinationDemand $demand,
    ) {}

    public function nearest(GeoPoint $point): ?Destination
    {
        $radius = (int) config('experience.explore_city.match_radius_metres', 30000);

        /* A box first so the database does the coarse work; the exact
           distance is only worked out for the few rows inside it. */
        $latSpan = $radius / 111_320;
        $lngSpan = $radius / (111_320 * max(0.01, cos(deg2rad($point->lat))));

        return Destination::query()
            ->whereBetween('lat', [$point->lat - $latSpan, $point->lat + $latSpan])
            ->whereBetween('lng', [$point->lng - $lngSpan, $point->lng + $lngSpan])
            ->get()
            ->map(fn (Destination $destination) => [$destination, $point->distanceTo($destination->point())])
            ->filter(fn (array $pair) => $pair[1] <= $radius)
            ->sortBy(fn (array $pair) => $pair[1])
            ->first()[0] ?? null;
    }

    /**
     * The local time zone at a point we have no destination for.
     *
     * Taken from the zone whose principal city is nearest, within the country
     * when it is known, so "today" and "this weekend" follow the traveller's
     * clock rather than the server's. Worked out from PHP's own zone data, so
     * it costs no request.
     */
    public function timezoneFor(GeoPoint $point, ?string $countryCode = null): string
    {
        $zones = $countryCode === null ? [] : DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, strtoupper($countryCode));
        if ($zones === []) {
            $zones = DateTimeZone::listIdentifiers();
        }

        $best = null;
        $bestDistance = INF;
        foreach ($zones as $zone) {
            $location = (new DateTimeZone($zone))->getLocation();
            if ($location === false || ($location['country_code'] ?? '??') === '??') {
                continue;
            }
            $distance = $point->distanceTo(new GeoPoint((float) $location['latitude'], (float) $location['longitude']));
            if ($distance < $bestDistance) {
                $best = $zone;
                $bestDistance = $distance;
            }
        }

        return $best ?? (string) config('app.timezone');
    }

    /**
     * An uncovered city, ready to activate, or null when the point is not in
     * one (at sea, in a forest) or the geocoder cannot be reached.
     *
     * @return array<string, mixed>|null
     */
    public function candidate(GeoPoint $point, Actor $actor): ?array
    {
        try {
            $found = $this->geocoder->reverse($point);
        } catch (Throwable $e) {
            Log::info('geocode.unavailable', ['reverse' => true, 'message' => $e->getMessage()]);

            return null;
        }

        if ($found === null || $found['country'] === null || $found['country_code'] === null) {
            return null;
        }

        /* Zoom 10 answers with the city itself only in some countries. Elsewhere
           it is a district (Shomolu, in Lagos) or a borough (Manhattan), so the
           city it sits in is looked up by name, as a typed search would. */
        if ($found['city'] === null
            || $found['osm_id'] === null
            || ! in_array($found['kind'] ?? '', self::TRAVELLABLE, true)) {
            $found = $this->containingCity($point, $found);
            if ($found === null) {
                return null;
            }
        }

        $candidate = new DestinationCandidate(
            provider: 'nominatim',
            externalId: $found['osm_id'],
            name: $found['city'],
            region: $found['region'],
            country: $found['country'],
            countryCode: $found['country_code'],
            lat: $found['lat'] ?? $point->lat,
            lng: $found['lng'] ?? $point->lng,
            kind: (string) $found['kind'],
        );
        $this->demand->record($candidate, $actor);

        return [
            'name' => $candidate->name,
            'display_name' => implode(', ', array_filter([$candidate->name, $candidate->region, $candidate->country])),
            'country' => $candidate->country,
            'country_code' => $candidate->countryCode,
            'region' => $candidate->region,
            'lat' => $candidate->lat,
            'lng' => $candidate->lng,
            'covered' => false,
            'kind' => 'destination_candidate',
            'source' => 'nominatim',
            'coverage_status' => 'discoverable',
            'candidate_token' => $this->candidateTokens->issue($candidate),
        ];
    }

    /**
     * The nearest travellable place named in a reverse answer that was not
     * one itself: its city or town, else its state, which in Lagos is the
     * city's own name. Too far away and it is somewhere else that shares it.
     *
     * @param  array<string, mixed>  $found
     * @return array<string, mixed>|null
     */
    private function containingCity(GeoPoint $point, array $found): ?array
    {
        $radius = (int) config('experience.explore_city.candidate_radius_metres', 50000);

        foreach (array_unique(array_filter([$found['city'], $found['region']])) as $name) {
            /* Guarded like the reverse lookup: a rate-limited geocoder means
               no candidate, never a failed screen. A wider page than a typed
               search's five, so a common name does not push the city off it. */
            try {
                $rows = $this->geocoder->search("{$name}, {$found['country']}", 10);
            } catch (Throwable $e) {
                Log::info('geocode.unavailable', ['search' => true, 'message' => $e->getMessage()]);

                return null;
            }

            $match = collect($rows)
                ->filter(fn (array $row) => in_array($row['kind'] ?? '', self::TRAVELLABLE, true)
                    && ($row['country_code'] ?? null) === $found['country_code'])
                ->map(fn (array $row) => [$row, $point->distanceTo(new GeoPoint($row['lat'], $row['lng']))])
                ->filter(fn (array $pair) => $pair[1] <= $radius)
                ->sortBy(fn (array $pair) => $pair[1])
                ->first()[0] ?? null;

            if ($match !== null) {
                return [
                    'city' => $match['name'],
                    'country' => $match['country'] ?? $found['country'],
                    'country_code' => $found['country_code'],
                    'region' => $match['region'] ?? $found['region'],
                    'osm_id' => $match['osm_id'],
                    'lat' => $match['lat'],
                    'lng' => $match['lng'],
                    'kind' => $match['kind'],
                ];
            }
        }

        return null;
    }
}
