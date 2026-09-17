<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Models;

use App\Domains\Experiences\Models\Experience;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingItem extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array', 'starts_at' => 'immutable_datetime'];
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }
}
