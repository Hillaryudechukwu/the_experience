<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Models;

use App\Domains\Bookings\BookingState;
use App\Domains\Shared\Concerns\BelongsToActor;
use App\Domains\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use BelongsToActor, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => BookingState::class,
            'meta' => 'array',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    public function travellers(): HasMany
    {
        return $this->hasMany(BookingTraveller::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(BookingTransition::class)->orderBy('occurred_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function total(): ?Money
    {
        return Money::of($this->total_minor, $this->currency);
    }
}
