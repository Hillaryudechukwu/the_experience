<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experience_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 48)->unique();
            $table->string('label');
            $table->string('kind', 24)->default('classification'); // classification|theme|format
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();
        });

        Schema::create('experiences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('destination_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('place_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('neighbourhood_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('summary');
            $table->text('why_it_matters');
            $table->text('best_for')->nullable();
            $table->text('what_to_wear')->nullable();
            $table->text('traveller_tip')->nullable();

            $table->integer('expected_duration_minutes')->default(90);
            $table->integer('min_duration_minutes')->default(45);
            $table->integer('max_duration_minutes')->default(180);

            $table->boolean('is_free')->default(false);
            $table->integer('price_from_minor')->nullable();
            $table->integer('price_to_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('price_source', 48)->nullable();
            $table->timestampTz('price_verified_at')->nullable();

            $table->string('weather_exposure', 24)->default('mixed'); // indoor|outdoor|mixed|weather_sensitive
            $table->string('energy_level', 16)->default('medium');    // low|medium|high
            $table->smallInteger('uniqueness')->default(50);          // 0-100 distinctiveness for the destination
            $table->smallInteger('tourist_concentration')->default(50);
            $table->smallInteger('value_signal')->default(50);
            $table->smallInteger('iconic_weight')->default(50);       // how "must see" for a first-timer

            $table->boolean('requires_booking')->default(false);
            $table->integer('booking_lead_time_hours')->nullable();
            $table->smallInteger('queue_risk')->default(30);

            $table->smallInteger('child_min_age')->nullable();
            $table->smallInteger('child_friendly_score')->default(50);
            $table->smallInteger('romance_score')->default(50);
            $table->smallInteger('social_score')->default(50);
            $table->boolean('has_toilets')->default(true);
            $table->boolean('food_on_site')->default(false);

            $table->jsonbDefault('interest_affinity', '{}');   // {"history":90,"food":20}
            $table->jsonbDefault('mood_affinity', '{}');       // {"romantic":80,"relaxed":40}
            $table->jsonbDefault('accessibility', '{}');
            $table->jsonbDefault('best_time_of_day', '[]');    // ["morning","golden_hour"]
            $table->jsonb('busy_periods')->nullable();
            $table->jsonbDefault('know_before_you_go', '[]');

            $table->string('image_url')->nullable();
            $table->string('data_source', 48)->default('seed_demo');
            $table->timestampTz('verified_at')->nullable();
            $table->string('status', 24)->default('published');
            $table->timestampsTz();

            $table->index(['destination_id', 'status']);
        });

        Schema::create('experience_category_links', function (Blueprint $table) {
            $table->foreignUuid('experience_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experience_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['experience_id', 'experience_category_id'], 'experience_category_links_pk');
        });

        Schema::create('experience_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('from_experience_id')->constrained('experiences')->cascadeOnDelete();
            $table->foreignUuid('to_experience_id')->constrained('experiences')->cascadeOnDelete();
            $table->string('type', 40); // near|walkable_to|often_combined_with|similar_to|alternative_to|better_before|better_after|same_neighbourhood|same_category|rainy_day_alternative|cheaper_alternative|family_alternative
            $table->smallInteger('weight')->default(50);
            $table->string('note')->nullable();
            $table->timestampsTz();
            $table->unique(['from_experience_id', 'to_experience_id', 'type'], 'experience_relationships_unique');
            $table->index(['from_experience_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_relationships');
        Schema::dropIfExists('experience_category_links');
        Schema::dropIfExists('experiences');
        Schema::dropIfExists('experience_categories');
    }
};
