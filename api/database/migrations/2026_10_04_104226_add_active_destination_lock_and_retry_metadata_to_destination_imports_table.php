<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destination_imports', function (Blueprint $table) {
            $table->uuid('active_destination_id')->nullable();
            $table->boolean('retryable')->default(false);
            $table->timestampTz('last_heartbeat_at')->nullable();
        });

        DB::table('destination_imports')
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'active_destination_id' => DB::raw('destination_id'),
                'last_heartbeat_at' => DB::raw('COALESCE(started_at, created_at)'),
            ]);

        Schema::table('destination_imports', function (Blueprint $table) {
            $table->unique('active_destination_id', 'destination_imports_one_active_per_destination');
            $table->index(['status', 'last_heartbeat_at'], 'destination_imports_stale_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('destination_imports', function (Blueprint $table) {
            $table->dropIndex('destination_imports_stale_lookup');
            $table->dropUnique('destination_imports_one_active_per_destination');
            $table->dropColumn(['active_destination_id', 'retryable', 'last_heartbeat_at']);
        });
    }
};
