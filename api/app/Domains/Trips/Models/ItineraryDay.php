<?php

declare(strict_types=1);

namespace App\Domains\Trips\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItineraryDay extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'weather' => 'array'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItineraryItem::class)->orderBy('starts_at');
    }
}
