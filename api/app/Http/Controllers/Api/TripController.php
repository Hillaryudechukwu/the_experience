<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Itineraries\Services\ItineraryPlanner;
use App\Domains\Itineraries\Services\ItineraryReplanner;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Trips\Models\Itinerary;
use App\Domains\Trips\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends ApiController
{
    public function __construct(
        private readonly ItineraryPlanner $planner,
        private readonly ItineraryReplanner $replanner,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'journey_id' => ['required', 'uuid', 'exists:journeys,id'],
            'title' => ['sometimes', 'string', 'max:160'],
        ]);

        $actor = $this->actor($request);
        $journey = Journey::with('destination')->ownedBy($actor)->findOrFail($data['journey_id']);

        $trip = Trip::create(array_merge($actor->ownerAttributes(), [
            'journey_id' => $journey->id,
            'title' => $data['title'] ?? $journey->destination->name,
        ]));

        return response()->json(['data' => $this->present($trip)], 201);
    }

    public function show(Request $request, string $trip): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($request, $trip))]);
    }

    public function generateItinerary(Request $request, string $trip): JsonResponse
    {
        $model = $this->find($request, $trip);

        $options = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'day_start' => ['sometimes', 'date_format:H:i'],
            'day_end' => ['sometimes', 'date_format:H:i'],
            'lat' => ['sometimes', 'numeric'],
            'lng' => ['sometimes', 'numeric'],
        ]);

        $itinerary = $this->planner->generate($this->actor($request), $model, $options);

        return response()->json(['data' => $this->presentItinerary($itinerary)], 201);
    }

    /** Spec s8.3 — propose changes, never apply them silently. */
    public function replan(Request $request, string $trip): JsonResponse
    {
        $model = $this->find($request, $trip);
        $result = $this->replanner->propose($this->actor($request), $model, $request->all());

        return response()->json([
            'data' => [
                'message' => $result['message'],
                'trigger' => $result['trigger'],
                'changes' => $result['changes'],
                'blocked_by_confirmed_bookings' => $result['blocked_by_confirmed_bookings'] ?? [],
                'proposal' => $result['proposal'] === null ? null : $this->presentItinerary($result['proposal']),
            ],
        ]);
    }

    public function acceptReplan(Request $request, string $trip, string $itinerary): JsonResponse
    {
        $model = $this->find($request, $trip);
        $proposal = Itinerary::where('trip_id', $model->id)->findOrFail($itinerary);

        return response()->json(['data' => $this->presentItinerary($this->replanner->accept($model, $proposal))]);
    }

    private function find(Request $request, string $id): Trip
    {
        return Trip::with('journey.destination')->ownedBy($this->actor($request))->findOrFail($id);
    }

    private function present(Trip $trip): array
    {
        $itinerary = $trip->currentItinerary()?->load('days.items.experience.place');

        return [
            'id' => $trip->id,
            'title' => $trip->title,
            'status' => $trip->status,
            'journey_id' => $trip->journey_id,
            'itinerary' => $itinerary === null ? null : $this->presentItinerary($itinerary),
        ];
    }

    private function presentItinerary(Itinerary $itinerary): array
    {
        $itinerary->loadMissing('days.items.experience.place');

        return [
            'id' => $itinerary->id,
            'version' => $itinerary->version,
            'is_current' => $itinerary->is_current,
            'generated_at' => $itinerary->generated_at->toIso8601String(),
            'engine_version' => $itinerary->engine_version,
            'objective_value' => $itinerary->objective_value,
            'days' => $itinerary->days->map(fn ($day) => [
                'id' => $day->id,
                'date' => $day->date->toDateString(),
                'summary' => $day->summary,
                'items' => $day->items->map(fn ($item) => [
                    'id' => $item->id,
                    'kind' => $item->kind,
                    'title' => $item->title,
                    'starts_at' => $item->starts_at->toIso8601String(),
                    'ends_at' => $item->ends_at->toIso8601String(),
                    'travel_minutes_from_previous' => $item->travel_minutes_from_previous,
                    'travel_mode' => $item->travel_mode,
                    'locked' => $item->locked,
                    'score' => $item->score,
                    'reason' => $item->reason,
                    'experience' => $item->experience === null ? null : [
                        'id' => $item->experience->id,
                        'title' => $item->experience->title,
                        'image_url' => $item->experience->image_url,
                        'lat' => $item->experience->place?->lat,
                        'lng' => $item->experience->place?->lng,
                    ],
                ])->all(),
            ])->all(),
        ];
    }
}
