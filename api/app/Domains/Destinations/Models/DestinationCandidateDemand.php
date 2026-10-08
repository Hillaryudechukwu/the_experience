<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DestinationCandidateDemand extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'prewarmed_at' => 'immutable_datetime',
        ];
    }
}
