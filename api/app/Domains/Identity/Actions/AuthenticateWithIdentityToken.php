<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Exceptions\IdentityTokenRejected;
use App\Domains\Identity\Models\SocialIdentity;
use App\Domains\Identity\Services\AppleIdTokenVerifier;
use App\Domains\Identity\Services\GoogleIdTokenVerifier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a verified provider token into the same Sanctum traveller session
 * email login already issues. The token itself is not stored.
 */
class AuthenticateWithIdentityToken
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $google,
        private readonly AppleIdTokenVerifier $apple,
        private readonly AdoptGuestSession $adopt,
    ) {}

    /**
     * @return array{user: User, created: bool}
     */
    public function authenticate(Request $request, string $provider, string $idToken, ?string $name): array
    {
        $verifier = match ($provider) {
            'google' => $this->google,
            'apple' => $this->apple,
            default => throw ValidationException::withMessages([
                'provider' => 'That sign-in could not be verified. Try again.',
            ]),
        };

        try {
            $verified = $verifier->verify($idToken);
        } catch (IdentityTokenRejected $rejected) {
            Log::warning('identity.token_rejected', [
                'provider' => $provider,
                'reason' => $rejected->getMessage(),
                'sub_hash' => $rejected->subHash,
            ]);

            throw ValidationException::withMessages([
                'id_token' => 'That sign-in could not be verified. Try again.',
            ]);
        }

        $identity = SocialIdentity::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $verified->subject)
            ->first();

        $created = false;

        if ($identity !== null) {
            $user = $identity->user;
            if ($verified->email !== null) {
                $identity->update(['email' => $verified->email]);
            }
        } elseif ($verified->email !== null && $verified->emailVerified) {
            $email = strtolower((string) $verified->email);
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user === null) {
                $user = $this->createUser($email, $name ?? $verified->name);
                $created = true;
            }
            $user->socialIdentities()->create([
                'provider' => $provider,
                'provider_user_id' => $verified->subject,
                'email' => $email,
            ]);
        } else {
            throw ValidationException::withMessages([
                'id_token' => 'That sign-in could not be verified. Try again.',
            ]);
        }

        if ($created && $provider === 'apple' && is_string($name) && trim($name) !== '') {
            $user->update(['name' => trim($name)]);
        }

        $this->adopt->run($request, $user);

        return ['user' => $user->fresh(), 'created' => $created];
    }

    /**
     * A provider-only account proves itself with a fresh token whose subject
     * matches an identity already on that user.
     */
    public function matches(User $user, string $provider, string $idToken): bool
    {
        $verifier = match ($provider) {
            'google' => $this->google,
            'apple' => $this->apple,
            default => null,
        };

        if ($verifier === null) {
            return false;
        }

        try {
            $verified = $verifier->verify($idToken);
        } catch (IdentityTokenRejected $rejected) {
            Log::warning('identity.token_rejected', [
                'provider' => $provider,
                'reason' => $rejected->getMessage(),
                'sub_hash' => $rejected->subHash,
            ]);

            return false;
        }

        return SocialIdentity::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('provider_user_id', $verified->subject)
            ->exists();
    }

    private function createUser(string $email, ?string $name): User
    {
        return User::create([
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : 'Traveller',
            'email' => Str::lower($email),
        ]);
    }
}
