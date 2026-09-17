<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Models;

use App\Domains\Experiences\Models\Experience;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderProduct extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_active' => 'boolean',
            'price_verified_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }

    public function availability(): HasMany
    {
        return $this->hasMany(ProviderAvailability::class);
    }

    public function priceFrom(): ?Money
    {
        return Money::of($this->price_from_minor, $this->currency);
    }

    public function priceFreshness(): Freshness
    {
        return new Freshness($this->provider, $this->price_verified_at, Freshness::HIGHLY_DYNAMIC);
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, (array) $this->capabilities, true);
    }
}
