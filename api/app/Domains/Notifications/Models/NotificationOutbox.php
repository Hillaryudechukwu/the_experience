<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use App\Domains\Shared\Concerns\BelongsToActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationOutbox extends Model
{
    use BelongsToActor, HasUuids;

    protected $table = 'notifications_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'scheduled_for' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    /**
     * Rows the traveller should see: already due, and not withdrawn. A reminder
     * scheduled for next week is not a notice yet.
     */
    public function scopeVisibleInInbox(Builder $query): Builder
    {
        return $query->where('scheduled_for', '<=', CarbonImmutable::now())
            ->where('status', '!=', 'cancelled');
    }
}
