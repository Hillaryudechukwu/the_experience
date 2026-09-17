<?php

declare(strict_types=1);

namespace App\Domains\Trips\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Itinerary extends Model
{
    use HasUuids;

    protected $plural = 'itineraries';

    protected $table = 'itineraries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'generated_at' => 'immutable_datetime',
            'diagnostics' => 'array',
            'is_current' => 'boolean',
            'objective_value' => 'float',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(ItineraryDay::class)->orderBy('date');
    }
}
