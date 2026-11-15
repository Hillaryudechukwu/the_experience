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

    public function verifyEmailUrl(User $user): string
    {
        return $this->origin().'/account/verify-email?'
            .http_build_query([
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]);
    }

    private function origin(): string
    {
        return (string) config('experience.app_origin');
    }
}
