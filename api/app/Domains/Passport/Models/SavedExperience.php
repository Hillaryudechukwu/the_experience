<?php

declare(strict_types=1);

namespace App\Domains\Passport\Models;

use App\Domains\Experiences\Models\Experience;
use App\Domains\Shared\Concerns\BelongsToActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedExperience extends Model
{
    use BelongsToActor, HasUuids;

    protected $guarded = [];

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }
}
