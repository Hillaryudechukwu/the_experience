<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use App\Domains\Shared\Concerns\BelongsToActor;
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
        ];
    }
}
