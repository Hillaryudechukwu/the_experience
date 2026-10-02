<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journey_context_snapshot_id')->nullable()->constrained('journey_context_snapshots')->nullOnDelete();
            $table->foreignUuid('journey_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('traveller_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('surface', 32);
            $table->jsonbDefault('request', '{}');
            $table->integer('candidates_considered')->default(0);
            $table->integer('generation_ms')->nullable();
            $table->timestampsTz();
        });

        Schema::create('recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recommendation_set_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experience_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('rank');
            $table->smallInteger('score');
            $table->boolean('is_sponsored')->default(false);
            $table->timestampsTz();
            $table->index(['recommendation_set_id', 'rank']);
        });

        Schema::create('recommendation_reasons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recommendation_id')->constrained()->cascadeOnDelete();
            $table->string('component', 48);
            $table->string('direction', 8);       // positive|negative
            $table->decimal('contribution', 6, 3);
            $table->string('message');
            $table->timestampsTz();
        });

        Schema::create('experience_score_cache', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experience_id')->constrained()->cascadeOnDelete();
            $table->string('context_fingerprint', 64);
            $table->smallInteger('score');
            $table->jsonb('components');
            $table->string('engine_version', 24);
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['experience_id', 'context_fingerprint'], 'experience_score_cache_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_score_cache');
        Schema::dropIfExists('recommendation_reasons');
        Schema::dropIfExists('recommendations');
        Schema::dropIfExists('recommendation_sets');
    }
};
