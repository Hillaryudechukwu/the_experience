<?php

declare(strict_types=1);

namespace App\Domains\Itineraries\Services;

use App\Domains\Bookings\Models\Booking;
use App\Domains\ExternalSources\Contracts\WeatherProvider;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Trips\Models\Itinerary;
use App\Domains\Trips\Models\ItineraryItem;
use App\Domains\Trips\Models\Trip;
use Carbon\CarbonImmutable;

/**
 * Dynamic replanning (spec s8.3).
 *
 * Produces a *proposal*: a new itinerary version that is not made current until
 * the traveller accepts it. Confirmed or paid items are never moved silently
 * (spec s8.2) — if a change would touch one, the proposal says so and stops.
 */
class ItineraryReplanner
{
    public function __construct(
        private readonly ItineraryPlanner $planner,
        private readonly WeatherProvider $weather,
    ) {}

    public function propose(Actor $actor, Trip $trip, array $options = []): array
    {
        $current = $trip->currentItinerary()?->load('days.items.experience');

        if ($current === null) {
            return [
                'proposal' => null,
                'changes' => [],
                'trigger' => null,
                'message' => 'There is no itinerary to replan yet.',
            ];
        }

        $locked = $this->lockedItems($current);
        $trigger = $options['trigger'] ?? $this->detectTrigger($trip, $current);

        $proposal = $this->planner->generate($actor, $trip, array_merge($options, [
            'make_current' => false,
        ]));

        $changes = $this->diff($current, $proposal);

        $conflicts = array_values(array_filter(
            $changes,
            fn (array $c) => $c['direction'] === 'removed' && in_array($c['item_id'], $locked, true),
        ));

        return [
            'proposal' => $proposal->load('days.items.experience'),
            'changes' => $changes,
            'trigger' => $trigger,
            'blocked_by_confirmed_bookings' => $conflicts,
            'message' => $this->message($trigger, $changes, $conflicts),
        ];
    }

    public function accept(Trip $trip, Itinerary $proposal): Itinerary
    {
        $trip->itineraries()->where('id', '!=', $proposal->id)->update(['is_current' => false]);
        $proposal->update(['is_current' => true]);

        return $proposal->fresh(['days.items']);
    }

    /** @return list<string> ids of items the planner is not allowed to drop */
    private function lockedItems(Itinerary $itinerary): array
    {
        $bookedExperienceIds = Booking::query()
            ->whereIn('state', ['confirmed', 'partially_confirmed', 'processing'])
            ->with('items')
            ->get()
            ->flatMap(fn (Booking $b) => $b->items->pluck('experience_id'))
            ->filter()
            ->all();

        $locked = [];
        foreach ($itinerary->days as $day) {
            foreach ($day->items as $item) {
                if ($item->locked || in_array($item->experience_id, $bookedExperienceIds, true)) {
                    $locked[] = $item->id;
                }
            }
        }

        return $locked;
    }

    private function detectTrigger(Trip $trip, Itinerary $itinerary): ?array
    {
        $destination = $trip->journey->destination;
        $forecast = rescue(
            fn () => $this->weather->forecast($destination->point(), CarbonImmutable::now($destination->timezone), 24),
            null,
            report: false,
        );

        if ($forecast === null) {
            return null;
        }

        foreach ($itinerary->days as $day) {
            foreach ($day->items as $item) {
                if ($item->kind !== 'experience' || $item->experience === null) {
                    continue;
                }
                if (! in_array($item->experience->weather_exposure, ['outdoor', 'weather_sensitive'], true)) {
                    continue;
                }
                if ($forecast->isPoorAt(CarbonImmutable::parse($item->starts_at))) {
                    return [
                        'type' => 'weather',
                        'condition' => $forecast->conditionAt(CarbonImmutable::parse($item->starts_at)),
                        'affects' => $item->title,
                        'at' => CarbonImmutable::parse($item->starts_at)->toIso8601String(),
                        'source' => $forecast->freshness->toArray(),
                    ];
                }
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function diff(Itinerary $current, Itinerary $proposal): array
    {
        $before = $this->index($current);
        $after = $this->index($proposal->load('days.items'));

        $changes = [];

        foreach ($before as $key => $item) {
            if (! isset($after[$key])) {
                $changes[] = [
                    'direction' => 'removed',
                    'item_id' => $item->id,
                    'title' => $item->title,
                    'was' => CarbonImmutable::parse($item->starts_at)->toIso8601String(),
                ];
            } elseif (! CarbonImmutable::parse($after[$key]->starts_at)->equalTo(CarbonImmutable::parse($item->starts_at))) {
                $changes[] = [
                    'direction' => 'moved',
                    'item_id' => $item->id,
                    'title' => $item->title,
                    'was' => CarbonImmutable::parse($item->starts_at)->toIso8601String(),
                    'now' => CarbonImmutable::parse($after[$key]->starts_at)->toIso8601String(),
                ];
            }
        }

        foreach ($after as $key => $item) {
            if (! isset($before[$key])) {
                $changes[] = [
                    'direction' => 'added',
                    'item_id' => $item->id,
                    'title' => $item->title,
                    'now' => CarbonImmutable::parse($item->starts_at)->toIso8601String(),
                ];
            }
        }

        return $changes;
    }

    /** @return array<string, ItineraryItem> */
    private function index(Itinerary $itinerary): array
    {
        $index = [];
        foreach ($itinerary->days as $day) {
            foreach ($day->items as $item) {
                $key = $item->kind . ':' . ($item->experience_id ?? $item->journey_anchor_id ?? $item->title);
                $index[$key] = $item;
            }
        }

        return $index;
    }

    private function message(?array $trigger, array $changes, array $conflicts): string
    {
        if ($conflicts !== []) {
            return 'This replan would move something you have already booked, so it needs your confirmation first.';
        }

        if ($changes === []) {
            return 'Your plan still looks like the best use of this trip — nothing worth changing.';
        }

        if (($trigger['type'] ?? null) === 'weather') {
            return sprintf(
                '%s is forecast while you would be at %s, so the outdoor part of the day has been moved.',
                ucfirst(str_replace('_', ' ', $trigger['condition'])),
                $trigger['affects'],
            );
        }

        return sprintf('%d change%s would make the day work better.', count($changes), count($changes) === 1 ? '' : 's');
    }
}
