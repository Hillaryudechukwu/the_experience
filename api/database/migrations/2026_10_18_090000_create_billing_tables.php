<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription JEM §11 P1 — the access rule, in the database.
 *
 * The month is the entitlement, not the subscription row: a row can be set to
 * pause and still be useless, and a row with auto-renew on can still be
 * expired. Everything `can_use` needs is here — a state and the end of the
 * last successful charge — so no question about access has to call a store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('store', 16);                  // apple|google
            $table->string('product_id', 64);             // wayoa.monthly|wayoa_monthly
            $table->string('original_transaction_id');    // stable across renewals
            $table->string('state', 32)->default('pending');
            /* The end of the last *successful* charge. A grace-extended store
               expiry never lands here; grace is not a month in place. */
            $table->timestamp('paid_until')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('pause_resumes_at')->nullable();   // Google only
            $table->string('environment', 16)->default('production');
            $table->timestamps();

            /* One store subscription, one WAYOA account. A revoked row keeps
               its id for history, so uniqueness is over the live ones. */
            $table->unique(['store', 'original_transaction_id']);
            $table->index(['user_id', 'state']);
            $table->index('paid_until');
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('store', 16);
            /* The store's own id for this delivery. Stores retry, so this is
               what makes a redelivery a no-op rather than a second state move. */
            $table->string('notification_id')->nullable();
            $table->string('type', 64);
            $table->string('payload_hash', 64);
            $table->jsonbDefault('payload', '{}');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['store', 'notification_id']);
            $table->index(['store', 'payload_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
    }
};
