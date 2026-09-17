<?php

declare(strict_types=1);

namespace App\Domains\Shared\Concerns;

use App\Domains\Shared\ValueObjects\Actor;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToActor
{
    public function scopeOwnedBy(Builder $query, Actor $actor): Builder
    {
        if ($actor->userId !== null) {
            return $query->where('user_id', $actor->userId);
        }

        if ($actor->guestSessionId !== null) {
            return $query->whereNull('user_id')->where('guest_session_id', $actor->guestSessionId);
        }

        return $query->whereRaw('1 = 0');
    }
}
