<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring\Components;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\Reason;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\ScoreComponent;

/**
 * Does this work *now* — opening hours, the remaining window, daylight and
 * queue risk (spec s6.1).
 */
class CurrentTimeFit implements ScoreComponent
{
    public function key(): string
    {
        return 'current_time_fit';
    }

    public function score(ExperienceCandidate $candidate, ScoringContext $context): ComponentScore
    {
        $experience = $candidate->experience;
        $reasons = [];
        $value = 0.6;

        $openNow = $candidate->openingHours->isOpenAt($context->now);
        if ($openNow === true) {
            $value += 0.2;
            $reasons[] = Reason::plus('Open right now');

            $closesAt = $candidate->openingHours->closesAt($context->now);
            if ($closesAt !== null) {
                $minutesLeft = (int) $context->now->diffInMinutes($closesAt);
                $needed = $experience->min_duration_minutes + ($candidate->travelMinutes() ?? 0);
                if ($minutesLeft < $needed) {
                    $value -= 0.45;
                    $reasons[] = Reason::minus('Closes before you could get a worthwhile visit in');
                } elseif ($minutesLeft < $needed + 45) {
                    $value -= 0.12;
                    $reasons[] = Reason::minus(sprintf('Closes in about %d minutes', $minutesLeft));
                }
            }
        } elseif ($openNow === false) {
            $value -= 0.35;
            $reasons[] = Reason::minus('Closed at the moment');
        } else {
            $reasons[] = Reason::minus('We do not have verified opening hours for this');
        }

        /* Fit against the window the traveller actually has. */
        if ($context->availableWindow !== null) {
            $window = $context->availableWindow->minutes();
            $needed = $candidate->minimumMinutes();
            $comfortable = $candidate->totalMinutes();

            if ($needed > $window) {
                $value -= 0.5;
                $reasons[] = Reason::minus('Does not fit the time you have');
            } elseif ($comfortable <= $window) {
                $value += 0.2;
                $reasons[] = Reason::plus(sprintf('Fits comfortably in your %s', $this->humanWindow($window)));
            } else {
                $reasons[] = Reason::minus('Would be a rushed visit in the time you have');
            }
        }

        /* Time-of-day preference, e.g. a viewpoint at golden hour. */
        $slot = $this->timeSlot($context);
        if (in_array($slot, (array) $experience->best_time_of_day, true)) {
            $value += 0.15;
            $reasons[] = Reason::plus('This is the best part of the day for it');
        }

        /* Daylight matters for outdoor experiences. */
        if ($experience->weather_exposure === 'outdoor' && $context->weather !== null) {
            $daylight = $context->weather->daylightRemainingMinutes($context->now);
            if ($daylight !== null && $daylight < $experience->min_duration_minutes) {
                $value -= 0.25;
                $reasons[] = Reason::minus('Not much daylight left for an outdoor visit');
            }
        }

        if ($experience->queue_risk >= 70) {
            $value -= 0.1;
            $reasons[] = Reason::minus('Queues are usually long here');
        }

        if ($experience->requires_booking && ! $candidate->hasLiveAvailability) {
            $value -= 0.1;
            $reasons[] = Reason::minus('Usually needs booking ahead');
        }

        if ($candidate->hasLiveAvailability && $candidate->slotsRemaining !== null) {
            $value += 0.1;
            $reasons[] = Reason::plus('Tickets available today');
        }

        return new ComponentScore($this->key(), max(0.0, min(1.0, $value)), $reasons);
    }

    private function timeSlot(ScoringContext $context): string
    {
        $hour = (int) $context->now->format('G');
        $sunset = $context->weather?->sunset;

        if ($sunset !== null && abs($context->now->diffInMinutes($sunset, false)) <= 75) {
            return 'golden_hour';
        }

        return match (true) {
            $hour < 11 => 'morning',
            $hour < 15 => 'midday',
            $hour < 18 => 'afternoon',
            $hour < 22 => 'evening',
            default => 'night',
        };
    }

    private function humanWindow(int $minutes): string
    {
        return $minutes >= 120
            ? sprintf('%s hours', rtrim(rtrim(number_format($minutes / 60, 1), '0'), '.'))
            : sprintf('%d minutes', $minutes);
    }
}
