<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forget Google photo links that were stored as URLs.
 *
 * They were Google's short-lived signed links and have all expired: the app
 * showed a placeholder and still credited the photographer. Clearing them lets
 * the imagery backfill find each place again, now storing the photo's name.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('experiences')
            ->where('image_url', 'like', 'https://lh3.googleusercontent.com/%')
            ->update(['image_url' => null, 'image_attribution' => null]);

        DB::table('neighbourhoods')
            ->where('image_url', 'like', 'https://lh3.googleusercontent.com/%')
            ->update(['image_url' => null, 'image_attribution' => null]);

        DB::table('destinations')
            ->where('hero_image_url', 'like', 'https://lh3.googleusercontent.com/%')
            ->update(['hero_image_url' => null, 'hero_image_attribution' => null]);
    }

    public function down(): void
    {
        /* The links were already dead; there is nothing to put back. */
    }
};
