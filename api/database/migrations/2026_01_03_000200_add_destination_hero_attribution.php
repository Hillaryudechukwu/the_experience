<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The city header carried a photograph with nowhere to record who took it.
 *
 * Every other image in the catalogue stores its licence and photographer
 * beside the URL, because most of this imagery is Creative Commons and naming
 * the author is a condition of showing it at all. The destination hero — the
 * largest picture in the product — was the one that had only a URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->jsonb('hero_image_attribution')->nullable()->after('hero_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn('hero_image_attribution');
        });
    }
};
