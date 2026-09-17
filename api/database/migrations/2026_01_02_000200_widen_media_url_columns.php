<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * URLs from real sources do not fit in 255 characters.
 *
 * Wikimedia Commons thumbnail URLs embed the full file name twice and routinely
 * exceed the default varchar length, which surfaced as a truncation error
 * during ingestion rather than as a silently shortened link — but either way
 * the column was simply the wrong size for the data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiences', function (Blueprint $table) {
            $table->text('image_url')->nullable()->change();
            $table->text('content_source_url')->nullable()->change();
        });

        Schema::table('places', function (Blueprint $table) {
            $table->text('website')->nullable()->change();
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->text('hero_image_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('experiences', function (Blueprint $table) {
            $table->string('image_url')->nullable()->change();
            $table->string('content_source_url')->nullable()->change();
        });

        Schema::table('places', function (Blueprint $table) {
            $table->string('website')->nullable()->change();
        });

        Schema::table('destinations', function (Blueprint $table) {
            $table->string('hero_image_url')->nullable()->change();
        });
    }
};
