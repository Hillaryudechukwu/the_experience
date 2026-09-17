<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring\Components;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\Reason;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\ScoreComponent;
use Illuminate\Support\Str;

/**
 * How strongly the experience serves *this* trip (spec s6.1, s6.3).
 *
 * This is the component that lets a lower-profile activity outrank a famous one:
 * it reads the journey reason playbook, the Journey Mission's interpreted soft
 * goals, the traveller's familiarity with the city, and the trip's must-do list.
 */
class JourneyPurposeFit implements ScoreComponent
{
    public function key(): string
    {
        return 'journey_purpose_fit';
    }

    public function score(ExperienceCandidate $candidate, ScoringContext $context): ComponentScore
    {
        $playbook = $context->playbook();
        $experience = $candidate->experience;
        $reasons = [];

        /* Start neutral; the playbook pushes up or down from there. */
        $value = 0.5;

        foreach ($playbook['tag_boost'] ?? [] as $tag => $boost) {
            if ($candidate->hasCategory($tag)) {
                $value += $boost / 200;   // a +40 boost is worth +0.20
                if ($boost >= 25) {
                    $reasons[] = Reason::plus(sprintf(
                        'Well suited to a %s',
                        Str::lower($playbook['label'] ?? 'trip like this'),
                    ));
                }
            }
        }

        foreach ($playbook['tag_penalty'] ?? [] as $tag => $penalty) {
            if ($candidate->hasCategory($tag)) {
                $value += $penalty / 200;
                if ($penalty <= -40) {
                    $reasons[] = Reason::minus('Hard to fit around a ' . Str::lower($playbook['label'] ?? 'trip like this'));
                }
            }
        }

        $affinity = (array) $experience->interest_affinity;
        foreach ($playbook['interest_boost'] ?? [] as $interest => $boost) {
            $value += (($affinity[$interest] ?? 0) / 100) * ($boost / 200);
        }

        /* Journey Mission soft goals (spec s3.3) — influential, never overriding. */
        foreach ($context->missionGoalWeights as $goal => $weight) {
            $match = $this->goalMatch($goal, $candidate);
            if ($match !== null) {
                $value += $match * $weight * 0.25;
                if ($match > 0.6 && $weight > 0.5) {
                    $reasons[] = Reason::plus('Serves what you said you wanted from this trip');
                }
            }
        }

        /* First-time visitor mode (spec s5.2). */
        $iconic = $experience->iconic_weight / 100;
        if ($context->familiarity === 'never') {
            $value += ($iconic - 0.5) * 0.30;
            if ($iconic >= 0.75) {
                $reasons[] = Reason::plus('A defining first-visit experience here');
            }
        } elseif (in_array($context->familiarity, ['few', 'well'], true)) {
            $value -= ($iconic - 0.5) * 0.25;
            if ($iconic >= 0.8) {
                $reasons[] = Reason::minus('You have been here before — this is the obvious tourist stop');
            }
        }

        /* Explicit must-do list always wins. */
        $mustDo = array_map('mb_strtolower', (array) ($context->journey?->must_do ?? []));
        foreach ($mustDo as $item) {
            if ($item !== '' && Str::contains(mb_strtolower($experience->title), $item)) {
                $value = 1.0;
                $reasons[] = Reason::plus('On your must-do list for this trip');
                break;
            }
        }

        /* Duration preference for this kind of trip. */
        $preferred = (int) ($playbook['preferred_duration_minutes'] ?? 120);
        $delta = abs($experience->expected_duration_minutes - $preferred) / max(60, $preferred);
        $value -= min(0.15, $delta * 0.12);

        return new ComponentScore($this->key(), max(0.0, min(1.0, $value)), $reasons);
    }

    /** 0..1 for how well the candidate serves an interpreted mission goal. */
    private function goalMatch(string $goal, ExperienceCandidate $candidate): ?float
    {
        $e = $candidate->experience;
        $affinity = (array) $e->interest_affinity;
        $mood = (array) $e->mood_affinity;

        return match ($goal) {
            'romantic' => ($mood['romantic'] ?? $e->romance_score) / 100,
            'authentic_food', 'good_food', 'memorable_dining' => ($affinity['food'] ?? 0) / 100,
            'local_life', 'neighbourhood_feel', 'everyday_living' => $candidate->hasCategory('local_favourite') ? 0.9 : ($affinity['local_life'] ?? 0) / 100,
            'scenic', 'viewpoints', 'golden_hour' => ($affinity['photography'] ?? 0) / 100,
            'restful', 'reflective' => $e->energy_level === 'low' ? 0.85 : 0.3,
            'interactive', 'age_appropriate' => $e->child_friendly_score / 100,
            'depth', 'context', 'learning' => ($affinity['history'] ?? 0) / 100 * 0.5 + ($affinity['culture'] ?? 0) / 100 * 0.5,
            'budget_first' => $e->is_free ? 1.0 : max(0.0, 1 - (($e->price_from_minor ?? 0) / 5000)),
            'close_to_base', 'low_travel_uncertainty' => $candidate->travelMinutes() === null ? null : max(0.0, 1 - ($candidate->travelMinutes() / 45)),
            'short_queues' => max(0.0, 1 - ($e->queue_risk / 100)),
            'high_value_short' => $e->expected_duration_minutes <= 75 ? 0.9 : 0.2,
            'celebratory', 'social', 'networking_friendly', 'optional_social' => $e->social_score / 100,
            'nature' => ($affinity['nature'] ?? 0) / 100,
            'safety_conscious' => $e->weather_exposure === 'indoor' ? 0.7 : 0.5,
            default => null,
        };
    }
}
