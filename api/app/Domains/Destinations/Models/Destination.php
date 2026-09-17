<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Models;

use App\Domains\Experiences\Models\Experience;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['languages' => 'array', 'lat' => 'float', 'lng' => 'float'];
    }

    public function point(): GeoPoint
    {
        return new GeoPoint($this->lat, $this->lng);
    }

    public function neighbourhoods(): HasMany
    {
        return $this->hasMany(Neighbourhood::class);
    }

    public function essentials(): HasMany
    {
        return $this->hasMany(CityEssential::class)->orderBy('sort');
    }

    public function signatureItems(): HasMany
    {
        return $this->hasMany(DestinationSignatureItem::class)->orderBy('sort');
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(Experience::class);
    }
}
