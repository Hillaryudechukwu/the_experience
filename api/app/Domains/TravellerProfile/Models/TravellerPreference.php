<?php

declare(strict_types=1);

namespace App\Domains\TravellerProfile\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TravellerPreference extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
