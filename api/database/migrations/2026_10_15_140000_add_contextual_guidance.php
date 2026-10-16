<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live intent is the only memory Discover needs from a mood tap or a guide
 * sentence. It does not store coordinates. Social identities let a provider
 * account be recognised without a password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('journey_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mood', 32)->nullable();
            $table->unsignedSmallInteger('window_minutes')->nullable();
            $table->jsonbDefault('categories', '[]');
            $table->jsonbDefault('exclude_categories', '[]');
            $table->boolean('free_only')->default(false);
            $table->string('source', 24);
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['user_id', 'journey_id']);
            $table->index(['guest_session_id', 'journey_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::create('social_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('provider_user_id');
            $table->string('email')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_identities');

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });

        Schema::dropIfExists('live_intents');
    }
};
