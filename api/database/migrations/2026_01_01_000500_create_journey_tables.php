<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('destination_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('reason', 48);                 // holiday|business|conference|honeymoon|...
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->smallInteger('adults')->default(1);
            $table->smallInteger('children')->default(0);
            $table->jsonb('child_ages')->default('[]');
            $table->string('familiarity', 16)->default('never'); // never|once|few|well
            $table->integer('budget_total_minor')->nullable();
            $table->integer('daily_budget_minor')->nullable();
            $table->char('currency', 3)->default('GBP');
            $table->text('mission_text')->nullable();
            $table->jsonb('mission_goals')->default('[]');  // interpreted soft goals
            $table->string('mission_interpreted_by', 32)->nullable();
            $table->jsonb('must_do')->default('[]');
            $table->jsonb('avoid')->default('[]');
            $table->boolean('accessibility_mode')->default(false);
            $table->string('pace_override', 16)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
        });

        Schema::create('journey_anchors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journey_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);   // flight|train|hotel_checkin|conference|wedding|meeting|fixture|concert|restaurant|theatre|prepaid_tour|transfer
            $table->string('title');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->foreignUuid('place_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('address')->nullable();
            $table->integer('buffer_before_minutes')->default(0);
            $table->integer('buffer_after_minutes')->default(0);
            $table->boolean('is_fixed')->default(true);
            $table->string('source', 32)->default('user');
            $table->timestampsTz();
            $table->index(['journey_id', 'starts_at']);
        });

        Schema::create('journey_goals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journey_id')->constrained()->cascadeOnDelete();
            $table->date('day')->nullable();           // null = whole-trip goal; set = day-level mission override
            $table->string('goal_key', 48);
            $table->smallInteger('weight')->default(50);
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->index(['journey_id', 'day']);
        });

        Schema::create('journey_context_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('journey_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('traveller_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('captured_at');
            $table->string('local_time', 32)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('location_precision', 16)->nullable(); // precise|approximate|none
            $table->jsonb('weather')->nullable();
            $table->integer('budget_remaining_minor')->nullable();
            $table->jsonb('companions')->default('{}');
            $table->foreignUuid('active_anchor_id')->nullable()->constrained('journey_anchors')->nullOnDelete();
            $table->integer('window_minutes')->nullable();
            $table->string('surface', 32);
            $table->string('engine_version', 24);
            $table->jsonb('weights')->default('{}');
            $table->timestampsTz();
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_context_snapshots');
        Schema::dropIfExists('journey_goals');
        Schema::dropIfExists('journey_anchors');
        Schema::dropIfExists('journeys');
    }
};
