<?php

declare(strict_types=1);

namespace App\Domains\Itineraries\Services;

use App\Domains\Discovery\Services\CandidateBuilder;
use App\Domains\Discovery\Services\ContextEngine;
use App\Domains\ExternalSources\Contracts\RoutingProvider;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Journeys\Models\JourneyAnchor;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\ExperienceScorer;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\GeoPoint;
use App\Domains\Shared\ValueObjects\TimeWindow;
use App\Domains\Trips\Models\Itinerary;
use App\Domains\Trips\Models\ItineraryItem;
use App\Domains\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Itinerary generation as constrained optimisation (spec s8).
 *
 * Anchors are immutable: the planner carves the day into the free windows
 * *between* them and fills those windows with feasible, well-scoring
 * experiences. Feasibility — travel time, opening hours, budget, buffers — is
 * computed from structured data, never generated as text. Before anything is
 * written, the result is checked against the anchors again (acceptance 56).
 */
class ItineraryPlanner
{
    public function __construct(
        private readonly ContextEngine $contextEngine,
        private readonly CandidateBuilder $candidateBuilder,
        private readonly ExperienceScorer $scorer,
        private readonly RoutingProvider $routing,
    ) {}

    public function generate(Actor $actor, Trip $trip, array $options = []): Itinerary
    {
        $journey = $trip->journey()->with(['destination', 'anchors'])->firstOrFail();
        $dates = $this->dateRange($journey, $options);

        $baseContext = $this->contextEngine->build($actor, array_merge($options, [
            'journey_id' => $journey->id,
            'surface' => 'itinerary',
        ]));

        $plans = [];
        $placed = [];

        foreach ($dates as $date) {
            $plan = $this->planDay($journey, $date, $baseContext, $placed, $options);
            $plans[] = $plan;

            foreach ($plan->items as $item) {
                if ($item['kind'] === 'experience') {
                    $placed[] = $item['experience_id'];
                }
            }
        }

        return $this->persist($trip, $plans, $journey, (bool) ($options['make_current'] ?? true));
    }

    /** @param list<string> $alreadyPlaced */
    private function planDay(
        Journey $journey,
        CarbonImmutable $date,
        ScoringContext $baseContext,
        array $alreadyPlaced,
        array $options,
    ): DayPlan {
        $plan = new DayPlan($date);
        $timezone = $journey->destination->timezone;

        $anchors = $journey->anchors->filter(
            fn (JourneyAnchor $a) => $a->starts_at->setTimezone($timezone)->isSameDay($date),
        )->values();

        foreach ($anchors as $anchor) {
            $plan->add([
                'kind' => 'anchor',
                'experience_id' => null,
                'journey_anchor_id' => $anchor->id,
                'title' => $anchor->title,
                'starts_at' => CarbonImmutable::parse($anchor->starts_at),
                'ends_at' => CarbonImmutable::parse($anchor->ends_at),
                'locked' => true,
                'score' => null,
                'reason' => 'Fixed commitment',
                'travel_minutes_from_previous' => 0,
                'travel_mode' => null,
                'meta' => [],
            ]);
        }

        $windows = $this->freeWindows($date, $anchors, $journey, $options);

        $maxItems = config('experience.itinerary.max_items_per_day')[$journey->pace_override ?? $baseContext->profile?->travel_pace ?? 'moderate'] ?? 3;
        $gap = (int) config('experience.itinerary.min_gap_between_items_minutes', 10);

        $pool = $this->candidateBuilder->build(
            $baseContext->at($date->setTime(10, 0)),
            array_merge($options, ['destination_id' => $journey->destination_id, 'limit' => 200, 'radius_metres' => 20000]),
        );

        $pool = array_values(array_filter(
            $pool,
            fn (ExperienceCandidate $c) => ! in_array($c->experience->id, $alreadyPlaced, true),
        ));

        $budgetRemaining = $journey->daily_budget_minor;
        $usedCategories = [];
        $consecutiveHighEnergy = 0;

        foreach ($windows as $window) {
            $cursor = $window->start;
            $position = $this->startingPointFor($window, $anchors, $journey);

            while ($plan->experienceCount() < $maxItems) {
                $remaining = new TimeWindow($cursor, $window->end);
                if ($remaining->minutes() < 30) {
                    break;
                }

                $best = $this->bestFor(
                    $pool,
                    $baseContext->at($cursor, $position, $remaining, $budgetRemaining)
                        ->withAnchor($this->anchorAfter($anchors, $cursor)),
                    $usedCategories,
                    $consecutiveHighEnergy,
                    $position,
                    $remaining,
                );

                if ($best === null) {
                    break;
                }

                [$candidate, $score, $travelMinutes] = $best;
                $start = $cursor->addMinutes($travelMinutes);
                $end = $start->addMinutes($candidate->experience->expected_duration_minutes);

                $plan->add([
                    'kind' => 'experience',
                    'experience_id' => $candidate->experience->id,
                    'journey_anchor_id' => null,
                    'title' => $candidate->experience->title,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'locked' => false,
                    'score' => $score,
                    'reason' => $this->reasonFor($candidate, $baseContext),
                    'travel_minutes_from_previous' => $travelMinutes,
                    'travel_mode' => $candidate->travel?->mode,
                    'meta' => ['is_food' => ($candidate->experience->interest_affinity['food'] ?? 0) >= 70],
                ]);

                $plan->objective += $score;
                $pool = array_values(array_filter($pool, fn ($c) => $c->experience->id !== $candidate->experience->id));
                $usedCategories = array_merge($usedCategories, $candidate->categoryKeys);
                $consecutiveHighEnergy = $candidate->experience->energy_level === 'high' ? $consecutiveHighEnergy + 1 : 0;

                if ($candidate->experience->price_from_minor !== null && $budgetRemaining !== null) {
                    $budgetRemaining = max(0, $budgetRemaining - $candidate->experience->price_from_minor * max(1, $journey->partySize()));
                }

                $position = $candidate->point ?? $position;
                $cursor = $end->addMinutes($gap);
            }
        }

        $this->insertMeals($plan, $date, $journey);
        $plan->diagnostics = [
            'free_windows' => array_map(fn (TimeWindow $w) => $w->toArray(), $windows),
            'anchors' => $anchors->count(),
            'pool_size' => count($pool),
            'max_items' => $maxItems,
        ];

        return $plan;
    }

    /**
     * Pick the best feasible candidate for the moment.
     *
     * @return array{0: ExperienceCandidate, 1: int, 2: int}|null
     */
    private function bestFor(
        array $pool,
        ScoringContext $context,
        array $usedCategories,
        int $consecutiveHighEnergy,
        ?GeoPoint $position,
        TimeWindow $remaining,
    ): ?array {
        $beam = (int) config('experience.itinerary.beam_width', 4);
        $energyLimit = (int) config('experience.itinerary.consecutive_high_energy_limit', 2);

        $feasible = [];

        foreach ($pool as $candidate) {
            if ($candidate->point === null) {
                continue;
            }

            $travel = $position === null
                ? 0
                : $this->routing->estimate($position, $candidate->point, $context->walkingTolerance)->minutes;

            $start = $remaining->start->addMinutes($travel);
            $end = $start->addMinutes($candidate->experience->expected_duration_minutes);

            if ($end > $remaining->end) {
                continue;
            }

            /* Must actually be open for the whole planned visit. */
            $openAtStart = $candidate->openingHours->isOpenAt($start);
            if ($openAtStart === false) {
                continue;
            }
            if ($openAtStart === true) {
                $closes = $candidate->openingHours->closesAt($start);
                if ($closes !== null && $end > $closes) {
                    continue;
                }
            }

            if ($consecutiveHighEnergy >= $energyLimit && $candidate->experience->energy_level === 'high') {
                continue;
            }

            $feasible[] = [$candidate, $travel, $start];
        }

        if ($feasible === []) {
            return null;
        }

        /* Score the shortlist in its actual planned slot, then apply the
           planner's own penalties: travel cost and repetition. */
        $scored = [];
        foreach (array_slice($feasible, 0, max($beam * 8, 40)) as [$candidate, $travel, $start]) {
            $slotContext = $context->at($start, $position, new TimeWindow($start, $remaining->end));
            $result = $this->scorer->score($candidate, $slotContext);

            $penalty = $travel * 0.35;
            $repeats = count(array_intersect($candidate->categoryKeys, $usedCategories));
            $penalty += $repeats * 4;

            $scored[] = [$candidate, $result->score, $travel, $result->score - $penalty];
        }

        usort($scored, fn (array $a, array $b) => $b[3] <=> $a[3]);

        $winner = $scored[0];

        /* A slot is only worth filling if the option is genuinely decent. */
        if ($winner[1] < 40) {
            return null;
        }

        return [$winner[0], $winner[1], $winner[2]];
    }

    /** @return list<TimeWindow> */
    private function freeWindows(CarbonImmutable $date, $anchors, Journey $journey, array $options): array
    {
        $timezone = $journey->destination->timezone;
        $dayStart = $date->setTimeFromTimeString($options['day_start'] ?? config('experience.itinerary.default_day_start'));
        $dayEnd = $date->setTimeFromTimeString($options['day_end'] ?? config('experience.itinerary.default_day_end'));

        $blocked = $anchors
            ->map(fn (JourneyAnchor $a) => $a->blockedWindow())
            ->sortBy(fn (TimeWindow $w) => $w->start)
            ->values();

        $windows = [];
        $cursor = $dayStart;

        foreach ($blocked as $block) {
            if ($block->start > $cursor) {
                $windows[] = new TimeWindow($cursor, $block->start->min($dayEnd));
            }
            $cursor = $cursor->max($block->end);
        }

        if ($cursor < $dayEnd) {
            $windows[] = new TimeWindow($cursor, $dayEnd);
        }

        return array_values(array_filter(
            $windows,
            fn (TimeWindow $w) => $w->isPositive() && $w->minutes() >= 45,
        ));
    }

    private function startingPointFor(TimeWindow $window, $anchors, Journey $journey): ?GeoPoint
    {
        /* Start from wherever the previous anchor left the traveller. */
        $previous = $anchors
            ->filter(fn (JourneyAnchor $a) => $a->ends_at <= $window->start)
            ->sortByDesc('ends_at')
            ->first();

        return $previous?->point() ?? $journey->destination->point();
    }

    private function anchorAfter($anchors, CarbonImmutable $moment): ?JourneyAnchor
    {
        return $anchors->filter(fn (JourneyAnchor $a) => $a->starts_at > $moment)->sortBy('starts_at')->first();
    }

    private function insertMeals(DayPlan $plan, CarbonImmutable $date, Journey $journey): void
    {
        foreach (config('experience.itinerary.meal_windows') as $meal => [$from, $to]) {
            if ($plan->hasMeal($meal)) {
                continue;
            }

            $start = $date->setTimeFromTimeString($from);
            $end = $date->setTimeFromTimeString($to);
            $slot = new TimeWindow($start, $start->addMinutes(60));

            $clashes = false;
            foreach ($plan->occupiedWindows() as $occupied) {
                if ($occupied->overlaps($slot)) {
                    $clashes = true;
                    break;
                }
            }

            if ($clashes || $slot->end > $end) {
                continue;
            }

            $plan->add([
                'kind' => 'meal',
                'experience_id' => null,
                'journey_anchor_id' => null,
                'title' => ucfirst($meal),
                'starts_at' => $slot->start,
                'ends_at' => $slot->end,
                'locked' => false,
                'score' => null,
                'reason' => 'Kept free so the day is realistic',
                'travel_minutes_from_previous' => 0,
                'travel_mode' => null,
                'meta' => ['meal' => $meal],
            ]);
        }
    }

    private function reasonFor(ExperienceCandidate $candidate, ScoringContext $context): string
    {
        $result = $this->scorer->score($candidate, $context);
        $positive = $result->reasons('positive');

        return $positive === []
            ? 'Fits the shape of this day'
            : implode('. ', array_slice(array_map(fn ($r) => $r->message, $positive), 0, 2));
    }

    /** @param list<DayPlan> $plans */
    private function persist(Trip $trip, array $plans, Journey $journey, bool $makeCurrent = true): Itinerary
    {
        $this->assertNoAnchorConflicts($plans, $journey);

        return DB::transaction(function () use ($trip, $plans, $makeCurrent) {
            if ($makeCurrent) {
                $trip->itineraries()->update(['is_current' => false]);
            }
            $version = (int) $trip->itineraries()->max('version') + 1;

            $itinerary = $trip->itineraries()->create([
                'version' => $version,
                'generated_at' => CarbonImmutable::now(),
                'engine_version' => config('experience.itinerary.engine_version'),
                'objective_value' => array_sum(array_map(fn (DayPlan $p) => $p->objective, $plans)),
                'diagnostics' => ['days' => array_map(fn (DayPlan $p) => $p->diagnostics, $plans)],
                'is_current' => $makeCurrent,
            ]);

            foreach ($plans as $plan) {
                $day = $itinerary->days()->create([
                    'date' => $plan->date->toDateString(),
                    'summary' => $this->summarise($plan),
                ]);

                foreach ($plan->items as $sort => $item) {
                    $day->items()->create([
                        'kind' => $item['kind'],
                        'experience_id' => $item['experience_id'],
                        'journey_anchor_id' => $item['journey_anchor_id'],
                        'title' => $item['title'],
                        'starts_at' => $item['starts_at'],
                        'ends_at' => $item['ends_at'],
                        'travel_minutes_from_previous' => $item['travel_minutes_from_previous'],
                        'travel_mode' => $item['travel_mode'],
                        'locked' => $item['locked'],
                        'score' => $item['score'],
                        'reason' => $item['reason'],
                        'sort' => $sort,
                    ]);
                }
            }

            return $itinerary->fresh(['days.items']);
        });
    }

    /** @param list<DayPlan> $plans */
    private function assertNoAnchorConflicts(array $plans, Journey $journey): void
    {
        $blocked = $journey->anchors->map(fn (JourneyAnchor $a) => [$a, $a->blockedWindow()]);

        foreach ($plans as $plan) {
            foreach ($plan->items as $item) {
                if ($item['kind'] === 'anchor') {
                    continue;
                }

                $window = new TimeWindow($item['starts_at'], $item['ends_at']);

                foreach ($blocked as [$anchor, $blockedWindow]) {
                    if ($window->overlaps($blockedWindow)) {
                        throw new DomainException(sprintf(
                            'Planner produced "%s" (%s-%s) overlapping the protected window of anchor "%s".',
                            $item['title'],
                            $window->start->format('H:i'),
                            $window->end->format('H:i'),
                            $anchor->title,
                        ));
                    }
                }
            }
        }
    }

    private function summarise(DayPlan $plan): string
    {
        $experiences = $plan->experienceCount();
        $anchors = count(array_filter($plan->items, fn ($i) => $i['kind'] === 'anchor'));

        return match (true) {
            $experiences === 0 && $anchors > 0 => 'Built around your fixed commitments',
            $experiences === 0 => 'Nothing scheduled yet',
            default => sprintf('%d experience%s planned around %d fixed commitment%s', $experiences, $experiences === 1 ? '' : 's', $anchors, $anchors === 1 ? '' : 's'),
        };
    }

    /** @return list<CarbonImmutable> */
    private function dateRange(Journey $journey, array $options): array
    {
        $timezone = $journey->destination->timezone;
        $start = isset($options['from'])
            ? CarbonImmutable::parse($options['from'], $timezone)
            : ($journey->starts_on ? CarbonImmutable::parse($journey->starts_on, $timezone) : CarbonImmutable::now($timezone));
        $end = isset($options['to'])
            ? CarbonImmutable::parse($options['to'], $timezone)
            : ($journey->ends_on ? CarbonImmutable::parse($journey->ends_on, $timezone) : $start->addDays(2));

        $dates = [];
        for ($d = $start->startOfDay(); $d <= $end->startOfDay() && count($dates) < 14; $d = $d->addDay()) {
            $dates[] = $d;
        }

        return $dates;
    }
}
