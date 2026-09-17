<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_experiences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('experience_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('journey_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'experience_id']);
            $table->unique(['guest_session_id', 'experience_id']);
        });

        Schema::create('completed_experiences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('experience_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('journey_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('completed_at');
            $table->timestampsTz();
            $table->index(['user_id', 'completed_at']);
        });

        Schema::create('user_experience_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('experience_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('rating');
            $table->boolean('would_recommend')->nullable();
            $table->text('best_part')->nullable();
            $table->text('private_note')->nullable();       // private by default (spec s23.2)
            $table->jsonb('photos')->default('[]');
            $table->boolean('is_public')->default(false);
            $table->timestampsTz();
        });

        Schema::create('passport_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('experience_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);   // experience|city|country|milestone
            $table->string('label');
            $table->timestampTz('earned_at');
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();
        });

        Schema::create('behavioural_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);  // impression|view|save|unsave|book|skip|share|navigate|complete|rate|search|add_to_itinerary|...
            $table->string('subject_type', 32)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->foreignUuid('recommendation_set_id')->nullable()->constrained()->nullOnDelete();
            $table->string('surface', 32)->nullable();
            $table->jsonb('properties')->default('{}');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['type', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavioural_events');
        Schema::dropIfExists('passport_entries');
        Schema::dropIfExists('user_experience_reviews');
        Schema::dropIfExists('completed_experiences');
        Schema::dropIfExists('saved_experiences');
    }
};
