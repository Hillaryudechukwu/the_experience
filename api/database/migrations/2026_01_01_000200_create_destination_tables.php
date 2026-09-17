<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('country');
            $table->char('country_code', 2);
            $table->string('timezone', 64);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->char('currency', 3);
            $table->jsonb('languages')->default('["en"]');
            $table->integer('default_radius_m')->default(8000);
            $table->text('summary')->nullable();
            $table->string('hero_image_url')->nullable();
            $table->timestampsTz();
        });

        Schema::create('neighbourhoods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('destination_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('character')->nullable();
            $table->jsonb('best_for')->default('[]');
            $table->integer('ideal_duration_minutes')->default(120);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->timestampsTz();
            $table->unique(['destination_id', 'slug']);
        });

        Schema::create('city_essentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('destination_id')->constrained()->cascadeOnDelete();
            $table->string('category', 48);
            $table->string('title');
            $table->text('body');
            $table->string('source_name');
            $table->string('source_url')->nullable();
            $table->timestampTz('verified_at');
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();
            $table->index(['destination_id', 'category']);
        });

        Schema::create('destination_signature_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('destination_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('kind', 32);   // food|neighbourhood|tradition|public_space|small_experience
            $table->uuid('experience_id')->nullable();
            $table->smallInteger('sort')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_signature_items');
        Schema::dropIfExists('city_essentials');
        Schema::dropIfExists('neighbourhoods');
        Schema::dropIfExists('destinations');
    }
};
