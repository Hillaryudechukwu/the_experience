<?php

declare(strict_types=1);

namespace App\Domains\Passport\Models;

use App\Domains\Shared\Concerns\BelongsToActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PassportEntry extends Model
{
    use BelongsToActor, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array', 'earned_at' => 'immutable_datetime'];
    }
}
