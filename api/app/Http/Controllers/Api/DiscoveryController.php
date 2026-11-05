<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\AI\Services\IntentParser;
use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Destinations\Services\CityResolver;
use App\Domains\Destinations\Services\EnsureDestinationReadyForDiscovery;
use App\Domains\Discovery\Services\EventFinder;
use App\Domains\Discovery\Services\LiveIntentStore;
use App\Domains\Discovery\Services\NameRelevance;
use App\Domains\Discovery\Services\PrimaryActionComposer;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Experiences\Services\ExperiencePresenter;
use App\Domains\Itineraries\DTO\DateNightStop;
use App\Domains\Itineraries\Services\DateNightPlanner;
use App\Domains\Itineraries\Services\DateNightUnavailable;
use App\Domains\Recommendations\DTO\ScoredExperience;
use App\Domains\Recommendations\Services\RecommendationService;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        private readonly LiveIntentStore $liveIntents,
        private readonly PrimaryActionComposer $primary,
        private readonly DateNightPlanner $dateNights,
        private readonly CityResolver $cities,
        private readonly EventFinder $eventFinder,
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
        if (($payload['data'][0]['id'] ?? null) !== ($payload['primary']['experience_id'] ?? null)) {
            $payload['primary'] = $payload['data'] === []
                ? null
                : $this->primary->fromCard($payload['data'][0]);
        }
        $payload['interpreted'] = [
            'understood_as' => $parsed['echo'],
            'filters' => $input,
        ];

        return response()->json($payload);
    }

    /** Clears fields on the live intent without touching the rest of it. */
    public function intent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clear' => ['required', 'array', 'min:1'],
            'clear.*' => [Rule::in(['mood', 'window_minutes', 'categories', 'exclude_categories', 'free_only'])],
            'journey_id' => ['sometimes', 'nullable', 'uuid'],
            'destination_id' => ['sometimes', 'nullable', 'uuid'],
            'destination' => ['sometimes', 'nullable', 'string'],
        ]);

        $this->liveIntents->clear($this->actor($request), $data, $data['clear']);

        return response()->json(['status' => 'cleared']);
    }

    /**
     * An evening for two, tonight or on a later date.
     *
     * Not recorded as live intent, and not shaped by it: planning next
     * week's date must leave today's Discover exactly as it was.
     */
    public function dateNight(Request $request): JsonResponse
    {
        $options = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:120', 'max:360'],
            'budget' => ['sometimes', 'nullable', Rule::in(array_keys(config('experience.date_night.budgets')))],
            'include_nightcap' => ['sometimes', 'boolean'],
        ]);

        try {
            $plan = $this->dateNights->plan($this->actor($request), $this->common($request, 'date_night'), $options);
        } catch (DateNightUnavailable $e) {
            return response()->json(['code' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        $weather = $plan->context->weather;

        return response()->json([
            'data' => $plan->stops === [] ? null : [
                'date' => $plan->evening->start->toDateString(),
                'starts_at' => $plan->evening->start->toIso8601String(),
                'ends_at' => $plan->evening->end->toIso8601String(),
                'stops' => array_map(fn (DateNightStop $stop) => [
                    'role' => $stop->role,
                    'starts_at' => $stop->startsAt->toIso8601String(),
                    'ends_at' => $stop->endsAt->toIso8601String(),
                    'travel_minutes_from_previous' => $stop->travelMinutesFromPrevious,
                    'travel_mode' => $stop->travelMode,
                    'experience' => $this->dateNightCard($stop->scored),
                    'alternatives' => array_map(
                        fn (ScoredExperience $alt) => $this->dateNightCard($alt),
                        $stop->alternatives,
                    ),
                ], $plan->stops),
                'weather' => $weather?->toArray(),
                'weather_note' => $weather === null ? 'The forecast arrives closer to the day.' : null,
            ],
            'recommendation_set_id' => $plan->set->id,
            'context' => $this->contextPayload($plan->context),
            'candidates_considered' => $plan->set->candidates_considered,
            'notice' => $plan->notice,
        ]);
    }

    /**
     * A date-night stop's card, plus what it takes to get a table: whether the
     * place needs booking, how far ahead, and how to reach it. It rides on each
     * card rather than the stop, so a swap brings its own.
     *
     * @return array<string, mixed>
     */
    private function dateNightCard(ScoredExperience $scored): array
    {
        $experience = $scored->candidate->experience;
        $place = $experience->place;

        return $this->presenter->card($experience, $scored) + [
            'booking' => [
                'required' => (bool) $experience->requires_booking,
                'lead_time_hours' => $experience->booking_lead_time_hours,
                'phone' => $place?->phone,
                'website' => $place?->website,
            ],
        ];
    }

    /**
     * Explore my city: what is on, and what is always good, wherever the
     * phone is — not wherever the trip says.
     *
     * Events do not depend on our catalogue, so they come back even while the
     * city is still being prepared or is not covered at all; the experience
     * sections fill in once it is. Like date night, this neither records nor
     * reads live intent: a mood picked on Discover is not a filter here.
     */
    public function exploreCity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'location_precision' => ['sometimes', Rule::in(['precise', 'approximate'])],
            'journey_id' => ['sometimes', 'nullable', 'uuid'],
            'when' => ['sometimes', Rule::in(EventFinder::RANGES)],
        ]);

        $actor = $this->actor($request);
        $point = new GeoPoint((float) $data['lat'], (float) $data['lng']);
        /* Approximate is enforced here, not trusted to the app: the city
           match, the geocoder and the events provider only see ~1 km. */
        if (($data['location_precision'] ?? 'precise') === 'approximate') {
            $point = new GeoPoint(round($point->lat, 2), round($point->lng, 2));
        }
        $range = $data['when'] ?? 'today';
        $destination = $this->cities->nearest($point);
        $candidate = $destination === null ? $this->cities->candidate($point, $actor) : null;
        $timezone = $destination?->timezone ?? $this->cities->timezoneFor($point, $candidate['country_code'] ?? null);
        $now = CarbonImmutable::now($timezone);

        $preparing = null;
        $sections = null;
        $context = null;
        $setId = null;

        if ($destination !== null) {
            $input = array_filter([
                'surface' => 'explore_city',
                'lat' => $point->lat,
                'lng' => $point->lng,
                'location_precision' => $data['location_precision'] ?? null,
                'destination_id' => $destination->id,
                'journey_id' => $data['journey_id'] ?? null,
            ], fn ($v) => $v !== null);

            try {
                $this->destinationReadiness->handle($actor, $input);
            } catch (HttpResponseException $e) {
                /* Not an error here: the events still stand, and the app
                   polls the import and asks again once the city is ready. */
                $preparing = $e->getResponse()->getData(true);
            }

            if ($preparing === null) {
                [$sections, $context, $setId] = $this->exploreSections($actor, $input);
            }
        }

        $events = $this->eventFinder->near($point, $range, $now);

        /* The app asks again every few seconds while a city is prepared, and
           again on each range chip; one visit is one open. */
        $openedKey = sprintf('explore_city_opened:%s:%s', $actor->key(), $destination?->id ?? sprintf('%.2f,%.2f', $point->lat, $point->lng));
        if (Cache::add($openedKey, true, (int) config('experience.explore_city.opened_dedupe_seconds', 600))) {
            $this->events->record($actor, 'explore_city_opened', [
                'subject_type' => $destination === null ? null : 'destination',
                'subject_id' => $destination?->id,
                'surface' => 'explore_city',
                'properties' => [
                    'when' => $range,
                    'covered' => $destination !== null,
                    'events' => count($events),
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'city' => $destination === null ? null : [
                    'destination_id' => $destination->id,
                    'slug' => $destination->slug,
                    'name' => $destination->name,
                    'country' => $destination->country,
                    'timezone' => $destination->timezone,
                    'currency' => $destination->currency,
                ],
                'candidate' => $candidate,
                'timezone' => $timezone,
                'preparing' => $preparing,
                'when' => $range,
                'events' => $events,
                'events_available' => $this->eventFinder->available(),
                'sections' => $sections,
                'weather' => $context?->weather?->toArray(),
            ],
            'recommendation_set_id' => $setId,
            'context' => $context === null ? null : $this->contextPayload($context),
        ]);
    }

    /**
     * Top picks, hidden gems, free, and the best few neighbourhoods.
     *
     * Each list is asked for explicitly with every intent field set, so the
     * traveller's live mood or window cannot leak in through the merge.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: \App\Domains\Recommendations\DTO\ScoringContext, 2: string}
     */
    private function exploreSections(Actor $actor, array $input): array
    {
        $input += [
            'mood' => null,
            'window_minutes' => null,
            'categories' => [],
            'exclude_categories' => [],
            'free_only' => false,
        ];
        $size = (int) config('experience.explore_city.section_size', 6);
        $cards = fn (array $results) => array_map(
            fn (ScoredExperience $scored) => $this->presenter->card($scored->candidate->experience, $scored),
            $results,
        );

        $top = $this->recommendations->recommend($actor, $input, 30);
        /* Asked for twice what a row shows, since the ones already shown
           higher up are taken out. */
        $gems = $this->recommendations->recommend($actor, array_merge($input, ['categories' => ['hidden_gem']]), $size * 2);
        $free = $this->recommendations->recommend($actor, array_merge($input, ['free_only' => true]), $size * 2);

        /* Each row shows only what no row above it has: the same five
           favourites repeated four times down the page reads as a short list. */
        $shown = [];
        $fresh = function (array $cards, int $limit) use (&$shown): array {
            $kept = array_slice(array_values(array_filter($cards, fn (array $card) => ! isset($shown[$card['id']]))), 0, $limit);
            foreach ($kept as $card) {
                $shown[$card['id']] = true;
            }

            return $kept;
        };

        $topCards = $cards($top['results']);
        $topPicks = $fresh($topCards, $size);
        /* Relaxed answers are the closest fit, not a hidden gem or a free
           one: better an empty row than a mislabelled one. */
        $hiddenGems = in_array('categories', $gems['relaxed'] ?? [], true) ? [] : $fresh($cards($gems['results']), $size);
        $freeCards = $fresh($cards($free['results']), $size);

        /* Neighbourhoods ranked by their best remaining experience, so the
           strongest areas lead; each keeps a handful of its own. */
        $byNeighbourhood = collect($topCards)
            ->reject(fn (array $card) => isset($shown[$card['id']]))
            ->filter(fn (array $card) => filled($card['location']['neighbourhood'] ?? null))
            ->groupBy(fn (array $card) => $card['location']['neighbourhood'])
            ->take((int) config('experience.explore_city.neighbourhoods', 3))
            ->map(fn ($items, string $name) => [
                'neighbourhood' => $name,
                'items' => $items->take((int) config('experience.explore_city.per_neighbourhood', 3))->values()->all(),
            ])
            ->values()
            ->all();

        return [
            [
                'top_picks' => $topPicks,
                'hidden_gems' => $hiddenGems,
                'free' => $freeCards,
                'by_neighbourhood' => $byNeighbourhood,
            ],
            $top['context'],
            $top['set']->id,
        ];
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
        $this->liveIntents->recordDiscovery($this->actor($request), $input);
        $result = $this->recommendations->recommend($this->actor($request), $input, $limit);
        $destinationId = $input['destination_id'] ?? $result['context']->journey?->destination_id;
        $catalogueEmpty = is_string($destinationId) && ! $this->destinationHasPublishedExperiences($destinationId);
        $cards = array_map(
            fn (ScoredExperience $scored) => $this->presenter->card($scored->candidate->experience, $scored),
            $result['results'],
        );

        return response()->json([
            'data' => $cards,
            'primary' => $result['results'] === []
                ? null
                : $this->primary->compose($result['results'][0]),
            'recommendation_set_id' => $result['set']->id,
            'context' => $this->contextPayload($result['context']),
            'candidates_considered' => $result['set']->candidates_considered,
            'active_intent' => $result['intent'],
            'catalogue_empty' => $catalogueEmpty,
            'notice' => $this->notice(
                $result['relaxed'] ?? [],
                $result['results'],
                $catalogueEmpty,
            ),
        ]);
    }

    /** Say plainly when we had to loosen the request — or when the catalogue is empty. */
    private function notice(array $relaxed, array $results, bool $catalogueEmpty): ?string
    {
        if ($results === [] && $catalogueEmpty) {
            return 'This city does not have experiences loaded yet. Try another covered city, or check back once the catalogue is filled.';
        }

        if ($results === []) {
            return 'Nothing here fits all of that. Loosening the time, the budget or the distance would open it up.';
        }

        return match (true) {
            $relaxed === ['distance'] => 'Nothing matched within walking distance, so this reaches a little further out.',
            in_array('categories', $relaxed, true) => 'Nothing matched that exactly, so these are the closest fit on everything else you asked for.',
            default => null,
        };
    }

    private function destinationHasPublishedExperiences(string $destinationId): bool
    {
        return Experience::query()
            ->where('destination_id', $destinationId)
            ->where('status', 'published')
            ->exists();
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
