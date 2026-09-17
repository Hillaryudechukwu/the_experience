<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token', 64)->unique();
            $table->string('platform', 32)->nullable();
            $table->string('locale', 16)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->foreignId('promoted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('traveller_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->unique()->constrained('guest_sessions')->cascadeOnDelete();
            $table->string('display_name')->nullable();
            $table->string('home_country', 2)->nullable();
            $table->string('travel_pace', 16)->default('moderate');       // slow|moderate|fast
            $table->string('walking_tolerance', 16)->default('medium');   // low|medium|high
            $table->string('budget_level', 16)->default('moderate');      // budget|moderate|premium|luxury
            $table->integer('daily_experience_budget_minor')->nullable();
            $table->char('currency', 3)->default('GBP');
            $table->smallInteger('iconic_vs_local')->default(60);         // 100 = only iconic, 0 = only local
            $table->string('group_size_preference', 16)->default('small');
            $table->boolean('prefers_private_tours')->default(false);
            $table->smallInteger('spontaneity')->default(50);
            $table->smallInteger('food_adventurousness')->default(60);
            $table->jsonb('accessibility')->default('{}');
            $table->jsonb('languages')->default('["en"]');
            $table->string('travel_style_label')->nullable();             // "Curious Explorer"
            $table->timestampTz('recomputed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('traveller_interests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('traveller_profile_id')->constrained()->cascadeOnDelete();
            $table->string('interest', 48);
            $table->smallInteger('weight')->default(50);                  // 0-100
            $table->string('source', 16)->default('explicit');            // explicit|learned
            $table->timestampsTz();
            $table->unique(['traveller_profile_id', 'interest']);
        });

        Schema::create('traveller_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('traveller_profile_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->jsonb('value');
            $table->timestampsTz();
            $table->unique(['traveller_profile_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traveller_preferences');
        Schema::dropIfExists('traveller_interests');
        Schema::dropIfExists('traveller_profiles');
        Schema::dropIfExists('guest_sessions');
    }
};
