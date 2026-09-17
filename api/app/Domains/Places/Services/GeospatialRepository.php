<?php

declare(strict_types=1);

namespace App\Domains\Places\Services;

use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Indexed radius search.
 *
 * Uses the cube/earthdistance GiST index created in the geospatial migration so
 * the bounding-box step is index-backed rather than a sequential scan. Swap the
 * body for ST_DWithin when PostGIS is available; callers are unaffected.
 */
class GeospatialRepository
{
    /** Restrict a query on a table with lat/lng columns to a radius, ordered by distance. */
    public function withinRadius(Builder $query, string $table, GeoPoint $point, int $metres): Builder
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $this->withinRadiusPortable($query, $table, $point, $metres);
        }

        return $query
            ->whereRaw(
                "earth_box(ll_to_earth(?, ?), ?) @> ll_to_earth({$table}.lat::float8, {$table}.lng::float8)",
                [$point->lat, $point->lng, $metres],
            )
            ->whereRaw(
                "earth_distance(ll_to_earth(?, ?), ll_to_earth({$table}.lat::float8, {$table}.lng::float8)) <= ?",
                [$point->lat, $point->lng, $metres],
            )
            ->orderByRaw(
                "earth_distance(ll_to_earth(?, ?), ll_to_earth({$table}.lat::float8, {$table}.lng::float8)) asc",
                [$point->lat, $point->lng],
            );
    }

    /** Bounding box fallback for non-PostgreSQL drivers (used by some tests). */
    private function withinRadiusPortable(Builder $query, string $table, GeoPoint $point, int $metres): Builder
    {
        $latDelta = $metres / 111_320;
        $lngDelta = $metres / (111_320 * max(0.01, cos(deg2rad($point->lat))));

        return $query
            ->whereBetween("{$table}.lat", [$point->lat - $latDelta, $point->lat + $latDelta])
            ->whereBetween("{$table}.lng", [$point->lng - $lngDelta, $point->lng + $lngDelta]);
    }
}
