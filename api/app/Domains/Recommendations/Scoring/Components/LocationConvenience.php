<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring\Components;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\Reason;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\ScoreComponent;

/**
 * Travel time, route compatibility and proximity to the next anchor (spec s6.1).
 */
class LocationConvenience implements ScoreComponent
{
    public function key(): string
    {
        return 'location_convenience';
    }

    public function score(ExperienceCandidate $candidate, ScoringContext $context): ComponentScore
    {
        if ($candidate->travel === null) {
            return ComponentScore::neutral($this->key(), 'We do not know where you are starting from');
        }

        $minutes = $candidate->travel->minutes;
        $budget = (int) ($context->playbook()['max_travel_minutes'] ?? 45);

        $value = max(0.0, 1 - ($minutes / max(10, $budget * 2)));
        $reasons = [];

        if ($minutes <= 15) {
            $reasons[] = Reason::plus(sprintf('%d minutes away', $minutes));
        } elseif ($minutes > $budget) {
            $reasons[] = Reason::minus(sprintf('%d minutes away — further than this trip usually allows', $minutes));
        }

        if ($candidate->travel->mode === 'walk' && $minutes <= 20) {
            $value = min(1.0, $value + 0.1);
            $reasons[] = Reason::plus('Walkable from where you are');
        }

        /* Can the traveller still make their next fixed commitment? */
        if ($context->nextAnchor !== null && $candidate->point !== null) {
            $anchorPoint = $context->nextAnchor->point();
            $blocked = $context->nextAnchor->blockedWindow();
            $available = (int) $context->now->diffInMinutes($blocked->start, false);

            if ($available > 0) {
                $returnMinutes = $anchorPoint !== null
                    ? (int) ceil($candidate->point->distanceTo($anchorPoint) / 1000 / 4.6 * 60 * 1.25)
                    : 0;
                $roundTrip = $minutes + $candidate->experience->min_duration_minutes + $returnMinutes;

                if ($roundTrip > $available) {
                    $value = max(0.0, $value - 0.5);
                    $reasons[] = Reason::minus(sprintf('Too tight before %s', $context->nextAnchor->title));
                } elseif ($returnMinutes <= 20) {
                    $value = min(1.0, $value + 0.15);
                    $reasons[] = Reason::plus(sprintf('Close to %s afterwards', $context->nextAnchor->title));
                }
            }
        }

        return new ComponentScore($this->key(), max(0.0, min(1.0, $value)), $reasons);
    }
}
