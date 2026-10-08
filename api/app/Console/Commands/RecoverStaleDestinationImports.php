<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Enums\DestinationImportStage;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Destinations\Services\DestinationCoverageStateMachine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('destinations:recover-stale-imports')]
#[Description('Fail destination imports whose workers stopped reporting progress')]
class RecoverStaleDestinationImports extends Command
{
    public function handle(DestinationCoverageStateMachine $coverage): int
    {
        $cutoff = now()->subMinutes(15);

        $staleRunning = DestinationImport::with('destination')
            ->where('status', DestinationImportStatus::Running->value)
            ->where('last_heartbeat_at', '<', $cutoff)
            ->get();

        /* Queued rows never get a heartbeat until a worker claims them. Age by
           created_at so a dead queue cannot leave checklist "queued > 15m"
           imports hanging forever. */
        $staleQueued = DestinationImport::with('destination')
            ->where('status', DestinationImportStatus::Queued->value)
            ->where('created_at', '<', $cutoff)
            ->get();

        $recovered = 0;

        foreach ($staleRunning as $import) {
            $this->failImport($import, $coverage, 'worker_stalled');
            $recovered++;
        }

        foreach ($staleQueued as $import) {
            $this->failImport($import, $coverage, 'queue_stalled');
            $recovered++;
        }

        $this->info("Recovered {$recovered} stale destination imports.");

        return self::SUCCESS;
    }

    private function failImport(
        DestinationImport $import,
        DestinationCoverageStateMachine $coverage,
        string $errorCode,
    ): void {
        $status = $import->destination->coverage_status;

        if (in_array($status, [DestinationCoverageStatus::Importing, DestinationCoverageStatus::Queued], true)) {
            $coverage->transition($import->destination, DestinationCoverageStatus::Failed);
        }

        $import->update([
            'active_destination_id' => null,
            'status' => DestinationImportStatus::Failed,
            'stage' => DestinationImportStage::Failed,
            'error_code' => $errorCode,
            'retryable' => true,
            'finished_at' => now(),
        ]);
    }
}
