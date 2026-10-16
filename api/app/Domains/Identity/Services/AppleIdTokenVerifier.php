<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

class AppleIdTokenVerifier extends JwksIdTokenVerifier
{
    public function provider(): string
    {
        return 'apple';
    }

    protected function jwksUrl(): string
    {
        return 'https://appleid.apple.com/auth/keys';
    }

    protected function issuers(): array
    {
        return ['https://appleid.apple.com'];
    }

    protected function audiences(): array
    {
        return array_values(config('experience.identity.apple_client_ids', []));
    }
}
