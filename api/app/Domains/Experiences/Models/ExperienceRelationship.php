<?php

declare(strict_types=1);

namespace App\Domains\Experiences\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperienceRelationship extends Model
{
    use HasUuids;

    /** Spec s10 — the Experience Graph relationship vocabulary. */
    public const TYPES = [
        'near', 'walkable_to', 'often_combined_with', 'similar_to', 'alternative_to',
        'better_before', 'better_after', 'same_neighbourhood', 'same_category',
        'rainy_day_alternative', 'cheaper_alternative', 'family_alternative',
    ];

    protected $guarded = [];

    public function from(): BelongsTo
    {
        return $this->belongsTo(Experience::class, 'from_experience_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Experience::class, 'to_experience_id');
    }
}
