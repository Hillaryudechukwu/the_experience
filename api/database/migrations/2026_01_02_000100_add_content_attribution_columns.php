<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribution for ingested content.
 *
 * Once descriptions and photographs come from Wikipedia and Wikimedia Commons
 * rather than being written in-house, we are licence-bound to name the source
 * and the photographer. Storing that next to the content is the only way to
 * guarantee it travels with it to the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiences', function (Blueprint $table) {
            $table->string('content_source_name', 64)->nullable()->after('data_source');
            $table->string('content_source_url')->nullable()->after('content_source_name');
            $table->jsonb('image_attribution')->nullable()->after('image_url');
        });

        Schema::table('places', function (Blueprint $table) {
            $table->string('data_source', 48)->default('seed_demo')->after('resolution_status');
            $table->string('attribution')->nullable()->after('data_source');
        });
    }

    public function down(): void
    {
        Schema::table('experiences', function (Blueprint $table) {
            $table->dropColumn(['content_source_name', 'content_source_url', 'image_attribution']);
        });

        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn(['data_source', 'attribution']);
        });
    }
};
