<?php

declare(strict_types=1);

namespace App\Domains\Itineraries\Services;

use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Discovery\Services\CandidateBuilder;
use App\Domains\Discovery\Services\ContextEngine;
use App\Domains\ExternalSources\Contracts\RoutingProvider;
use App\Domains\Itineraries\DTO\DateNightPlan;
use App\Domains\Itineraries\DTO\DateNightStop;
use App\Domains\Journeys\Models\JourneyAnchor;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\ScoredExperience;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Models\Recommendation;
use App\Domains\Recommendations\Models\RecommendationReason;
use App\Domains\Recommendations\Models\RecommendationSet;
use App\Domains\Recommendations\Scoring\ExperienceScorer;
use App\Domains\Recommendations\Scoring\MoodBlend;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\GeoPoint;
use App\Domains\Shared\ValueObjects\TimeWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An evening for two, tonight or on a later date: a first stop, dinner, and a
 * nightcap, each timed, open when they get there, and close to the last.
 *
 * Every stop is scored at the moment it actually starts, from where the
 * previous one ended — the same discipline as the itinerary planner, so
 * "open" means open at 21:30 on that Saturday, not open now.
 *
 * This never reads or writes live intent. A date planned for next week must
 * not leave today's Discover tinted romantic, and today's "1 hour" must not
 * shrink next week's evening.
 */
class DateNightPlanner
{
    private const SLOT_PENALTY_PER_TRAVEL_MINUTE = 0.35;

    private const MIN_SCORE = 40;

    public function __construct(
        private readonly ContextEngine $contextEngine,
        private readonly CandidateBuilder $candidates,
        private readonly ExperienceScorer $scorer,
        private readonly MoodBlend $moodBlend,
        private readonly RoutingProvider $routing,
        private readonly RecordBehaviouralEvent $events,
    ) {}

    /**
     * @param  array<string, mixed>  $input  The usual discovery input (location, journey, destination).
     * @param  array{date: string, starts_at?: ?string, duration_minutes?: ?int, budget?: ?string, include_nightcap?: ?bool}  $options
     *
     * @throws DateNightUnavailable
     */
    public function plan(Actor $actor, array $input, array $options): DateNightPlan
    {
        $started = microtime(true);
        $config = config('experience.date_night');

        $context = $this->contextEngine->build($actor, array_merge($input, [
            'now' => $options['date'] . ' ' . ($options['starts_at'] ?? $config['default_start']),
            'surface' => 'date_night',
            'mood' => 'romantic',
            'adults' => 2,
            'children' => 0,
            'child_ages' => [],
        ]));

        $evening = $this->evening($context, $options, $config);
        $base = $context->at($evening->start, null, $evening)->withAnchor(null);

        $pool = $this->candidates->build($base, array_filter([
            'destination_id' => $input['destination_id'] ?? $context->journey?->destination_id,
            'radius_metres' => $input['radius_metres'] ?? 15000,
            'max_price_minor' => $config['budgets'][$options['budget'] ?? 'relaxed'] ?? null,
            'exclude_categories' => $input['exclude_categories'] ?? null,
            'limit' => 300,
        ], fn ($v) => $v !== null));

        $includeNightcap = (bool) ($options['include_nightcap'] ?? true);
        $withOpener = $evening->minutes() >= (int) $config['opener_min_evening_minutes'];

        $stops = $this->sequence($pool, $base, $evening, $withOpener, $includeNightcap, $config);

        /* A first stop that ate the time dinner needed is the wrong trade:
           dinner is the evening, the opener is a nice-to-have. */
        if ($withOpener && ! $this->hasRole($stops, 'dinner')) {
            $withoutOpener = $this->sequence($pool, $base, $evening, false, $includeNightcap, $config);
            if ($this->hasRole($withoutOpener, 'dinner')) {
                $stops = $withoutOpener;
            }
        }

        $notice = match (true) {
            $stops === [] => 'Nothing fits that evening. An earlier start or a bigger budget would open more up.',
            ! $this->hasRole($stops, 'dinner') => 'No dinner spot fits that evening, so this is the rest of it. An earlier start would open more up.',
            default => null,
        };

        $set = $this->persist($actor, $base, $input, $options, count($pool), $stops, $started);

        return new DateNightPlan($base, $evening, $stops, $set, $notice);
    }

    /**
     * The window the evening actually has, in the destination's time.
     *
     * @throws DateNightUnavailable
     */
    private function evening(ScoringContext $context, array $options, array $config): TimeWindow
    {
        $requested = $context->now;
        $timezone = $requested->getTimezone();
        $localNow = CarbonImmutable::now($timezone);
        $day = $requested->startOfDay();
        $today = $localNow->startOfDay();

        if ($day < $today) {
            throw new DateNightUnavailable('date_night_in_past', 'That date has already gone. Pick tonight or a later date.');
        }

        $endsOn = $context->journey?->ends_on;
        if ($day > $today->addDays((int) $config['max_days_ahead'])
            || ($endsOn !== null && $day->toDateString() > $endsOn->toDateString())) {
            throw new DateNightUnavailable('date_night_out_of_range', 'That date is outside your trip. Pick a date while you are there.');
        }

        $start = $requested;
        $tonight = $day->equalTo($today);

        /* Tonight, from a start that has already passed: begin at the next
           quarter hour rather than planning a stop the couple has missed. */
        if ($tonight && $start < $localNow) {
            $start = $localNow->ceilMinutes(15);
        }

        $duration = (int) ($options['duration_minutes'] ?? $config['default_duration_minutes']);
        $latest = $day->setTimeFromTimeString($config['latest_end']);
        $end = $start->addMinutes($duration)->min($latest);

        $evening = $this->aroundAnchors($context, new TimeWindow($start, $end), $config);

        if (! $evening->isPositive() || $evening->minutes() < (int) $config['min_tonight_minutes']) {
            throw new DateNightUnavailable('date_night_too_late', $tonight
                ? 'Tonight is nearly over. Plan for tomorrow instead?'
                : 'That leaves too little of the evening. Try an earlier start.');
        }

        return $evening;
    }

    /** Fixed commitments win: start after one that covers the start, end before one that follows. */
    private function aroundAnchors(ScoringContext $context, TimeWindow $evening, array $config): TimeWindow
    {
        $anchors = $context->journey?->anchors ?? collect();

        foreach ($anchors->sortBy('starts_at') as $anchor) {
            /** @var JourneyAnchor $anchor */
            $blocked = $anchor->blockedWindow();
            if (! $blocked->overlaps($evening)) {
                continue;
            }

            $evening = $blocked->start <= $evening->start
                ? new TimeWindow($blocked->end->max($evening->start), $evening->end)
                : new TimeWindow($evening->start, $blocked->start);

            if (! $evening->isPositive()) {
                throw new DateNightUnavailable('date_night_clashes', 'Something already in your plans takes that evening.');
            }
        }

        return $evening;
    }

    /**
     * @param  list<ExperienceCandidate>  $pool
     * @return list<DateNightStop>
     */
    private function sequence(
        array $pool,
        ScoringContext $base,
        TimeWindow $evening,
        bool $withOpener,
        bool $includeNightcap,
        array $config,
    ): array {
        $roles = array_values(array_filter([
            $withOpener ? 'opener' : null,
            'dinner',
            $includeNightcap ? 'nightcap' : null,
        ]));

        $stops = [];
        $used = [];
        $cursor = $evening->start;
        $position = $base->location;

        foreach ($roles as $role) {
            $stop = $this->bestFor($role, $pool, $base, $cursor, $position, $evening->end, $used, $config, $stops === []);
            if ($stop === null) {
                continue;
            }

            $stops[] = $stop;
            $used[] = $stop->scored->candidate->experience->id;
            $cursor = $stop->endsAt;
            $position = $stop->scored->candidate->point ?? $position;
        }

        return $stops;
    }

    /** @param list<ExperienceCandidate> $pool */
    private function bestFor(
        string $role,
        array $pool,
        ScoringContext $base,
        CarbonImmutable $cursor,
        ?GeoPoint $position,
        CarbonImmutable $latest,
        array $used,
        array $config,
        bool $first,
    ): ?DateNightStop {
        $slot = $config['stops'][$role];
        $feasible = [];

        foreach ($pool as $candidate) {
            if ($candidate->point === null
                || in_array($candidate->experience->id, $used, true)
                || $this->roleOf($candidate, $config) !== $role) {
                continue;
            }

            $travel = $position === null
                ? null
                : $this->routing->estimate($position, $candidate->point, $base->walkingTolerance);

            /* The first stop is where the couple meets; travel there is not
               part of the evening. Between stops it is. */
            $minutes = $first ? 0 : ($travel?->minutes ?? 0);

            if ($role === 'nightcap' && $minutes > (int) $config['max_nightcap_travel_minutes']) {
                continue;
            }

            $duration = max($slot['min'], min($slot['max'], $candidate->experience->expected_duration_minutes));
            if ($candidate->experience->min_duration_minutes > $duration) {
                continue;
            }

            $start = $cursor->addMinutes($minutes);
            $end = $start->addMinutes($duration);
            if ($end > $latest) {
                continue;
            }

            /* Open for the whole visit at that hour on that day. Unknown
               hours are let through and scored down, as everywhere else. */
            $open = $candidate->openingHours->isOpenAt($start);
            if ($open === false) {
                continue;
            }
            if ($open === true) {
                $closes = $candidate->openingHours->closesAt($start);
                if ($closes !== null && $end > $closes) {
                    continue;
                }
            }

            $scored = $this->scorer->score($candidate, $base->at($start, $position, new TimeWindow($start, $latest)));
            $feasible[$candidate->experience->id] = [$scored, $start, $end, $first ? null : $travel];
        }

        if ($feasible === []) {
            return null;
        }

        $ranked = $this->moodBlend->apply(array_column($feasible, 0), $base);

        $ranked = array_values(array_filter($ranked, fn (ScoredExperience $s) => $s->score >= self::MIN_SCORE));
        if ($ranked === []) {
            return null;
        }

        usort($ranked, function (ScoredExperience $a, ScoredExperience $b) use ($feasible) {
            return $this->slotValue($b, $feasible) <=> $this->slotValue($a, $feasible)
                ?: strcmp($a->candidate->experience->id, $b->candidate->experience->id);
        });

        $winner = $ranked[0];
        $rest = array_slice($ranked, 1);
        [, $start, $end, $travel] = $feasible[$winner->candidate->experience->id];

        return new DateNightStop(
            role: $role,
            scored: $winner,
            startsAt: $start,
            endsAt: $end,
            travelMinutesFromPrevious: $travel?->minutes,
            travelMode: $travel?->mode,
            alternatives: array_slice($rest, 0, 2),
        );
    }

    private function slotValue(ScoredExperience $scored, array $feasible): float
    {
        $travel = $feasible[$scored->candidate->experience->id][3];

        return $scored->score - ($travel?->minutes ?? 0) * self::SLOT_PENALTY_PER_TRAVEL_MINUTE;
    }

    /** Which slot a candidate can fill — dinner first, so a restaurant is never an opener. */
    private function roleOf(ExperienceCandidate $candidate, array $config): ?string
    {
        $isFood = ($candidate->experience->interest_affinity['food'] ?? 0) >= 70;

        foreach (['dinner', 'nightcap', 'opener'] as $role) {
            if ($role === 'dinner' && $isFood) {
                return 'dinner';
            }

            if (array_intersect($candidate->categoryKeys, $config['stops'][$role]['categories']) !== []) {
                return $role;
            }
        }

        return null;
    }

    /** @param list<DateNightStop> $stops */
    private function hasRole(array $stops, string $role): bool
    {
        return in_array($role, array_map(fn (DateNightStop $s) => $s->role, $stops), true);
    }

    /**
     * Written down like every other surface, so a plan can be explained and
     * measured later. The stops are the ranked recommendations, in order.
     *
     * @param  list<DateNightStop>  $stops
     */
    private function persist(Actor $actor, ScoringContext $context, array $input, array $options, int $considered, array $stops, float $started): RecommendationSet
    {
        $snapshot = $this->contextEngine->snapshot($context);

        $set = DB::transaction(function () use ($snapshot, $context, $input, $options, $considered, $stops, $started) {
            $set = RecommendationSet::create([
                'journey_context_snapshot_id' => $snapshot->id,
                'journey_id' => $context->journey?->id,
                'traveller_profile_id' => $context->profile?->id,
                'surface' => 'date_night',
                'request' => array_filter([
                    'surface' => 'date_night',
                    'mood' => 'romantic',
                    'date' => $options['date'],
                    'starts_at' => $options['starts_at'] ?? null,
                    'duration_minutes' => $options['duration_minutes'] ?? null,
                    'budget' => $options['budget'] ?? null,
                    'destination' => $input['destination'] ?? null,
                ], fn ($v) => $v !== null),
                'candidates_considered' => $considered,
                'generation_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            $now = CarbonImmutable::now();
            $rows = [];
            $reasons = [];

            foreach ($stops as $rank => $stop) {
                $id = (string) Str::uuid7();
                $rows[] = [
                    'id' => $id,
                    'recommendation_set_id' => $set->id,
                    'experience_id' => $stop->scored->candidate->experience->id,
                    'rank' => $rank + 1,
                    'score' => $stop->scored->score,
                    'is_sponsored' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                foreach ($stop->scored->components as $component) {
                    foreach ($component->reasons as $reason) {
                        $reasons[] = [
                            'id' => (string) Str::uuid7(),
                            'recommendation_id' => $id,
                            'component' => $component->component,
                            'direction' => $reason->direction,
                            'contribution' => $stop->scored->contributions[$component->component] ?? 0,
                            'message' => $reason->message,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            if ($rows !== []) {
                Recommendation::insert($rows);
            }

            foreach (array_chunk($reasons, 200) as $chunk) {
                RecommendationReason::insert($chunk);
            }

            return $set;
        });

        $this->events->recordMany($actor, array_map(
            fn (DateNightStop $stop) => ['impression', [
                'subject_type' => 'experience',
                'subject_id' => $stop->scored->candidate->experience->id,
                'recommendation_set_id' => $set->id,
                'surface' => 'date_night',
                'properties' => ['score' => $stop->scored->score, 'role' => $stop->role],
            ]],
            $stops,
        ));

        return $set;
    }
}
