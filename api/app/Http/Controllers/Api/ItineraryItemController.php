<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Journeys\Models\JourneyAnchor;
use App\Domains\Shared\ValueObjects\TimeWindow;
use App\Domains\Trips\Models\Itinerary;
use App\Domains\Trips\Models\ItineraryItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItineraryItemController extends ApiController
{
    public function __construct(private readonly RecordBehaviouralEvent $events) {}

    public function store(Request $request, string $itinerary): JsonResponse
    {
        $data = $request->validate([
            'experience_id' => ['required', 'uuid', 'exists:experiences,id'],
            'date' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
        ]);

        $model = Itinerary::with('trip.journey.anchors', 'days')->findOrFail($itinerary);
        $this->assertOwned($request, $model);

        $experience = Experience::findOrFail($data['experience_id']);
        $starts = CarbonImmutable::parse($data['starts_at']);
        $ends = $starts->addMinutes($experience->expected_duration_minutes);

        $this->assertNoAnchorClash($model, new TimeWindow($starts, $ends));

        $day = $model->days()->firstOrCreate(
            ['date' => CarbonImmutable::parse($data['date'])->toDateString()],
            ['summary' => 'Added by you'],
        );

        $item = $day->items()->create([
            'kind' => 'experience',
            'experience_id' => $experience->id,
            'title' => $experience->title,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'reason' => 'You added this',
            'sort' => $day->items()->count(),
        ]);

        $this->events->record($this->actor($request), 'add_to_itinerary', [
            'subject_type' => 'experience',
            'subject_id' => $experience->id,
        ]);

        return response()->json(['data' => $this->present($item)], 201);
    }

    public function update(Request $request, string $item): JsonResponse
    {
        $model = ItineraryItem::with('day.itinerary.trip.journey.anchors')->findOrFail($item);
        $this->assertOwned($request, $model->day->itinerary);

        $data = $request->validate([
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'locked' => ['sometimes', 'boolean'],
        ]);

        if ($model->kind === 'anchor') {
            return response()->json([
                'message' => 'Fixed commitments are edited on the journey, not the itinerary.',
            ], 422);
        }

        $starts = isset($data['starts_at']) ? CarbonImmutable::parse($data['starts_at']) : CarbonImmutable::parse($model->starts_at);
        $ends = isset($data['ends_at']) ? CarbonImmutable::parse($data['ends_at']) : CarbonImmutable::parse($model->ends_at);

        $this->assertNoAnchorClash($model->day->itinerary, new TimeWindow($starts, $ends));

        $model->update(array_merge($data, ['starts_at' => $starts, 'ends_at' => $ends]));

        return response()->json(['data' => $this->present($model->fresh())]);
    }

    public function destroy(Request $request, string $item): JsonResponse
    {
        $model = ItineraryItem::with('day.itinerary.trip')->findOrFail($item);
        $this->assertOwned($request, $model->day->itinerary);

        if ($model->locked) {
            return response()->json(['message' => 'This is a fixed commitment and cannot be removed here.'], 422);
        }

        $this->events->record($this->actor($request), 'remove_from_itinerary', [
            'subject_type' => 'experience',
            'subject_id' => $model->experience_id,
        ]);

        $model->delete();

        return response()->json(['status' => 'deleted']);
    }

    /** Acceptance 56: an itinerary never overlaps a fixed anchor. */
    private function assertNoAnchorClash(Itinerary $itinerary, TimeWindow $window): void
    {
        $anchors = $itinerary->trip->journey->anchors;

        foreach ($anchors as $anchor) {
            /** @var JourneyAnchor $anchor */
            if ($window->overlaps($anchor->blockedWindow())) {
                abort(422, sprintf(
                    'That time clashes with "%s", which runs %s to %s.',
                    $anchor->title,
                    $anchor->starts_at->format('H:i'),
                    $anchor->ends_at->format('H:i'),
                ));
            }
        }
    }

    private function assertOwned(Request $request, Itinerary $itinerary): void
    {
        $actor = $this->actor($request);
        $trip = $itinerary->trip;

        $owned = $actor->userId !== null
            ? $trip->user_id === $actor->userId
            : $trip->user_id === null && $trip->guest_session_id === $actor->guestSessionId;

        abort_unless($owned, 404);
    }

    private function present(ItineraryItem $item): array
    {
        return [
            'id' => $item->id,
            'kind' => $item->kind,
            'title' => $item->title,
            'experience_id' => $item->experience_id,
            'starts_at' => $item->starts_at->toIso8601String(),
            'ends_at' => $item->ends_at->toIso8601String(),
            'locked' => $item->locked,
            'reason' => $item->reason,
        ];
    }
}
