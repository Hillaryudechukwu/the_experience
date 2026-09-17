<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Neighbourhood extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['best_for' => 'array', 'lat' => 'float', 'lng' => 'float'];
    }
}
