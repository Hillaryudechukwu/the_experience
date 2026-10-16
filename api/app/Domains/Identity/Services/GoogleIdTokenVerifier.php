<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

class GoogleIdTokenVerifier extends JwksIdTokenVerifier
{
    public function provider(): string
    {
        return 'google';
    }

    protected function jwksUrl(): string
    {
        return 'https://www.googleapis.com/oauth2/v3/certs';
    }

    protected function issuers(): array
    {
        return ['https://accounts.google.com', 'accounts.google.com'];
    }

    protected function audiences(): array
    {
        return array_values(config('experience.identity.google_client_ids', []));
    }
}
