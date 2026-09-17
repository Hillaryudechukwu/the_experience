<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('status', 24)->default('planning');
            $table->timestampsTz();
        });

        Schema::create('trip_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('role', 24)->default('member');
            $table->timestampsTz();
        });

        Schema::create('trip_votes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('trip_member_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experience_id')->constrained()->cascadeOnDelete();
            $table->string('vote', 16); // must_do|interested|skip
            $table->timestampsTz();
            $table->unique(['trip_member_id', 'experience_id']);
        });

        Schema::create('itineraries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained()->cascadeOnDelete();
            $table->integer('version')->default(1);
            $table->timestampTz('generated_at');
            $table->string('engine_version', 24);
            $table->decimal('objective_value', 10, 3)->default(0);
            $table->jsonb('diagnostics')->default('{}');
            $table->boolean('is_current')->default(true);
            $table->timestampsTz();
            $table->unique(['trip_id', 'version']);
        });

        Schema::create('itinerary_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('itinerary_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('summary')->nullable();
            $table->jsonb('weather')->nullable();
            $table->timestampsTz();
            $table->unique(['itinerary_id', 'date']);
        });

        Schema::create('itinerary_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('itinerary_day_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);   // experience|anchor|travel|meal|rest
            $table->foreignUuid('experience_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('journey_anchor_id')->nullable()->constrained('journey_anchors')->nullOnDelete();
            $table->string('title');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->integer('travel_minutes_from_previous')->default(0);
            $table->string('travel_mode', 24)->nullable();
            $table->boolean('locked')->default(false);
            $table->foreignUuid('booking_id')->nullable();
            $table->smallInteger('score')->nullable();
            $table->text('reason')->nullable();
            $table->integer('sort')->default(0);
            $table->timestampsTz();
            $table->index(['itinerary_day_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary_items');
        Schema::dropIfExists('itinerary_days');
        Schema::dropIfExists('itineraries');
        Schema::dropIfExists('trip_votes');
        Schema::dropIfExists('trip_members');
        Schema::dropIfExists('trips');
    }
};
