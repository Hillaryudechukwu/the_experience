<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Models;

use App\Domains\Destinations\Enums\DestinationImportStage;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DestinationImport extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['error_context'];

    protected static function booted(): void
    {
        static::saving(function (DestinationImport $import): void {
            $status = $import->status instanceof DestinationImportStatus
                ? $import->status
                : DestinationImportStatus::from((string) $import->status);

            $import->active_destination_id = $status->isActive()
                ? $import->destination_id
                : null;
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DestinationImportStatus::class,
            'stage' => DestinationImportStage::class,
            'error_context' => 'encrypted:array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'retryable' => 'boolean',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}
