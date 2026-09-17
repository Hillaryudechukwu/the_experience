<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Geospatial + fuzzy-match indexes.
 *
 * PostGIS is the production target (spec s18) but this migration uses the
 * cube/earthdistance pair so radius search is genuinely index-backed on any
 * stock PostgreSQL. The query layer goes through GeospatialRepository, so the
 * implementation can be swapped for PostGIS without touching callers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS cube');
        DB::statement('CREATE EXTENSION IF NOT EXISTS earthdistance');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX places_earth_idx ON places USING gist (ll_to_earth(lat::float8, lng::float8))');
        DB::statement('CREATE INDEX places_name_trgm_idx ON places USING gin (normalised_name gin_trgm_ops)');
        DB::statement('CREATE INDEX experiences_title_trgm_idx ON experiences USING gin (lower(title) gin_trgm_ops)');
        DB::statement('CREATE INDEX experiences_summary_trgm_idx ON experiences USING gin (lower(summary) gin_trgm_ops)');
        DB::statement('CREATE INDEX experiences_interest_affinity_idx ON experiences USING gin (interest_affinity jsonb_path_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'experiences_interest_affinity_idx',
            'experiences_summary_trgm_idx',
            'experiences_title_trgm_idx',
            'places_name_trgm_idx',
            'places_earth_idx',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
