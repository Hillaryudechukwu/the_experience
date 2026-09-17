<?php

declare(strict_types=1);

namespace App\Domains\Passport\Models;

use App\Domains\Shared\Concerns\BelongsToActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UserExperienceReview extends Model
{
    use BelongsToActor, HasUuids;

    protected $guarded = [];

    /** private_note is hidden by default — journal entries are private (spec s23.2). */
    protected $hidden = ['private_note'];

    protected function casts(): array
    {
        return ['photos' => 'array', 'is_public' => 'boolean', 'would_recommend' => 'boolean'];
    }
}
