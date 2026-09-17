<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Analytics\Models\BehaviouralEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends ApiController
{
    public function __construct(private readonly RecordBehaviouralEvent $events) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'max:50'],
            'events.*.type' => ['required', Rule::in(BehaviouralEvent::TYPES)],
            'events.*.subject_type' => ['sometimes', 'nullable', 'string'],
            'events.*.subject_id' => ['sometimes', 'nullable', 'uuid'],
            'events.*.recommendation_set_id' => ['sometimes', 'nullable', 'uuid'],
            'events.*.surface' => ['sometimes', 'nullable', 'string'],
            'events.*.properties' => ['sometimes', 'array'],
            'events.*.occurred_at' => ['sometimes', 'date'],
        ]);

        $actor = $this->actor($request);

        foreach ($data['events'] as $event) {
            $this->events->record($actor, $event['type'], $event);
        }

        return response()->json(['recorded' => count($data['events'])], 202);
    }
}
