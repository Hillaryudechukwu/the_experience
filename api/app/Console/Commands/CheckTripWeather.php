<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Itineraries\Services\ItineraryReplanner;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Proactive weather watch for trips that already have a current itinerary.
 *
 * Interactive replan from the Plan tab does not push — the traveller is already
 * looking at the proposal. This command is the only path that should interrupt.
 */
#[Signature('trips:check-weather {--limit=40}')]
#[Description('Propose weather-driven replans and notify travellers when the plan would change')]
class CheckTripWeather extends Command
{
    public function handle(ItineraryReplanner $replanner): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $today = CarbonImmutable::now()->toDateString();

        $trips = Trip::query()
            ->whereHas('itineraries', fn ($query) => $query->where('is_current', true))
            ->whereHas('journey', function ($query) use ($today) {
                $query->where(function ($inner) use ($today) {
                    $inner->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today);
                });
            })
            ->with(['journey.destination'])
            ->latest('updated_at')
            ->limit($limit)
            ->get();

        $checked = 0;
        $proposed = 0;
        $notified = 0;

        foreach ($trips as $trip) {
            $checked++;

            $actor = new Actor(
                userId: $trip->user_id,
                guestSessionId: $trip->user_id === null ? $trip->guest_session_id : null,
            );

            if ($actor->isAnonymous()) {
                continue;
            }

            /* One weather proposal per trip per day is enough. */
            $alreadyProposedToday = $trip->itineraries()
                ->where('is_current', false)
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadyProposedToday) {
                continue;
            }

            $result = $replanner->proposeForWeather($actor, $trip);
            if ($result === null) {
                continue;
            }

            $proposed++;
            if (($result['changes'] ?? []) !== []) {
                $notified++;
            }
        }

        $this->info("Checked {$checked} trip(s); proposed {$proposed}; notified {$notified}.");

        return self::SUCCESS;
    }
}
