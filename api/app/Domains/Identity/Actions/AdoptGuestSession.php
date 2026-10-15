<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Discovery\Models\LiveIntent;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Notifications\Models\DevicePushToken;
use App\Domains\Notifications\Models\NotificationOutbox;
use App\Domains\Passport\Models\SavedExperience;
use App\Domains\TravellerProfile\Models\TravellerProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Carry everything the traveller did as a guest into their account. */
class AdoptGuestSession
{
    public function run(Request $request, User $user): void
    {
        $token = $request->header('X-Guest-Token');
        if ($token === null) {
            return;
        }

        $guest = GuestSession::where('token', $token)->first();
        if ($guest === null) {
            return;
        }

        DB::transaction(function () use ($guest, $user) {
            $guest->update(['promoted_user_id' => $user->id]);

            if (TravellerProfile::where('user_id', $user->id)->doesntExist()) {
                TravellerProfile::where('guest_session_id', $guest->id)
                    ->update(['user_id' => $user->id, 'guest_session_id' => null]);
            }

            /*
             * The guest id is cleared, not merely overwritten.
             *
             * journeys.guest_session_id and saved_experiences.guest_session_id
             * are both constrained()->cascadeOnDelete(). Leaving them pointing
             * at the old session means the row still dies with it — so the day
             * anything prunes stale guest sessions, and a TTL on that table is
             * the obvious next thing to add, a registered traveller loses their
             * trips, the itineraries hanging off them, and every save. The four
             * tables below already cleared it; these two did not.
             */
            Journey::where('guest_session_id', $guest->id)
                ->update(['user_id' => $user->id, 'guest_session_id' => null]);

            /*
             * Saves are deduplicated first. The table is unique on
             * (user_id, experience_id), so handing the account a guest save of
             * something it already holds raises a constraint violation inside
             * this transaction and takes the whole sign-in down with it — which
             * is reachable today: save something, sign out, save it again as
             * the new guest, sign back in.
             */
            $alreadySaved = SavedExperience::where('user_id', $user->id)->pluck('experience_id');

            if ($alreadySaved->isNotEmpty()) {
                SavedExperience::where('guest_session_id', $guest->id)
                    ->whereIn('experience_id', $alreadySaved)
                    ->delete();
            }

            SavedExperience::where('guest_session_id', $guest->id)
                ->update(['user_id' => $user->id, 'guest_session_id' => null]);
            DevicePushToken::where('guest_session_id', $guest->id)
                ->update(['user_id' => $user->id, 'guest_session_id' => null]);
            NotificationOutbox::where('guest_session_id', $guest->id)
                ->update(['user_id' => $user->id, 'guest_session_id' => null]);
            LiveIntent::where('guest_session_id', $guest->id)
                ->update(['user_id' => $user->id, 'guest_session_id' => null]);
        });
    }
}
