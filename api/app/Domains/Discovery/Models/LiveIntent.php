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
