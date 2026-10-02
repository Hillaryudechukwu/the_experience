<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The schema has to build on MySQL as well as PostgreSQL.
 *
 * MySQL refuses a literal default on a JSON column — "BLOB, TEXT, GEOMETRY or
 * JSON column can't have a default value" — and there were thirty-three of
 * them. The very first migration died on the first one, and because MySQL
 * cannot roll back DDL the deployment was left with some tables created and
 * no record of them, so the next attempt failed with "table users already
 * exists" and never mentioned the real cause.
 *
 * The macro exists so those defaults survive on both. This guards the thing
 * that would silently undo it: someone writing `jsonb(...)->default(...)`
 * again, which passes every test on PostgreSQL.
 */
class SchemaPortabilityTest extends TestCase
{
    public function test_no_migration_puts_a_literal_default_on_a_json_column(): void
    {
        $offenders = [];

        foreach (glob(database_path('migrations/*.php')) as $file) {
            $contents = file_get_contents($file);

            /* json() and jsonb() alike: the column type MySQL produces is the
               same, and so is its refusal of a literal default. */
            if (preg_match_all('/->jsonb?\([^)]*\)\s*(->[a-zA-Z]+\([^)]*\)\s*)*->default\(/', $contents, $matches)) {
                foreach ($matches[0] as $match) {
                    $offenders[] = basename($file) . ': ' . trim(preg_replace('/\s+/', ' ', $match));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "MySQL rejects a literal default on a JSON column. Use \$table->jsonbDefault('col', '{}') instead.",
        );
    }

    /** The macro has to exist, or every one of those migrations is a fatal. */
    public function test_the_portable_json_default_macro_is_registered(): void
    {
        $this->assertTrue(
            \Illuminate\Database\Schema\Blueprint::hasMacro('jsonbDefault'),
            'jsonbDefault is used by the migrations and registered in AppServiceProvider.',
        );
    }

    /** And it must actually produce a working default on this driver. */
    public function test_the_default_is_applied(): void
    {
        Schema::create('portability_probe', function ($table) {
            $table->increments('id');
            $table->jsonbDefault('settings', '{}');
            $table->jsonbDefault('tags', '["en"]');
        });

        DB::table('portability_probe')->insert(['id' => 1]);
        $row = DB::table('portability_probe')->first();

        Schema::drop('portability_probe');

        $this->assertSame([], json_decode((string) $row->settings, true));
        $this->assertSame(['en'], json_decode((string) $row->tags, true));
    }
}
