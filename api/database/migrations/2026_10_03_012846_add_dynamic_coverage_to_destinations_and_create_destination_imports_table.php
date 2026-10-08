<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->string('coverage_status', 24)->default('ready')->index();
            $table->string('discovery_source', 32)->default('seed');
            $table->string('discovery_external_id')->nullable();
            $table->string('region')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('last_imported_at')->nullable();
            $table->unique(['discovery_source', 'discovery_external_id']);
        });

        DB::table('destinations')->update([
            'coverage_status' => 'ready',
            'discovery_source' => 'seed',
            'ready_at' => now(),
        ]);

        Schema::create('destination_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('destination_id')->constrained()->cascadeOnDelete();
            $table->string('requested_by_type', 24)->nullable();
            $table->string('requested_by_id')->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->string('stage', 32)->default('queued');
            $table->string('provider_key', 48);
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedInteger('records_seen')->default(0);
            $table->unsignedInteger('places_created')->default(0);
            $table->unsignedInteger('places_matched')->default(0);
            $table->unsignedInteger('places_needing_review')->default(0);
            $table->unsignedInteger('places_failed')->default(0);
            $table->unsignedInteger('experiences_published')->default(0);
            $table->unsignedInteger('experiences_pending_content')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_context')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['destination_id', 'status']);
            $table->index(['requested_by_type', 'requested_by_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_imports');

        Schema::table('destinations', function (Blueprint $table) {
            $table->dropUnique(['discovery_source', 'discovery_external_id']);
            $table->dropColumn([
                'coverage_status',
                'discovery_source',
                'discovery_external_id',
                'region',
                'activated_at',
                'ready_at',
                'last_imported_at',
            ]);
        });
    }
};
