<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Destinations\Actions\RetryDestinationImport;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use App\Domains\Destinations\Models\DestinationImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DestinationImportController extends ApiController
{
    public function show(Request $request, string $import): JsonResponse
    {
        $model = DestinationImport::with('destination:id,name,slug,timezone,coverage_status')->findOrFail($import);
        $actor = $this->actor($request);
        $ownsImport = $model->requested_by_type === ($actor->isGuest() ? 'guest' : 'user')
            && $model->requested_by_id === ($actor->isGuest() ? $actor->guestSessionId : (string) $actor->userId);

        abort_unless($ownsImport || $model->finished_at !== null, 404);

        return response()->json([
            'data' => [
                'id' => $model->id,
                'destination_id' => $model->destination_id,
                'destination' => $model->destination,
                'status' => $model->status->value,
                'stage' => $model->stage->value,
                'records_seen' => $model->records_seen,
                'places_created' => $model->places_created,
                'places_matched' => $model->places_matched,
                'places_needing_review' => $model->places_needing_review,
                'places_failed' => $model->places_failed,
                'experiences_published' => $model->experiences_published,
                'experiences_pending_content' => $model->experiences_pending_content,
                'started_at' => $model->started_at?->toIso8601String(),
                'finished_at' => $model->finished_at?->toIso8601String(),
                'retryable' => $model->status === DestinationImportStatus::Failed && $model->retryable,
                'message' => $this->message($model),
                'poll_after_seconds' => $model->status->isActive()
                    ? (int) config('experience.destination_activation.poll_after_seconds', 3)
                    : null,
            ],
        ]);
    }

    public function retry(Request $request, string $import, RetryDestinationImport $retry): JsonResponse
    {
        $model = DestinationImport::findOrFail($import);
        $actor = $this->actor($request);
        $ownsImport = $model->requested_by_type === ($actor->isGuest() ? 'guest' : 'user')
            && $model->requested_by_id === ($actor->isGuest() ? $actor->guestSessionId : (string) $actor->userId);

        abort_unless($ownsImport, 404);
        abort_unless($model->status === DestinationImportStatus::Failed && $model->retryable, 409, 'This preparation cannot be retried.');

        $next = $retry->handle($model);

        return response()->json([
            'data' => [
                'import_id' => $next->id,
                'destination_id' => $next->destination_id,
                'status' => $next->status->value,
                'stage' => $next->stage->value,
                'poll_after_seconds' => (int) config('experience.destination_activation.poll_after_seconds', 3),
            ],
        ], 202);
    }

    private function message(DestinationImport $import): string
    {
        return match ($import->stage->value) {
            'queued' => 'Your destination is waiting to be prepared.',
            'discovering_places' => 'Finding places worth considering.',
            'resolving_places' => 'Checking place identities and duplicates.',
            'enriching_content' => 'Preparing sourced descriptions and images.',
            'evaluating_readiness' => 'Checking that recommendations are useful and grounded.',
            'ready' => 'Your destination is ready.',
            'limited' => 'A few grounded recommendations are available.',
            'cancelled' => 'Preparation was cancelled.',
            default => 'We could not prepare this destination yet.',
        };
    }
}
