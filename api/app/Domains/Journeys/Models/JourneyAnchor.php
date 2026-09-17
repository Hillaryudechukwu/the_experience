<?php

declare(strict_types=1);

namespace App\Domains\Journeys\Models;

use App\Domains\Places\Models\Place;
use App\Domains\Shared\ValueObjects\GeoPoint;
use App\Domains\Shared\ValueObjects\TimeWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JourneyAnchor extends Model
{
    use HasUuids;

    /** Spec s3.4 — commitments that are fixed or expensive to move. */
    public const TYPES = [
        'flight', 'train', 'hotel_checkin', 'hotel_checkout', 'conference', 'wedding',
        'meeting', 'fixture', 'concert', 'restaurant', 'theatre', 'prepaid_tour', 'transfer',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_fixed' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function point(): ?GeoPoint
    {
        if ($this->lat !== null && $this->lng !== null) {
            return new GeoPoint($this->lat, $this->lng);
        }

        return $this->place?->point();
    }

    /** The anchor plus its protective buffers — nothing discretionary may overlap this. */
    public function blockedWindow(): TimeWindow
    {
        $safety = (int) config('experience.itinerary.anchor_safety_buffer_minutes', 15);

        return new TimeWindow(
            CarbonImmutable::parse($this->starts_at)->subMinutes($this->buffer_before_minutes + $safety),
            CarbonImmutable::parse($this->ends_at)->addMinutes($this->buffer_after_minutes),
        );
    }

    public function window(): TimeWindow
    {
        return new TimeWindow(
            CarbonImmutable::parse($this->starts_at),
            CarbonImmutable::parse($this->ends_at),
        );
    }
}
