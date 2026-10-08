<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Jobs;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Services\DestinationCoverageStateMachine;
use App\Domains\Destinations\Services\DestinationReadinessEvaluator;
use App\Domains\Places\Actions\EnrichDestinationContent as EnrichContent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnrichDestinationContent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [300, 1800, 7200];

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $destinationId) {}

    public function uniqueId(): string
    {
        return $this->destinationId;
    }

    public function handle(
        EnrichContent $enrich,
        DestinationReadinessEvaluator $readiness,
        DestinationCoverageStateMachine $coverage,
    ): void {
        $destination = Destination::findOrFail($this->destinationId);
        $enrich->run($destination, (int) config('experience.destination_activation.enrichment_retry_limit', 50));
        $evaluation = $readiness->evaluate($destination);

        if ($evaluation['status']->isUsable()
            && in_array($destination->coverage_status, [DestinationCoverageStatus::Failed, DestinationCoverageStatus::Limited], true)) {
            $coverage->transition($destination, $evaluation['status']);
        }
    }
}
