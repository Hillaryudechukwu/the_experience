<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GuestSession extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime'];
    }
}
