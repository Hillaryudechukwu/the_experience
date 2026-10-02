<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_entities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 32);      // place|experience|destination
            $table->uuid('entity_id');
            $table->string('provider', 48);
            $table->string('provider_id');
            $table->string('external_url')->nullable();
            $table->jsonbDefault('metadata', '{}');
            $table->decimal('confidence', 4, 3)->default(1.000);
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_id', 'entity_type'], 'external_entities_provider_unique');
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('provider_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('experience_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('place_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 48);
            $table->string('provider_product_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('product_url')->nullable();
            $table->jsonbDefault('capabilities', '[]');
            $table->text('cancellation_policy')->nullable();
            $table->char('currency', 3)->nullable();
            $table->integer('price_from_minor')->nullable();
            $table->timestampTz('price_verified_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['provider', 'provider_product_id']);
            $table->index('experience_id');
        });

        Schema::create('provider_availability', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_product_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->integer('slots_remaining')->nullable();
            $table->integer('price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestampTz('retrieved_at');
            $table->integer('ttl_seconds')->default(900);
            $table->timestampsTz();
            $table->index(['provider_product_id', 'date']);
        });

        Schema::create('provider_health', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 48)->unique();
            $table->string('status', 24)->default('unknown');     // healthy|degraded|down|unknown
            $table->timestampTz('last_successful_request_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->decimal('failure_rate', 5, 4)->default(0);
            $table->integer('avg_latency_ms')->nullable();
            $table->jsonbDefault('rate_limit_state', '{}');
            $table->timestampTz('circuit_open_until')->nullable();
            $table->timestampsTz();
        });

        Schema::create('provider_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 48);
            $table->string('kind', 40);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->integer('records_seen')->default(0);
            $table->integer('records_written')->default(0);
            $table->integer('failures')->default(0);
            $table->string('status', 24)->default('running');
            $table->timestampsTz();
        });

        Schema::create('provider_sync_failures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_sync_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 48);
            $table->string('provider_id')->nullable();
            $table->string('stage', 40);
            $table->text('message');
            $table->jsonbDefault('context', '{}');
            $table->boolean('resolved')->default(false);
            $table->timestampsTz();
            $table->index(['provider', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_sync_failures');
        Schema::dropIfExists('provider_sync_runs');
        Schema::dropIfExists('provider_health');
        Schema::dropIfExists('provider_availability');
        Schema::dropIfExists('provider_products');
        Schema::dropIfExists('external_entities');
    }
};
