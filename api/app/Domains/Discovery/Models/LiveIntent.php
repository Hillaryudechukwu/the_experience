<?php

declare(strict_types=1);

namespace App\Domains\Discovery\Models;

use App\Domains\Shared\Concerns\BelongsToActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LiveIntent extends Model
{
    use BelongsToActor, HasUuids;

    protected $guarded = [];

    /**
     * Derived on every save, so no caller has to remember it.
     *
     * Requiring it at each call site would mean the column is only as correct
     * as the least careful insert, and a wrong key is worse than no key: the
     * row becomes invisible to the lookup while still occupying the slot.
     */
    protected static function booted(): void
    {
        static::saving(function (self $intent): void {
            $intent->owner_key = self::ownerKeyFor(
                $intent->user_id,
                $intent->guest_session_id,
                $intent->journey_id,
            );
        });
    }

    /**
     * The one value the unique index is built on.
     *
     * Composed rather than composite because a unique index over nullable
     * columns constrains nothing in the common case: both PostgreSQL and
     * MySQL count NULLs as distinct, and the ordinary row here has a null
     * journey and a null user or guest. Rendering the three into one non-null
     * string gives the same guarantee on either database.
     */
    public static function ownerKeyFor(?int $userId, ?string $guestSessionId, ?string $journeyId): string
    {
        $owner = $userId !== null ? 'user:'.$userId : 'guest:'.($guestSessionId ?? '-');

        return $owner.'|journey:'.($journeyId ?? '-');
    }

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'exclude_categories' => 'array',
            'free_only' => 'boolean',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
