<?php

declare(strict_types=1);

namespace App\Domains\Passport\Models;

use App\Domains\Experiences\Models\Experience;
use App\Domains\Shared\Concerns\BelongsToActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompletedExperience extends Model
{
    use BelongsToActor, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }
}
