<?php

declare(strict_types=1);

namespace App\Domains\Experiences\Models;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\Neighbourhood;
use App\Domains\ExternalSources\Models\ProviderProduct;
use App\Domains\Places\Models\Place;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\GeoPoint;
use App\Domains\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Experience extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'interest_affinity' => 'array',
            'mood_affinity' => 'array',
            'accessibility' => 'array',
            'best_time_of_day' => 'array',
            'busy_periods' => 'array',
            'know_before_you_go' => 'array',
            'image_attribution' => 'array',
            'is_free' => 'boolean',
            'requires_booking' => 'boolean',
            'has_toilets' => 'boolean',
            'food_on_site' => 'boolean',
            'price_verified_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function neighbourhood(): BelongsTo
    {
        return $this->belongsTo(Neighbourhood::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ExperienceCategory::class, 'experience_category_links');
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(ExperienceRelationship::class, 'from_experience_id');
    }

    public function providerProducts(): HasMany
    {
        return $this->hasMany(ProviderProduct::class);
    }

    public function point(): ?GeoPoint
    {
        return $this->relationLoaded('place') && $this->place
            ? $this->place->point()
            : ($this->place?->point());
    }

    public function priceFrom(): ?Money
    {
        return Money::of($this->price_from_minor, $this->currency);
    }

    public function priceFreshness(): Freshness
    {
        return new Freshness($this->price_source, $this->price_verified_at, Freshness::HIGHLY_DYNAMIC);
    }

    /** Category keys, eager-loaded when available. */
    public function categoryKeys(): array
    {
        return $this->categories->pluck('key')->all();
    }
}
