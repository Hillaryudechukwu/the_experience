<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProviderHealth extends Model
{
    use HasUuids;

    protected $table = 'provider_health';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate_limit_state' => 'array',
            'failure_rate' => 'float',
            'last_successful_request_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'circuit_open_until' => 'immutable_datetime',
        ];
    }

    public function isCircuitOpen(): bool
    {
        return $this->circuit_open_until !== null && $this->circuit_open_until->isFuture();
    }
}
