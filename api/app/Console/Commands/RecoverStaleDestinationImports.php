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
        $stale = DestinationImport::with('destination')
            ->where('status', DestinationImportStatus::Running->value)
            ->where('last_heartbeat_at', '<', now()->subMinutes(15))
            ->get();

        foreach ($stale as $import) {
            if ($import->destination->coverage_status === DestinationCoverageStatus::Importing) {
                $coverage->transition($import->destination, DestinationCoverageStatus::Failed);
            }

            $import->update([
                'active_destination_id' => null,
                'status' => DestinationImportStatus::Failed,
                'stage' => DestinationImportStage::Failed,
                'error_code' => 'worker_stalled',
                'retryable' => true,
                'finished_at' => now(),
            ]);
        }

        $this->info("Recovered {$stale->count()} stale destination imports.");

        return self::SUCCESS;
    }
}
