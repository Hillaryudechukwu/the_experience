<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use RuntimeException;

class IdentityTokenRejected extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $subHash = null)
    {
        parent::__construct($message);
    }
}
