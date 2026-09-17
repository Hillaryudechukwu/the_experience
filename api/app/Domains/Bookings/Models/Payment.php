<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
