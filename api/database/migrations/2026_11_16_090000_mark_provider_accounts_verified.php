<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Google and Apple only hand over an address they have confirmed, and sign-in
 * refuses one they have not. Accounts that came in that way before email
 * verification existed are verified in every sense but the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('social_identities')
                    ->whereColumn('social_identities.user_id', 'users.id');
            })
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Not reversible: which rows this set is not recorded.
    }
};
