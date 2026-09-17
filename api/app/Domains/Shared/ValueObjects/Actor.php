<?php

declare(strict_types=1);

namespace App\Domains\Shared\ValueObjects;

/**
 * Who is acting — a registered user or a guest session.
 *
 * Guest-first browsing (spec s17.4) means almost every write must work without
 * an account, so ownership is expressed as this pair rather than a user id.
 */
final readonly class Actor
{
    public function __construct(
        public ?int $userId = null,
        public ?string $guestSessionId = null,
    ) {}

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    public function isAnonymous(): bool
    {
        return $this->userId === null && $this->guestSessionId === null;
    }

    /** Column => value pair used for ownership filters and inserts. */
    public function ownerAttributes(): array
    {
        return [
            'user_id' => $this->userId,
            'guest_session_id' => $this->userId === null ? $this->guestSessionId : null,
        ];
    }

    public function key(): string
    {
        return $this->userId !== null ? "user:{$this->userId}" : "guest:{$this->guestSessionId}";
    }
}
