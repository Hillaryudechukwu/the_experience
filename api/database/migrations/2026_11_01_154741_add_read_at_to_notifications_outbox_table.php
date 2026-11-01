<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inbox needs to know which notices the traveller has already seen.
 * Delivery status (scheduled/sent) is not the same as read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_outbox', function (Blueprint $table) {
            $table->timestampTz('read_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications_outbox', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
};
