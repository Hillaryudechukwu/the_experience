<?php

declare(strict_types=1);

namespace App\Domains\TravellerProfile\Models;

use App\Domains\Shared\Concerns\BelongsToActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TravellerProfile extends Model
{
    use BelongsToActor, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'accessibility' => 'array',
            'languages' => 'array',
            'recomputed_at' => 'immutable_datetime',
            'prefers_private_tours' => 'boolean',
        ];
    }

    public function interests(): HasMany
    {
        return $this->hasMany(TravellerInterest::class);
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(TravellerPreference::class);
    }

    /** interest key => 0-100 weight */
    public function interestVector(): array
    {
        return $this->interests->pluck('weight', 'interest')->map(fn ($w) => (int) $w)->all();
    }
}
