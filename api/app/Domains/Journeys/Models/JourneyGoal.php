<?php

declare(strict_types=1);

namespace App\Domains\Journeys\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class JourneyGoal extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['day' => 'immutable_date'];
    }
}
