<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Journeys\Models\JourneyAnchor;
use App\Domains\Notifications\Services\NotificationEnqueuer;
use App\Domains\Shared\ValueObjects\TimeWindow;
use App\Domains\Trips\Models\Itinerary;
use App\Domains\Trips\Models\ItineraryItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ItineraryItemController extends ApiController
{
    public function __construct(
        private readonly RecordBehaviouralEvent $events,
        private readonly NotificationEnqueuer $notifications,
    ) {}

    public function store(Request $request, string $itinerary): JsonResponse
    {
        $data = $request->validate([
            'experience_id' => ['required', 'uuid', 'exists:experiences,id'],
            'date' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
        ]);

        $model = Itinerary::with('trip.journey.anchors', 'days')->findOrFail($itinerary);
        $this->assertOwned($request, $model);

        $item = $this->add(
            $request,
            $model,
            Experience::findOrFail($data['experience_id']),
            $data['date'],
            CarbonImmutable::parse($data['starts_at']),
            null,
            'You added this',
        );

        return response()->json(['data' => $this->present($item)], 201);
    }

    /**
     * A whole date night in one go: every stop or none, so a clash on the
     * nightcap does not leave half an evening in the trip. Saving it also
     * schedules the reminder on the day.
     */
    public function storeDateNight(Request $request, string $itinerary): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'stops' => ['required', 'array', 'min:1', 'max:3'],
            'stops.*.role' => ['required', 'distinct', Rule::in(['opener', 'dinner', 'nightcap'])],
            'stops.*.experience_id' => ['required', 'uuid', 'exists:experiences,id'],
            'stops.*.starts_at' => ['required', 'date'],
            'stops.*.ends_at' => ['required', 'date'],
        ]);

        $model = Itinerary::with('trip.journey.anchors', 'trip.journey.destination', 'days')->findOrFail($itinerary);
        $this->assertOwned($request, $model);

        /* In the order they happen, whatever order they were sent in: the
           reminder and the trip both read the first stop as the start. */
        $requested = collect($data['stops'])
            ->map(fn (array $stop) => $stop + [
                'window' => new TimeWindow(CarbonImmutable::parse($stop['starts_at']), CarbonImmutable::parse($stop['ends_at'])),
            ])
            ->sortBy(fn (array $stop) => $stop['window']->start->getTimestamp())
            ->values();

        $this->assertEveningFits($model, $requested->pluck('window')->all(), $model->trip->journey->destination->timezone ?? 'UTC');

        /** @var array<string, ItineraryItem> $stops */
        $stops = DB::transaction(function () use ($request, $model, $data, $requested) {
            $stops = [];
            foreach ($requested as $stop) {
                $stops[$stop['role']] = $this->add(
                    $request,
                    $model,
                    Experience::findOrFail($stop['experience_id']),
                    $data['date'],
                    $stop['window']->start,
                    $stop['window']->end,
                    'Your date night',
                );
            }

            return $stops;
        });

        $reminder = $this->notifications->dateNightPlanned(
            $this->actor($request),
            $model,
            $stops,
            $model->trip->journey->destination->timezone ?? 'UTC',
        );

        return response()->json(['data' => [
            'items' => array_values(array_map(fn (ItineraryItem $item) => $this->present($item), $stops)),
            'reminder_at' => $reminder?->scheduled_for?->toIso8601String(),
        ]], 201);
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
        $this->notifications->withdrawDateNightReminder($model);

        return response()->json(['status' => 'deleted']);
    }

    /** Without an end, the item runs for the experience's usual length. */
    private function add(
        Request $request,
        Itinerary $itinerary,
        Experience $experience,
        string $date,
        CarbonImmutable $starts,
        ?CarbonImmutable $ends,
        string $reason,
    ): ItineraryItem {
        $ends ??= $starts->addMinutes($experience->expected_duration_minutes);

        $this->assertNoAnchorClash($itinerary, new TimeWindow($starts, $ends));

        $day = $itinerary->days()->firstOrCreate(
            ['date' => CarbonImmutable::parse($date)->toDateString()],
            ['summary' => 'Added by you'],
        );

        $item = $day->items()->create([
            'kind' => 'experience',
            'experience_id' => $experience->id,
            'title' => $experience->title,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'reason' => $reason,
            'sort' => $day->items()->count(),
        ]);

        $this->events->record($this->actor($request), 'add_to_itinerary', [
            'subject_type' => 'experience',
            'subject_id' => $experience->id,
        ]);

        return $item;
    }

    /**
     * The evening's stops must run one after another, and must not land on
     * anything already in the trip — a second date night on the same evening
     * included. Fixed commitments are checked separately, with their buffers.
     *
     * @param  list<TimeWindow>  $windows  in order
     */
    private function assertEveningFits(Itinerary $itinerary, array $windows, string $timezone): void
    {
        foreach ($windows as $i => $window) {
            abort_unless($window->isPositive(), 422, 'Each stop has to end after it starts.');
            abort_if($i > 0 && $window->overlaps($windows[$i - 1]), 422, 'The stops of an evening cannot overlap.');
        }

        $existing = ItineraryItem::query()
            ->whereIn('itinerary_day_id', $itinerary->days()->select('id'))
            ->where('kind', '!=', 'anchor')
            ->where('starts_at', '<', end($windows)->end)
            ->where('ends_at', '>', $windows[0]->start)
            ->orderBy('starts_at')
            ->get();

        foreach ($existing as $item) {
            foreach ($windows as $window) {
                if ($window->overlaps($item->window())) {
                    abort(422, sprintf(
                        'That evening overlaps "%s", already in your trip from %s to %s.',
                        $item->title,
                        $item->starts_at->setTimezone($timezone)->format('H:i'),
                        $item->ends_at->setTimezone($timezone)->format('H:i'),
                    ));
                }
            }
        }
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
