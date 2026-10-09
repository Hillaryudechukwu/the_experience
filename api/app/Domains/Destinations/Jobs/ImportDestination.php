<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Jobs;

use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Enums\DestinationImportStage;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Destinations\Services\DestinationCoverageStateMachine;
use App\Domains\Destinations\Services\DestinationReadinessEvaluator;
use App\Domains\Places\Actions\IngestPlaces;
use App\Domains\Shared\ValueObjects\Actor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportDestination implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public int $uniqueFor = 1800;

    public int $timeout = 300;

    public function __construct(public readonly string $importId) {}

    public function uniqueId(): string
    {
        return $this->importId;
    }

    public function handle(
        IngestPlaces $ingest,
        DestinationCoverageStateMachine $coverage,
        DestinationReadinessEvaluator $readiness,
        RecordBehaviouralEvent $events,
    ): void {
        $import = DestinationImport::with('destination')->findOrFail($this->importId);

        if (! $import->status->isActive()) {
            return;
        }

        Cache::lock("destination-import:{$import->destination_id}", $this->timeout + 30)->block(5, function () use ($import, $ingest, $coverage, $readiness, $events) {
            $import->refresh();

            if (! $import->status->isActive()) {
                return;
            }

            if ($import->destination->coverage_status === DestinationCoverageStatus::Queued) {
                $coverage->transition($import->destination, DestinationCoverageStatus::Importing);
            }

            $import->update([
                'status' => DestinationImportStatus::Running,
                'stage' => DestinationImportStage::DiscoveringPlaces,
                'attempt' => $import->attempt + 1,
                'started_at' => $import->started_at ?? now(),
                'last_heartbeat_at' => now(),
                'error_code' => null,
                'error_context' => null,
                'retryable' => false,
            ]);
            $this->log($import, 'destination import started');

            try {
                $result = $ingest->run($import->destination, [
                    'limit' => (int) config('experience.destination_activation.place_limit', 20),
                    'enrich' => true,
                ]);

                $import->update([
                    'stage' => DestinationImportStage::EvaluatingReadiness,
                    'last_heartbeat_at' => now(),
                    'records_seen' => $result['run']->records_seen,
                    'places_created' => $result['created'],
                    'places_matched' => $result['matched'],
                    'places_needing_review' => $result['needs_review'],
                    'places_failed' => $result['failed'],
                ]);
                $this->log($import, 'destination import ingestion completed');

                $evaluation = $readiness->evaluate($import->destination);
                $coverage->transition($import->destination, $evaluation['status']);
                $import->destination->update(['last_imported_at' => now()]);

                $import->update([
                    'active_destination_id' => null,
                    'status' => $evaluation['status'] === DestinationCoverageStatus::Ready
                        ? DestinationImportStatus::Succeeded
                        : ($evaluation['status'] === DestinationCoverageStatus::Limited
                            ? DestinationImportStatus::Partial
                            : DestinationImportStatus::Failed),
                    'stage' => match ($evaluation['status']) {
                        DestinationCoverageStatus::Ready => DestinationImportStage::Ready,
                        DestinationCoverageStatus::Limited => DestinationImportStage::Limited,
                        default => DestinationImportStage::Failed,
                    },
                    'experiences_published' => $evaluation['published'],
                    'experiences_pending_content' => $evaluation['pending_content'],
                    'error_code' => $evaluation['status'] === DestinationCoverageStatus::Failed ? 'insufficient_grounded_content' : null,
                    'retryable' => $evaluation['status'] === DestinationCoverageStatus::Failed
                        && $evaluation['pending_content'] > 0,
                    'finished_at' => now(),
                ]);
                $import->refresh();
                $this->recordOutcome($events, $import);
                $this->log($import, 'destination import finished');
            } catch (Throwable $exception) {
                $import->update([
                    'error_code' => 'provider_temporarily_unavailable',
                    'retryable' => true,
                    'last_heartbeat_at' => now(),
                    'error_context' => $this->errorContext($exception),
                ]);
                $this->log($import->fresh(), 'destination import attempt failed', ['error_code' => 'provider_temporarily_unavailable']);

                throw $exception;
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $import = DestinationImport::with('destination')->find($this->importId);

        if ($import === null) {
            return;
        }

        if (in_array($import->destination->coverage_status, [DestinationCoverageStatus::Queued, DestinationCoverageStatus::Importing], true)) {
            $import->destination->update(['coverage_status' => DestinationCoverageStatus::Failed]);
        }

        $import->update([
            'active_destination_id' => null,
            'status' => DestinationImportStatus::Failed,
            'stage' => DestinationImportStage::Failed,
            'error_code' => $import->error_code ?? 'import_failed',
            'retryable' => true,
            'error_context' => $exception === null ? $import->error_context : $this->errorContext($exception),
            'finished_at' => now(),
        ]);

        $import->refresh();
        $this->recordOutcome(app(RecordBehaviouralEvent::class), $import);
        $this->log($import, 'destination import failed permanently');
    }

    private function recordOutcome(RecordBehaviouralEvent $events, DestinationImport $import): void
    {
        $actor = $import->requested_by_type === 'user'
            ? new Actor(userId: (int) $import->requested_by_id)
            : new Actor(guestSessionId: $import->requested_by_id);

        $events->record($actor, $import->status === DestinationImportStatus::Failed
            ? 'destination_import_failed'
            : 'destination_import_completed', [
                'subject_type' => 'destination',
                'subject_id' => $import->destination_id,
                'properties' => [
                    'import_id' => $import->id,
                    'status' => $import->status->value,
                    'provider' => $import->provider_key,
                    'records_seen' => $import->records_seen,
                    'experiences_published' => $import->experiences_published,
                ],
            ]);
    }

    /** @param array<string, mixed> $extra */
    private function log(DestinationImport $import, string $message, array $extra = []): void
    {
        Log::info($message, array_merge([
            'import_id' => $import->id,
            'destination_id' => $import->destination_id,
            'provider_key' => $import->provider_key,
            'stage' => $import->stage->value,
        ], $extra));
    }

    /**
     * Keep encrypted error_context inside MySQL TEXT after encryption overhead.
     *
     * @return array{type: class-string<Throwable>, message: string}
     */
    private function errorContext(Throwable $exception): array
    {
        return [
            'type' => $exception::class,
            'message' => mb_substr($exception->getMessage(), 0, 500),
        ];
    }
}
