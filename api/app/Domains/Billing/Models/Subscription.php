<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\SubscriptionState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One store subscription, as this API understands it (Subscription JEM §6).
 *
 * The row is not the entitlement. `canUse()` is, and it asks two questions
 * that are deliberately separate: does the state allow use, and does the last
 * successful charge still cover now. A cancelled subscription passes the
 * second until its month runs out; a renewing one fails it the moment the
 * charge stops landing.
 */
class Subscription extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => SubscriptionState::class,
            'paid_until' => 'immutable_datetime',
            'pause_resumes_at' => 'immutable_datetime',
            'auto_renew' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class);
    }

    /** Does a paid month cover this instant? */
    public function canUse(?CarbonImmutable $now = null): bool
    {
        if (! $this->state->allowsUse()) {
            return false;
        }

        return $this->paid_until !== null
            && $this->paid_until->greaterThan($now ?? CarbonImmutable::now());
    }
}
