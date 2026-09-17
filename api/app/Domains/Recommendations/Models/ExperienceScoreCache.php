<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExperienceScoreCache extends Model
{
    use HasUuids;

    protected $table = 'experience_score_cache';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['components' => 'array', 'expires_at' => 'immutable_datetime'];
    }
}
