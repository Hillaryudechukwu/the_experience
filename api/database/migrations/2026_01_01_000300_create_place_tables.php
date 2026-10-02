<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('destination_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('neighbourhood_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('normalised_name');            // lowercase, punctuation-stripped: used for resolution
            $table->string('kind', 48)->default('attraction');
            $table->text('address')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('timezone', 64);
            $table->string('phone', 48)->nullable();
            $table->string('website')->nullable();

            // Dynamic facts are always stored with provenance + verification time (spec s22.1).
            $table->jsonb('opening_hours')->nullable();
            $table->string('opening_hours_source', 48)->nullable();
            $table->timestampTz('opening_hours_verified_at')->nullable();

            $table->decimal('rating', 3, 2)->nullable();
            $table->integer('rating_count')->nullable();
            $table->string('rating_source', 48)->nullable();
            $table->timestampTz('rating_verified_at')->nullable();

            $table->jsonbDefault('accessibility', '{}');
            $table->string('accessibility_source', 48)->nullable();

            $table->smallInteger('canonical_confidence')->default(100);
            $table->string('resolution_status', 24)->default('confirmed'); // confirmed|needs_review
            $table->timestampsTz();

            $table->index(['destination_id', 'kind']);
        });

        Schema::create('place_merge_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('place_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 48);
            $table->string('provider_id');
            $table->string('candidate_name');
            $table->decimal('candidate_lat', 10, 7)->nullable();
            $table->decimal('candidate_lng', 10, 7)->nullable();
            $table->decimal('confidence', 4, 3);
            $table->jsonbDefault('signals', '{}');
            $table->string('status', 24)->default('pending'); // pending|merged|rejected
            $table->timestampsTz();
            $table->unique(['provider', 'provider_id', 'place_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_merge_candidates');
        Schema::dropIfExists('places');
    }
};
