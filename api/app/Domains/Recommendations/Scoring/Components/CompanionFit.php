<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring\Components;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\Reason;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\ScoreComponent;

/** Suitability for who is actually going (spec s6.1, s13.1, s13.2). */
class CompanionFit implements ScoreComponent
{
    public function key(): string
    {
        return 'companion_fit';
    }

    public function score(ExperienceCandidate $candidate, ScoringContext $context): ComponentScore
    {
        $experience = $candidate->experience;
        $reasons = [];
        $value = 0.6;

        if ($context->hasChildren()) {
            $value = $experience->child_friendly_score / 100;
            $youngest = $context->childAges === [] ? null : min($context->childAges);

            if ($experience->child_min_age !== null && $youngest !== null && $youngest < $experience->child_min_age) {
                $value = 0.05;
                $reasons[] = Reason::minus(sprintf('Suited to ages %d and up', $experience->child_min_age));
            } elseif ($experience->child_friendly_score >= 75) {
                $reasons[] = Reason::plus('Works well with children');
            }

            if ($experience->expected_duration_minutes > 150) {
                $value -= 0.15;
                $reasons[] = Reason::minus('Long for younger children');
            }
            if (! $experience->has_toilets) {
                $value -= 0.1;
                $reasons[] = Reason::minus('No toilets on site');
            }
            if ($experience->food_on_site) {
                $value += 0.05;
            }
        } elseif ($context->adults === 2 && in_array($context->reason(), ['honeymoon', 'anniversary', 'romantic'], true)) {
            $value = $experience->romance_score / 100;
            if ($experience->romance_score >= 75) {
                $reasons[] = Reason::plus('A good one for two');
            }
        } elseif ($context->adults >= 4) {
            $value = $experience->social_score / 100;
            if ($experience->social_score >= 70) {
                $reasons[] = Reason::plus('Works for a group');
            }
        }

        if ($context->accessibilityMode) {
            $access = (array) $experience->accessibility;
            $wheelchair = $access['wheelchair_accessible'] ?? null;

            if ($wheelchair === true) {
                $value = min(1.0, $value + 0.2);
                $reasons[] = Reason::plus('Step-free access confirmed by the venue');
            } elseif ($wheelchair === false) {
                $value = 0.05;
                $reasons[] = Reason::minus('The venue states this is not step-free');
            } else {
                $value = min($value, 0.45);
                $reasons[] = Reason::minus('No verified accessibility information — worth checking with the venue');
            }
        }

        return new ComponentScore($this->key(), max(0.0, min(1.0, $value)), $reasons);
    }
}
