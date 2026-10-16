<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\Reason;
use App\Domains\Recommendations\DTO\ScoredExperience;
use App\Domains\Recommendations\DTO\ScoringContext;

/**
 * Re-ranks a scored list when a mood is selected.
 *
 * This is not a tenth score component. The nine weights stay as they are, and
 * a request with no mood returns the scorer's objects untouched so score-v1.3
 * order is the order the traveller still sees.
 */
class MoodBlend
{
    /**
     * @param  list<ScoredExperience>  $ranked
     * @return list<ScoredExperience>
     */
    public function apply(array $ranked, ScoringContext $context): array
    {
        $blend = (float) config('experience.scoring.mood_blend', 0.22);

        if ($context->mood === null || $blend <= 0) {
            return $ranked;
        }

        $mood = $context->mood;
        $adjusted = array_map(
            fn (ScoredExperience $scored) => $this->adjust($scored, $mood, $blend),
            $ranked,
        );

        usort($adjusted, function (ScoredExperience $a, ScoredExperience $b) {
            return $b->score <=> $a->score
                ?: ($a->candidate->travelMinutes() ?? 999) <=> ($b->candidate->travelMinutes() ?? 999)
                ?: strcmp($a->candidate->experience->id, $b->candidate->experience->id);
        });

        return $adjusted;
    }

    private function adjust(ScoredExperience $scored, string $mood, float $blend): ScoredExperience
    {
        [$affinity, $neutral] = $this->affinity($scored, $mood);
        $score = (int) round($scored->score * (1 - $blend) + ($affinity * 100 * $blend));
        $score = max(0, min(100, $score));

        $reason = $neutral ? null : $this->reason($mood, $affinity);
        if ($reason === null) {
            return new ScoredExperience($scored->candidate, $score, $scored->components, $scored->contributions);
        }

        $components = $scored->components;
        $components[] = new ComponentScore('mood_blend', $affinity, [$reason]);

        return new ScoredExperience($scored->candidate, $score, $components, $scored->contributions);
    }

    /** @return array{0: float, 1: bool} affinity and whether it is the neutral prior */
    private function affinity(ScoredExperience $scored, string $mood): array
    {
        $experience = $scored->candidate->experience;

        if ($mood === 'different') {
            $uniqueness = max(0, min(100, (int) $experience->uniqueness));
            $concentration = max(0, min(100, (int) $experience->tourist_concentration));

            return [
                (0.6 * ($uniqueness / 100)) + (0.4 * (1 - ($concentration / 100))),
                false,
            ];
        }

        $map = (array) $experience->mood_affinity;
        if (! array_key_exists($mood, $map)) {
            return [0.5, true];
        }

        return [max(0, min(100, (int) $map[$mood])) / 100, false];
    }

    private function reason(string $mood, float $affinity): ?Reason
    {
        if (abs($affinity - 0.5) < 0.001) {
            return null;
        }

        return $affinity > 0.5
            ? Reason::plus("Matches {$mood}")
            : Reason::minus("Not really a {$mood} fit");
    }
}
