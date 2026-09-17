<?php

declare(strict_types=1);

namespace App\Domains\Places\Models;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\Neighbourhood;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Place extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'accessibility' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'rating' => 'float',
            'opening_hours_verified_at' => 'immutable_datetime',
            'rating_verified_at' => 'immutable_datetime',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function neighbourhood(): BelongsTo
    {
        return $this->belongsTo(Neighbourhood::class);
    }

    public function point(): GeoPoint
    {
        return new GeoPoint($this->lat, $this->lng);
    }

    public function openingHoursFreshness(): Freshness
    {
        return new Freshness($this->opening_hours_source, $this->opening_hours_verified_at, Freshness::SEMI_DYNAMIC);
    }

    public function ratingFreshness(): Freshness
    {
        return new Freshness($this->rating_source, $this->rating_verified_at, Freshness::SEMI_DYNAMIC);
    }
}
