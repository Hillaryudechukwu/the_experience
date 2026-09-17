<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProviderSyncRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
