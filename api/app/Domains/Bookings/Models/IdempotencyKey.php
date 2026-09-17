<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['response' => 'array', 'locked_at' => 'immutable_datetime'];
    }
}
