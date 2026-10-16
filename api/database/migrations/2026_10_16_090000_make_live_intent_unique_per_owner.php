<?php

declare(strict_types=1);

use App\Domains\Discovery\Models\LiveIntent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One live intent per owner and journey, enforced rather than assumed.
 *
 * LiveIntentStore has always documented "one row per actor and journey" and
 * nothing held it to that: the table carried plain indexes, and the lookup
 * took an unordered first(). Two requests arriving together — which Discover
 * does whenever a mood is tapped while the first fetch is still in flight —
 * both found nothing and both inserted. After that the remembered mood
 * alternated between the two rows depending on which the database happened to
 * return, and clearing it only ever cleared one.
 *
 * A composite unique index would not do it. Both PostgreSQL and MySQL treat
 * NULLs as distinct in a unique index, and the ordinary case here is a null
 * journey_id and a null user_id or guest_session_id — so the rows that most
 * need constraining are exactly the ones such an index ignores. A single
 * non-null key column sidesteps that and behaves identically on both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_intents', function (Blueprint $table) {
            $table->string('owner_key', 120)->nullable()->after('id');
        });

        /* Existing rows first, newest wins: an older duplicate is a stale
           answer to a question the traveller has already asked again. */
        $seen = [];
        foreach (LiveIntent::query()->orderByDesc('updated_at')->get() as $row) {
            $key = LiveIntent::ownerKeyFor($row->user_id, $row->guest_session_id, $row->journey_id);

            if (isset($seen[$key])) {
                $row->delete();

                continue;
            }

            $seen[$key] = true;
            DB::table('live_intents')->where('id', $row->id)->update(['owner_key' => $key]);
        }

        Schema::table('live_intents', function (Blueprint $table) {
            $table->string('owner_key', 120)->nullable(false)->change();
            $table->unique('owner_key');
        });
    }

    public function down(): void
    {
        Schema::table('live_intents', function (Blueprint $table) {
            $table->dropUnique(['owner_key']);
            $table->dropColumn('owner_key');
        });
    }
};
