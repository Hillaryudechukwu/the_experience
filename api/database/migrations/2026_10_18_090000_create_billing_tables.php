<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
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

            $table->index(['store', 'original_transaction_id']);
            $table->index(['user_id', 'state']);
            $table->index('paid_until');
        });

        /*
         * One store subscription, one WAYOA account — among the live rows.
         *
         * A revoked row keeps its transaction id as history, and Apple reuses
         * that id when the same traveller subscribes again. A unique index
         * over every row would therefore let a refund permanently bar
         * somebody from buying again, so the uniqueness has to exclude the
         * revoked ones. PostgreSQL says that with a partial index; MySQL and
         * MariaDB have none, but treat NULLs as always distinct, so a stored
         * column that is NULL exactly when the row is revoked says the same
         * thing.
         */
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX subscriptions_live_transaction_idx
                 ON subscriptions (store, original_transaction_id)
                 WHERE state <> \'revoked\''
            );
        } else {
            DB::statement(
                "ALTER TABLE subscriptions
                 ADD COLUMN live_transaction_id VARCHAR(255)
                 AS (CASE WHEN state = 'revoked' THEN NULL ELSE original_transaction_id END) STORED"
            );
            DB::statement(
                'CREATE UNIQUE INDEX subscriptions_live_transaction_idx
                 ON subscriptions (store, live_transaction_id)'
            );
        }

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
