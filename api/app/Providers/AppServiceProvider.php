<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * A JSON column with a default, on both databases.
         *
         * PostgreSQL takes `default '{}'` happily. MySQL refuses it outright —
         * "BLOB, TEXT, GEOMETRY or JSON column can't have a default value" —
         * and that one rule stops the very first migration dead, which is how
         * a deployment ends up with half its tables created and no record of
         * them, because MySQL cannot roll back DDL.
         *
         * MySQL does allow an *expression* default from 8.0.13, written with
         * parentheses. Laravel's grammar will not produce that from a string,
         * but it passes an Expression through untouched, so the parentheses
         * can be supplied here. The schema ends up semantically identical on
         * both, which is the point: the alternative is dropping the default
         * and teaching every model and every raw insert to cope with null.
         */
        Blueprint::macro('jsonbDefault', function (string $column, string $default) {
            /** @var Blueprint $this */
            $definition = $this->jsonb($column);

            return DB::getDriverName() === 'mysql'
                ? $definition->default(DB::raw("('{$default}')"))
                : $definition->default($default);
        });

        /* Guests are rate limited by their session token, not just by IP:
           a hotel or conference wifi puts hundreds of travellers behind one address. */
        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->id
                ? 'user:' . $request->user()->id
                : 'guest:' . ($request->header('X-Guest-Token') ?: $request->ip());

            return [
                Limit::perMinute(120)->by($key),
                Limit::perMinute(300)->by($request->ip()),
            ];
        });
    }
}
