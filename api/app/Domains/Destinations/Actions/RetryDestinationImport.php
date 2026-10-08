<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Actions;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Enums\DestinationImportStage;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use App\Domains\Destinations\Jobs\ImportDestination;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Destinations\Services\DestinationCoverageStateMachine;
use DomainException;
use Illuminate\Support\Facades\DB;

class RetryDestinationImport
{
    public function __construct(private readonly DestinationCoverageStateMachine $coverage) {}

    public function handle(DestinationImport $previous): DestinationImport
    {
        return DB::transaction(function () use ($previous) {
            $previous = DestinationImport::with('destination')->lockForUpdate()->findOrFail($previous->id);

            if (! in_array($previous->status, [DestinationImportStatus::Failed, DestinationImportStatus::Partial], true)) {
                throw new DomainException('Only failed or partial destination imports can be retried.');
            }

            $active = $previous->destination->imports()
                ->whereIn('status', [DestinationImportStatus::Queued->value, DestinationImportStatus::Running->value])
                ->first();

            if ($active !== null) {
                return $active;
            }

            if (in_array($previous->destination->coverage_status, [DestinationCoverageStatus::Failed, DestinationCoverageStatus::Limited], true)) {
                $this->coverage->transition($previous->destination, DestinationCoverageStatus::Queued);
            }

            $retry = $previous->destination->imports()->create([
                'active_destination_id' => $previous->destination_id,
                'requested_by_type' => $previous->requested_by_type,
                'requested_by_id' => $previous->requested_by_id,
                'status' => DestinationImportStatus::Queued,
                'stage' => DestinationImportStage::Queued,
                'provider_key' => $previous->provider_key,
                'last_heartbeat_at' => now(),
            ]);

            ImportDestination::dispatch($retry->id)->afterCommit();

            return $retry;
        });
    }
}
