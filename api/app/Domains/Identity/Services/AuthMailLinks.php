<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Models\User;

/** Deep-link URLs for password reset and email confirmation. */
class AuthMailLinks
{
    public function resetPasswordUrl(string $email, string $token): string
    {
        return $this->origin().'/account/reset-password?'
            .http_build_query(['token' => $token, 'email' => $email]);
    }

    /**
     * The id and the address are both knowable — the id comes back from
     * register — so neither can be the proof. The signature is keyed on the
     * app key, which only this server holds, and it expires.
     */
    public function verifyEmailUrl(User $user): string
    {
        $expires = now()->addMinutes((int) config('auth.verification.expire', 60))->getTimestamp();

        return $this->origin().'/account/verify-email?'
            .http_build_query([
                'id' => $user->getKey(),
                'expires' => $expires,
                'signature' => $this->verificationSignature($user, $expires),
            ]);
    }

    public function verificationSignatureIsValid(User $user, int $expires, string $signature): bool
    {
        return $expires >= now()->getTimestamp()
            && hash_equals($this->verificationSignature($user, $expires), $signature);
    }

    /* The address is part of what is signed, so a link stops working once the
       email on the account changes. */
    private function verificationSignature(User $user, int $expires): string
    {
        return hash_hmac(
            'sha256',
            $user->getKey().'|'.strtolower($user->getEmailForVerification()).'|'.$expires,
            (string) config('app.key'),
        );
    }

    private function origin(): string
    {
        return (string) config('experience.app_origin');
    }
}
