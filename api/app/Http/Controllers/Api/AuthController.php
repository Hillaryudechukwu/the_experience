<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Identity\Actions\DeleteTravellerAccount;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Passport\Models\SavedExperience;
use App\Domains\TravellerProfile\Models\TravellerProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $this->adoptGuestData($request, $user);

        return response()->json([
            'token' => $user->createToken('mobile', ['traveller'])->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Those details do not match our records.']);
        }

        $this->adoptGuestData($request, $user);

        return response()->json([
            'token' => $user->createToken('mobile', ['traveller'])->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['status' => 'signed_out']);
    }

    /**
     * Deletes the traveller and everything that describes them (Apple 5.1.1(v)).
     *
     * A registered traveller must re-enter their password. The bearer token is
     * not enough on its own: it lives on the device, and the one case where
     * this endpoint gets called by someone who is not the account holder is a
     * phone that has been picked up by somebody else. A password is the only
     * thing here that the person holding the phone might not have.
     *
     * Guests have no password and no account, so for them this is the same
     * erasure without that step — there is nothing to prove.
     */
    public function destroyAccount(Request $request, DeleteTravellerAccount $action): JsonResponse
    {
        $actor = $this->actor($request);

        if ($actor->isAnonymous()) {
            return response()->json(['message' => 'There is nothing to delete.'], 404);
        }

        if ($actor->userId !== null) {
            $request->validate(['password' => ['required', 'string']]);
            $user = User::find($actor->userId);

            if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
                throw ValidationException::withMessages([
                    'password' => 'That password does not match.',
                ]);
            }
        }

        $result = $action->run($actor);

        return response()->json([
            'status' => 'deleted',
            'deleted' => $result['deleted'],
            /* Named explicitly so the app can tell the traveller what survived
               rather than claiming a clean sweep it did not make. */
            'retained_bookings' => $result['retained_bookings'],
        ]);
    }

    /** Carry everything the traveller did as a guest into their new account. */
    private function adoptGuestData(Request $request, User $user): void
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
        });
    }
}
