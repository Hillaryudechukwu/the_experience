<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Models;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
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
        return [
            'languages' => 'array',
            'hero_image_attribution' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'coverage_status' => DestinationCoverageStatus::class,
            'activated_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'last_imported_at' => 'immutable_datetime',
        ];
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

    public function imports(): HasMany
    {
        return $this->hasMany(DestinationImport::class);
    }
}
