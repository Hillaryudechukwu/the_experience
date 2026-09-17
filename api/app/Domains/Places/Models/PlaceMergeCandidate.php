<?php

declare(strict_types=1);

namespace App\Domains\Places\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlaceMergeCandidate extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['signals' => 'array', 'confidence' => 'float'];
    }
}
