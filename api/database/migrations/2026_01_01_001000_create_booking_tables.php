<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 48);
            $table->string('key', 128);
            $table->string('request_hash', 64);
            $table->string('status', 24)->default('in_progress'); // in_progress|completed|failed
            $table->jsonb('response')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['scope', 'key']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 24)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('journey_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 32)->default('draft');
            $table->string('provider', 48);
            $table->string('fulfilment', 24)->default('redirect'); // redirect|native
            $table->string('provider_booking_id')->nullable();
            $table->integer('total_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->text('redirect_url')->nullable();
            $table->text('cancellation_policy')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();
            $table->index(['user_id', 'state']);
        });

        Schema::create('booking_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('experience_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('provider_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->smallInteger('quantity')->default(1);
            $table->integer('unit_price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->string('state', 32)->default('draft');
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();
        });

        Schema::create('booking_travellers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('type', 16)->default('adult');
            $table->smallInteger('age')->nullable();
            $table->timestampsTz();
        });

        Schema::create('booking_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('actor', 48)->default('system');
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('processor', 32)->default('stripe');
            $table->string('processor_reference')->nullable();
            $table->integer('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24)->default('pending');
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained()->cascadeOnDelete();
            $table->integer('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24)->default('pending');
            $table->string('reason')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('booking_transitions');
        Schema::dropIfExists('booking_travellers');
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('idempotency_keys');
    }
};
