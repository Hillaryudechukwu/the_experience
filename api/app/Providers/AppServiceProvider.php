<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
