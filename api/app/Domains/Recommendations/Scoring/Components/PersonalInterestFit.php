<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring\Components;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\Reason;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\ScoreComponent;

/**
 * How well the experience matches the persistent Traveller Profile (spec s6.1).
 *
 * Weighted overlap of the traveller's interest vector with the experience's
 * affinity vector, so a strong match on something the traveller cares about
 * counts for more than a weak match on something they are lukewarm about.
 */
class PersonalInterestFit implements ScoreComponent
{
    public function key(): string
    {
        return 'personal_interest_fit';
    }

    public function score(ExperienceCandidate $candidate, ScoringContext $context): ComponentScore
    {
        $interests = $context->interestVector;
        $affinity = (array) $candidate->experience->interest_affinity;

        if ($interests === [] || $affinity === []) {
            return ComponentScore::neutral($this->key(), 'We do not know your interests yet');
        }

        $weightTotal = 0.0;
        $matched = 0.0;
        $best = [];

        foreach ($interests as $interest => $weight) {
            $w = max(0, min(100, (int) $weight)) / 100;
            if ($w <= 0.05) {
                continue;
            }
            $weightTotal += $w;
            $a = max(0, min(100, (int) ($affinity[$interest] ?? 0))) / 100;
            $matched += $w * $a;

            if ($a >= 0.6 && $w >= 0.6) {
                $best[$interest] = $w * $a;
            }
        }

        if ($weightTotal <= 0) {
            return ComponentScore::neutral($this->key(), 'We do not know your interests yet');
        }

        $value = min(1.0, $matched / $weightTotal);
        arsort($best);
        $reasons = [];

        if ($best !== []) {
            $labels = array_map(
                fn (string $key) => str_replace('_', ' ', $key),
                array_slice(array_keys($best), 0, 2),
            );
            $reasons[] = Reason::plus('Matches your interest in ' . implode(' and ', $labels));
        } elseif ($value < 0.25) {
            $reasons[] = Reason::minus('Not a strong match for your usual interests');
        }

        return new ComponentScore($this->key(), $value, $reasons);
    }
}
