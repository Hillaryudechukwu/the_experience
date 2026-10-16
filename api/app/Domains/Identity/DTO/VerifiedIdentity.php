<?php

declare(strict_types=1);

namespace App\Domains\Identity\DTO;

final readonly class VerifiedIdentity
{
    public function __construct(
        public string $provider,
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
    ) {}
}
