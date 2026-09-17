<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Maps an internal canonical UUID to a provider's own id (spec s19.1). */
class ExternalEntity extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'confidence' => 'float', 'last_synced_at' => 'immutable_datetime'];
    }
}
