<?php

declare(strict_types=1);

namespace App\Domains\Trips\Models;

use App\Domains\Journeys\Models\Journey;
use App\Domains\Shared\Concerns\BelongsToActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use BelongsToActor, HasUuids;

    protected $guarded = [];

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    public function itineraries(): HasMany
    {
        return $this->hasMany(Itinerary::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(TripMember::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(TripVote::class);
    }

    public function currentItinerary(): ?Itinerary
    {
        return $this->itineraries()->where('is_current', true)->latest('version')->first();
    }
}
