<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\JourneyIntelligence\Services\MissionInterpreter;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Journeys\Models\JourneyAnchor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JourneyController extends ApiController
{
    public function __construct(private readonly MissionInterpreter $missions) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $actor = $this->actor($request);

        $journey = Journey::create(array_merge($actor->ownerAttributes(), $data, [
            'mission_goals' => $this->missions->interpret($data['mission_text'] ?? null, $data['reason']),
            'mission_interpreted_by' => 'rules',
        ]));

        return response()->json(['data' => $this->present($journey->load(['destination', 'anchors']))], 201);
    }

    public function show(Request $request, string $journey): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($request, $journey))]);
    }

    public function update(Request $request, string $journey): JsonResponse
    {
        $model = $this->find($request, $journey);
        $data = $request->validate($this->rules(partial: true));

        $model->fill($data);

        if (array_key_exists('mission_text', $data) || array_key_exists('reason', $data)) {
            $model->mission_goals = $this->missions->interpret($model->mission_text, $model->reason);
            $model->mission_interpreted_by = 'rules';
        }

        $model->save();

        return response()->json(['data' => $this->present($model->fresh(['destination', 'anchors']))]);
    }

    /** Spec s3.3 — the emotional objective of the trip, turned into soft goals. */
    public function mission(Request $request, string $journey): JsonResponse
    {
        $model = $this->find($request, $journey);
        $data = $request->validate(['mission_text' => ['required', 'string', 'max:1000']]);

        $goals = $this->missions->interpret($data['mission_text'], $model->reason);
        $model->update([
            'mission_text' => $data['mission_text'],
            'mission_goals' => $goals,
            'mission_interpreted_by' => 'rules',
        ]);

        return response()->json([
            'data' => [
                'mission_text' => $model->mission_text,
                'goals' => $goals,
                'note' => 'These goals shape what we suggest. They never override bookings, closing times or anything you have asked us to avoid.',
            ],
        ]);
    }

    public function storeAnchor(Request $request, string $journey): JsonResponse
    {
        $model = $this->find($request, $journey);

        $data = $request->validate([
            'type' => ['required', Rule::in(JourneyAnchor::TYPES)],
            'title' => ['required', 'string', 'max:160'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'place_id' => ['sometimes', 'nullable', 'uuid', 'exists:places,id'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'address' => ['sometimes', 'nullable', 'string'],
            'buffer_before_minutes' => ['sometimes', 'integer', 'min:0', 'max:480'],
            'buffer_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:480'],
        ]);

        /* Anchors are stored in UTC, but the traveller thinks in destination
           local time: "dinner at 20:00" means 20:00 in Rome, wherever the phone
           happens to be. A value that carries its own offset is honoured;
           a naive one is read in the destination's timezone. */
        $timezone = $model->destination->timezone;
        $data['starts_at'] = $this->toUtc($data['starts_at'], $timezone);
        $data['ends_at'] = $this->toUtc($data['ends_at'], $timezone);

        $anchor = $model->anchors()->create($data);

        return response()->json(['data' => $this->presentAnchor($anchor)], 201);
    }

    public function destroyAnchor(Request $request, string $journey, string $anchor): JsonResponse
    {
        $model = $this->find($request, $journey);
        $model->anchors()->where('id', $anchor)->delete();

        return response()->json(['status' => 'deleted']);
    }

    /** Naive datetimes are destination-local; offset-bearing ones are taken as given. */
    private function toUtc(string $value, string $timezone): CarbonImmutable
    {
        $hasOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($value));

        return ($hasOffset ? CarbonImmutable::parse($value) : CarbonImmutable::parse($value, $timezone))->utc();
    }

    private function find(Request $request, string $id): Journey
    {
        return Journey::with(['destination', 'anchors'])
            ->ownedBy($this->actor($request))
            ->findOrFail($id);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'destination_id' => [$required, 'uuid', 'exists:destinations,id'],
            'reason' => [$required, Rule::in(array_keys(config('experience.playbooks')))],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'child_ages' => ['sometimes', 'array'],
            'child_ages.*' => ['integer', 'min:0', 'max:18'],
            'familiarity' => ['sometimes', Rule::in(['never', 'once', 'few', 'well'])],
            'budget_total_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'daily_budget_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'mission_text' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'must_do' => ['sometimes', 'array'],
            'avoid' => ['sometimes', 'array'],
            'accessibility_mode' => ['sometimes', 'boolean'],
            'pace_override' => ['sometimes', 'nullable', Rule::in(['slow', 'moderate', 'fast'])],
        ];
    }

    private function present(Journey $journey): array
    {
        return [
            'id' => $journey->id,
            'title' => $journey->title,
            'reason' => $journey->reason,
            'reason_label' => $journey->playbook()['label'] ?? $journey->reason,
            'destination' => [
                'id' => $journey->destination->id,
                'slug' => $journey->destination->slug,
                'name' => $journey->destination->name,
                'timezone' => $journey->destination->timezone,
                'currency' => $journey->destination->currency,
                'lat' => $journey->destination->lat,
                'lng' => $journey->destination->lng,
            ],
            'starts_on' => $journey->starts_on?->toDateString(),
            'ends_on' => $journey->ends_on?->toDateString(),
            'adults' => $journey->adults,
            'children' => $journey->children,
            'child_ages' => $journey->child_ages,
            'familiarity' => $journey->familiarity,
            'budget_total_minor' => $journey->budget_total_minor,
            'daily_budget_minor' => $journey->daily_budget_minor,
            'currency' => $journey->currency,
            'mission_text' => $journey->mission_text,
            'mission_goals' => $journey->mission_goals,
            'must_do' => $journey->must_do,
            'avoid' => $journey->avoid,
            'accessibility_mode' => $journey->accessibility_mode,
            'anchors' => $journey->anchors->map(fn ($a) => $this->presentAnchor($a))->all(),
        ];
    }

    private function presentAnchor(JourneyAnchor $anchor): array
    {
        return [
            'id' => $anchor->id,
            'type' => $anchor->type,
            'title' => $anchor->title,
            'starts_at' => $anchor->starts_at->toIso8601String(),
            'ends_at' => $anchor->ends_at->toIso8601String(),
            'protected_from' => $anchor->blockedWindow()->start->toIso8601String(),
            'protected_to' => $anchor->blockedWindow()->end->toIso8601String(),
            'address' => $anchor->address,
            'lat' => $anchor->lat,
            'lng' => $anchor->lng,
        ];
    }
}
