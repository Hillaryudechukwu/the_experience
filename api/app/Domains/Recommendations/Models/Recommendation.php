<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Models;

use App\Domains\Experiences\Models\Experience;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recommendation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_sponsored' => 'boolean'];
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }

    public function reasons(): HasMany
    {
        return $this->hasMany(RecommendationReason::class);
    }
}
