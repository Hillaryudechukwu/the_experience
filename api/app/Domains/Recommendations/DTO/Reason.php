<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\DTO;

final readonly class Reason
{
    public function __construct(
        public string $direction,   // positive|negative
        public string $message,
    ) {}

    public static function plus(string $message): self
    {
        return new self('positive', $message);
    }

    public static function minus(string $message): self
    {
        return new self('negative', $message);
    }

    public function toArray(): array
    {
        return ['direction' => $this->direction, 'message' => $this->message];
    }
}
