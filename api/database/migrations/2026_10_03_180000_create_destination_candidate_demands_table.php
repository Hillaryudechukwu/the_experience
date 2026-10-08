<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_candidate_demands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 32);
            $table->string('external_id');
            $table->string('name');
            $table->string('region')->nullable();
            $table->string('country');
            $table->char('country_code', 2);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('kind', 32);
            $table->unsignedInteger('search_count')->default(0);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at')->index();
            $table->timestampTz('prewarmed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'external_id']);
            $table->index(['prewarmed_at', 'search_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_candidate_demands');
    }
};
