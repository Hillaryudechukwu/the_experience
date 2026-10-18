<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a store told us, kept so it cannot tell us twice (JEM §11 P1).
 *
 * Apple and Google both retry deliveries. Recording the store's own
 * notification id, and a hash of the payload for the deliveries that carry no
 * id, is what makes the second copy a no-op instead of a second state move.
 */
class SubscriptionEvent extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
