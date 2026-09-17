<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProviderAvailability extends Model
{
    use HasUuids;

    protected $table = 'provider_availability';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'retrieved_at' => 'immutable_datetime'];
    }

    public function isExpired(): bool
    {
        return $this->retrieved_at->addSeconds($this->ttl_seconds)->isPast();
    }
}
