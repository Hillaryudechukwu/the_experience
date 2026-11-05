<?php

declare(strict_types=1);

namespace App\Domains\Discovery\Services;

use App\Domains\ExternalSources\Contracts\EventProvider;
use App\Domains\ExternalSources\DTO\LiveEvent;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What's on near a point, for today, this weekend or this week.
 *
 * Never fails the screen it sits on: a provider that is down, unconfigured or
 * rate-limited answers with no events, and the experiences carry the page.
 */
class EventFinder
{
    public const RANGES = ['today', 'weekend', 'week'];

    public function __construct(private readonly EventProvider $provider) {}

    public function available(): bool
    {
        return $this->provider->isConfigured();
    }

    /** @return list<array<string, mixed>> */
    public function near(GeoPoint $point, string $range, CarbonImmutable $now): array
    {
        if (! $this->provider->isConfigured()) {
            return [];
        }

        [$from, $to] = $this->window($range, $now);
        $limit = (int) config('experience.explore_city.events_limit', 20);

        try {
            /* Keyed on a ~1 km grid and the day, not the minute: two people
               on the same street share an answer, and nobody's exact position
               ends up in a cache key. */
            $events = Cache::remember(
                sprintf('events:%s:%.2f:%.2f:%s:%s', $this->provider->key(), $point->lat, $point->lng, $range, $from->toDateString()),
                (int) config('experience.explore_city.events_cache_seconds', 1800),
                fn () => $this->provider->near(
                    $point,
                    (int) config('experience.explore_city.events_radius_km', 15),
                    $from,
                    $to,
                    $limit,
                ),
            );
        } catch (Throwable $e) {
            Log::info('events.unavailable', ['provider' => $this->provider->key(), 'message' => $e->getMessage()]);

            return [];
        }

        return collect($events)
            /* The cache outlives the minute it was filled in. A show that has
               already started an hour ago is not something to go to now. */
            ->reject(fn (LiveEvent $event) => $event->timeKnown && $event->startsAt->lt($now->subMinutes(30)))
            ->reject(fn (LiveEvent $event) => $event->startsAt->gt($to))
            ->sortBy(fn (LiveEvent $event) => $event->startsAt->getTimestamp())
            /* One show, one row: listings repeat for VIP packages, parking
               and each price tier under the same name and venue. */
            ->unique(fn (LiveEvent $event) => mb_strtolower(trim($event->title)).'|'.mb_strtolower((string) $event->venueName).'|'.$event->startsAt->toDateString())
            ->take($limit)
            ->map(fn (LiveEvent $event) => $event->toArray($point))
            ->values()
            ->all();
    }

    /**
     * The span a range covers, in the city's own time.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(string $range, CarbonImmutable $now): array
    {
        return match ($range) {
            'weekend' => $this->weekend($now),
            'week' => [$now, $now->addDays(6)->endOfDay()],
            default => [$now, $now->endOfDay()],
        };
    }

    /**
     * Friday evening to Sunday night. On a weekday it is the coming one; once
     * it has started it runs from now.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function weekend(CarbonImmutable $now): array
    {
        $friday = $now->dayOfWeekIso <= 5
            ? $now->startOfDay()->addDays(5 - $now->dayOfWeekIso)->setTime(17, 0)
            : $now->startOfDay()->subDays($now->dayOfWeekIso - 5)->setTime(17, 0);
        $sunday = $friday->addDays(2)->endOfDay();

        return [$now->greaterThan($friday) ? $now : $friday, $sunday];
    }
}
