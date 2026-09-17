<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CityEssential extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime'];
    }
}
