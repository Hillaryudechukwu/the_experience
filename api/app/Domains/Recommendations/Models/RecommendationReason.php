<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RecommendationReason extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['contribution' => 'float'];
    }
}
