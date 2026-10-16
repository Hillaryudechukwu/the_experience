<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Identity\Actions\AdoptGuestSession;
use App\Domains\Identity\Actions\AuthenticateWithIdentityToken;
use App\Domains\Identity\Actions\DeleteTravellerAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    public function register(Request $request, AdoptGuestSession $adopt): JsonResponse
    {
        /* Fold email case before validation so unique:users,email cannot be
           bypassed by Sam@Example.com vs sam@example.com on Postgres. */
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        /* unique: above is exact-match. A legacy mixed-case row would still
           collide at the mailbox level — reject those explicitly. */
        if (User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'The email has already been taken.',
            ]);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $this->adoptGuestData($request, $user, $adopt);

        return response()->json([
            'token' => $user->createToken('mobile', ['traveller'])->plainTextToken,
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request, AdoptGuestSession $adopt): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($data['email']));

        /* Case-insensitive match so a client that lowercases (or a traveller
           who capitalises the first letter) still finds accounts stored with
           mixed case from older clients or direct inserts. */
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($user === null || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Those details do not match our records.']);
        }

        $this->adoptGuestData($request, $user, $adopt);

        return response()->json([
            'token' => $user->createToken('mobile', ['traveller'])->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    public function google(Request $request, AuthenticateWithIdentityToken $action): JsonResponse
    {
        return $this->identity($request, $action, 'google');
    }

    public function apple(Request $request, AuthenticateWithIdentityToken $action): JsonResponse
    {
        return $this->identity($request, $action, 'apple');
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
    public function destroyAccount(
        Request $request,
        DeleteTravellerAccount $action,
        AuthenticateWithIdentityToken $identities,
    ): JsonResponse {
        $actor = $this->actor($request);

        if ($actor->isAnonymous()) {
            return response()->json(['message' => 'There is nothing to delete.'], 404);
        }

        if ($actor->userId !== null) {
            $user = User::find($actor->userId);
            if ($user === null) {
                return response()->json(['message' => 'There is nothing to delete.'], 404);
            }

            if ($user->password !== null) {
                $request->validate(['password' => ['required', 'string']]);

                if (! Hash::check($request->string('password')->toString(), $user->password)) {
                    throw ValidationException::withMessages([
                        'password' => 'That password does not match.',
                    ]);
                }
            } else {
                $proof = $request->validate([
                    'id_token' => ['required', 'string'],
                    'provider' => ['required', 'in:google,apple'],
                ]);

                if (! $identities->matches($user, $proof['provider'], $proof['id_token'])) {
                    throw ValidationException::withMessages([
                        'id_token' => 'That sign-in could not be verified. Try again.',
                    ]);
                }
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
    private function adoptGuestData(Request $request, User $user, AdoptGuestSession $adopt): void
    {
        $adopt->run($request, $user);
    }

    private function identity(Request $request, AuthenticateWithIdentityToken $action, string $provider): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $result = $action->authenticate($request, $provider, $data['id_token'], $data['name'] ?? null);
        $user = $result['user'];

        return response()->json([
            'token' => $user->createToken('mobile', ['traveller'])->plainTextToken,
            'user' => $this->userPayload($user),
        ], $result['created'] ? 201 : 200);
    }

    /** @return array{id: int, name: string, email: string, providers: list<string>} */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'providers' => $user->socialIdentities()->orderBy('provider')->pluck('provider')->all(),
        ];
    }
}
