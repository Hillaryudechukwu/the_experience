<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox already stored a row. It could not say "this booking was already
 * told" or "the last send failed, try again later", so a retry would either
 * spam the traveller or give up. The token table is how a device opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_outbox', function (Blueprint $table) {
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
        });

        Schema::create('device_push_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token', 255)->unique();
            $table->string('platform', 16)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_push_tokens');

        Schema::table('notifications_outbox', function (Blueprint $table) {
            $table->dropColumn(['idempotency_key', 'attempts', 'last_error']);
        });
    }
};
