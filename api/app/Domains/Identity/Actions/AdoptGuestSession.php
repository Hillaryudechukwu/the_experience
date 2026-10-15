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

            Journey::where('guest_session_id', $guest->id)->update(['user_id' => $user->id]);
            SavedExperience::where('guest_session_id', $guest->id)->update(['user_id' => $user->id]);
            DevicePushToken::where('guest_session_id', $guest->id)
                ->update(['user_id' => $user->id, 'guest_session_id' => null]);
            NotificationOutbox::where('guest_session_id', $guest->id)
                ->update(['user_id' => $user->id, 'guest_session_id' => null]);
            LiveIntent::where('guest_session_id', $guest->id)
                ->update(['user_id' => $user->id, 'guest_session_id' => null]);
        });
    }
}
