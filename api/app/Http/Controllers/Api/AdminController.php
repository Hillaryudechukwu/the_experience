<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\ExternalSources\Models\ProviderHealth;
use App\Domains\ExternalSources\Models\ProviderSyncFailure;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use App\Domains\Places\Models\PlaceMergeCandidate;
use App\Domains\Recommendations\Models\RecommendationSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operations surface (spec s24).
 *
 * Enough to run the platform day to day: provider health, failed syncs,
 * duplicate review, and the ability to explain any recommendation that was
 * ever shown.
 */
class AdminController extends ApiController
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    public function providers(): JsonResponse
    {
        $health = ProviderHealth::all()->keyBy('provider');

        return response()->json([
            'data' => collect($this->registry->status())->map(function (array $status, string $key) use ($health) {
                $record = $health->get($key);

                return array_merge($status, [
                    'provider' => $key,
                    'status' => $record?->status ?? 'unknown',
                    'last_successful_request_at' => $record?->last_successful_request_at?->toIso8601String(),
                    'consecutive_failures' => $record?->consecutive_failures ?? 0,
                    'failure_rate' => $record?->failure_rate ?? 0,
                    'avg_latency_ms' => $record?->avg_latency_ms,
                    'circuit_open' => (bool) $record?->isCircuitOpen(),
                ]);
            })->values()->all(),
        ]);
    }

    public function syncFailures(Request $request): JsonResponse
    {
        $failures = ProviderSyncFailure::query()
            ->when($request->query('provider'), fn ($q, $p) => $q->where('provider', $p))
            ->where('resolved', false)
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['data' => $failures]);
    }

    public function resolveSyncFailure(string $failure): JsonResponse
    {
        ProviderSyncFailure::findOrFail($failure)->update(['resolved' => true]);

        return response()->json(['status' => 'resolved']);
    }

    public function mergeCandidates(): JsonResponse
    {
        return response()->json([
            'data' => PlaceMergeCandidate::with('place')->where('status', 'pending')->limit(100)->get(),
        ]);
    }

    /** Correcting a provider mapping is a first-class operation (acceptance 64). */
    public function remapProviderEntity(Request $request, string $entity): JsonResponse
    {
        $data = $request->validate([
            'entity_id' => ['required', 'uuid'],
            'confidence' => ['sometimes', 'numeric', 'between:0,1'],
        ]);

        $mapping = ExternalEntity::findOrFail($entity);
        $mapping->update([
            'entity_id' => $data['entity_id'],
            'confidence' => $data['confidence'] ?? 1.0,
        ]);

        return response()->json(['data' => $mapping]);
    }

    /** Recommendation debugging: what exactly produced this ranking? */
    public function explainRecommendationSet(string $set): JsonResponse
    {
        $model = RecommendationSet::with(['recommendations.reasons', 'recommendations.experience'])
            ->findOrFail($set);

        $snapshot = \App\Domains\Journeys\Models\JourneyContextSnapshot::find($model->journey_context_snapshot_id);

        return response()->json([
            'data' => [
                'id' => $model->id,
                'surface' => $model->surface,
                'request' => $model->request,
                'candidates_considered' => $model->candidates_considered,
                'generation_ms' => $model->generation_ms,
                'context' => $snapshot === null ? null : [
                    'captured_at' => $snapshot->captured_at->toIso8601String(),
                    'local_time' => $snapshot->local_time,
                    'location' => ['lat' => $snapshot->lat, 'lng' => $snapshot->lng, 'precision' => $snapshot->location_precision],
                    'weather' => $snapshot->weather,
                    'window_minutes' => $snapshot->window_minutes,
                    'weights' => $snapshot->weights,
                    'engine_version' => $snapshot->engine_version,
                ],
                'recommendations' => $model->recommendations->map(fn ($r) => [
                    'rank' => $r->rank,
                    'score' => $r->score,
                    'experience' => ['id' => $r->experience_id, 'title' => $r->experience?->title],
                    'reasons' => $r->reasons->map(fn ($reason) => [
                        'component' => $reason->component,
                        'direction' => $reason->direction,
                        'contribution' => $reason->contribution,
                        'message' => $reason->message,
                    ])->all(),
                ])->all(),
            ],
        ]);
    }
}
