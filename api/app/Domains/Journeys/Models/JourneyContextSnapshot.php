<?php

declare(strict_types=1);

namespace App\Domains\Journeys\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class JourneyContextSnapshot extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'weather' => 'array',
            'companions' => 'array',
            'weights' => 'array',
            'captured_at' => 'immutable_datetime',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }
}
