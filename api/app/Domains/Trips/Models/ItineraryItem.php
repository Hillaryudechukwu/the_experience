<?php

declare(strict_types=1);

namespace App\Domains\Trips\Models;

use App\Domains\Experiences\Models\Experience;
use App\Domains\Journeys\Models\JourneyAnchor;
use App\Domains\Shared\ValueObjects\TimeWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItineraryItem extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'locked' => 'boolean',
        ];
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }

    public function anchor(): BelongsTo
    {
        return $this->belongsTo(JourneyAnchor::class, 'journey_anchor_id');
    }

    public function window(): TimeWindow
    {
        return new TimeWindow(
            CarbonImmutable::parse($this->starts_at),
            CarbonImmutable::parse($this->ends_at),
        );
    }
}
