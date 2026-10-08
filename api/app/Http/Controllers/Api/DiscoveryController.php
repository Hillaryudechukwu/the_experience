<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\AI\Services\IntentParser;
use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Destinations\Services\EnsureDestinationReadyForDiscovery;
use App\Domains\Discovery\Services\NameRelevance;
use App\Domains\Experiences\Services\ExperiencePresenter;
use App\Domains\Recommendations\DTO\ScoredExperience;
use App\Domains\Recommendations\Services\RecommendationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The discovery surfaces from spec s4 and s5.
 */
class DiscoveryController extends ApiController
{
    public function __construct(
        private readonly RecommendationService $recommendations,
        private readonly ExperiencePresenter $presenter,
        private readonly IntentParser $intents,
        private readonly RecordBehaviouralEvent $events,
        private readonly NameRelevance $relevance,
        private readonly EnsureDestinationReadyForDiscovery $destinationReadiness,
    ) {}

    /** Spec s4.1 — "I'm Here Now". */
    public function now(Request $request): JsonResponse
    {
        $input = $this->common($request, 'now');

        return $this->respond($request, $input, (int) $request->input('limit', 4));
    }

    /** Spec s4.2 — "I have X hours". */
    public function timeBoxed(Request $request): JsonResponse
    {
        $request->validate([
            'window_minutes' => ['required', 'integer', 'min:15', 'max:900'],
        ]);

        $input = array_merge($this->common($request, 'time_boxed'), [
            'window_minutes' => (int) $request->input('window_minutes'),
        ]);

        return $this->respond($request, $input, (int) $request->input('limit', 5));
    }

    /** Spec s4.3 — mood-based discovery. */
    public function mood(Request $request): JsonResponse
    {
        $request->validate(['mood' => ['required', Rule::in(config('experience.moods'))]]);

        $input = array_merge($this->common($request, 'mood'), ['mood' => $request->input('mood')]);

        return $this->respond($request, $input, (int) $request->input('limit', 5));
    }

    /** Spec s5.6 — universal natural-language search. */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:300']]);

        $parsed = $this->intents->parse($request->input('q'), CarbonImmutable::now());

        $input = array_merge(
            $this->common($request, 'search'),
            array_filter($parsed['filters'], fn ($v) => $v !== null && $v !== []),
            array_filter([
                'window_minutes' => $parsed['window_minutes'],
                'mood' => $parsed['mood'],
            ], fn ($v) => $v !== null),
        );

        $this->events->record($this->actor($request), 'search', [
            'surface' => 'search',
            'properties' => ['query' => $request->input('q'), 'interpreted' => $parsed],
        ]);

        $response = $this->respond($request, $input, (int) $request->input('limit', 12));

        $payload = $response->getData(true);
        $payload['data'] = $this->relevance->promote($payload['data'] ?? [], $request->input('q'));
        $payload['interpreted'] = [
            'understood_as' => $parsed['echo'],
            'filters' => $input,
        ];

        return response()->json($payload);
    }

    /** Spec s14.6 — a constrained "surprise me". */
    public function surpriseMe(Request $request): JsonResponse
    {
        $input = array_merge($this->common($request, 'surprise_me'), [
            'window_minutes' => (int) $request->input('window_minutes', 150),
        ]);

        $result = $this->recommendations->recommend($this->actor($request), $input, 12);

        /* Pick from the strong options rather than the whole catalogue: a
           surprise should still be a good use of the traveller's time. */
        $strong = array_values(array_filter($result['results'], fn (ScoredExperience $s) => $s->score >= 60));
        $pool = $strong !== [] ? $strong : $result['results'];

        if ($pool === []) {
            return response()->json(['data' => null, 'message' => 'Nothing strong enough to surprise you with right now.']);
        }

        $pick = $pool[random_int(0, count($pool) - 1)];

        return response()->json([
            'data' => $this->presenter->card($pick->candidate->experience, $pick),
            'recommendation_set_id' => $result['set']->id,
            'context' => $this->contextPayload($result['context']),
        ]);
    }

    private function respond(Request $request, array $input, int $limit): JsonResponse
    {
        $result = $this->recommendations->recommend($this->actor($request), $input, $limit);

        return response()->json([
            'data' => array_map(
                fn (ScoredExperience $scored) => $this->presenter->card($scored->candidate->experience, $scored),
                $result['results'],
            ),
            'recommendation_set_id' => $result['set']->id,
            'context' => $this->contextPayload($result['context']),
            'candidates_considered' => $result['set']->candidates_considered,
            'notice' => $this->notice($result['relaxed'] ?? [], $result['results']),
        ]);
    }

    /** Say plainly when we had to loosen the request to find anything. */
    private function notice(array $relaxed, array $results): ?string
    {
        if ($results === []) {
            return 'Nothing here fits all of that. Loosening the time, the budget or the distance would open it up.';
        }

        return match (true) {
            $relaxed === ['distance'] => 'Nothing matched within walking distance, so this reaches a little further out.',
            in_array('categories', $relaxed, true) => 'Nothing matched that exactly, so these are the closest fit on everything else you asked for.',
            default => null,
        };
    }

    private function contextPayload($context): array
    {
        return [
            'local_time' => $context->now->toIso8601String(),
            'location_precision' => $context->locationPrecision,
            'window_minutes' => $context->windowMinutes(),
            'weather' => $context->weather?->toArray(),
            'next_anchor' => $context->nextAnchor === null ? null : [
                'title' => $context->nextAnchor->title,
                'starts_at' => $context->nextAnchor->starts_at->toIso8601String(),
            ],
            'journey' => $context->journey === null ? null : [
                'id' => $context->journey->id,
                'reason' => $context->journey->reason,
                'familiarity' => $context->journey->familiarity,
            ],
            'engine_version' => config('experience.engine_version'),
        ];
    }

    private function common(Request $request, string $surface): array
    {
        $request->validate([
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'location_precision' => ['sometimes', Rule::in(['precise', 'approximate'])],
            'destination' => ['sometimes', 'nullable', 'string'],
            'destination_id' => ['sometimes', 'nullable', 'uuid'],
            'journey_id' => ['sometimes', 'nullable', 'uuid'],
            'categories' => ['sometimes', 'array'],
            'exclude_categories' => ['sometimes', 'array'],
            'free_only' => ['sometimes', 'boolean'],
            'max_price_minor' => ['sometimes', 'integer', 'min:0'],
            'radius_metres' => ['sometimes', 'integer', 'min:200', 'max:50000'],
        ]);

        $input = array_filter([
            'surface' => $surface,
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
            'location_precision' => $request->input('location_precision'),
            'destination' => $request->input('destination'),
            'destination_id' => $request->input('destination_id'),
            'journey_id' => $request->input('journey_id'),
            'categories' => $request->input('categories'),
            'exclude_categories' => $request->input('exclude_categories'),
            'weather_exposure' => $request->input('weather_exposure'),
            'free_only' => $request->boolean('free_only') ?: null,
            'max_price_minor' => $request->input('max_price_minor'),
            'radius_metres' => $request->input('radius_metres'),
            'query' => $request->input('query'),
        ], fn ($v) => $v !== null);

        $this->destinationReadiness->handle($this->actor($request), $input);

        return $input;
    }
}
