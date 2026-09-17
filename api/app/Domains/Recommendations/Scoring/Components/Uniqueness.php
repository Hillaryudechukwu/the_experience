<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring\Components;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\Reason;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\ScoreComponent;

/** How distinctive this is for the destination (spec s6.1). */
class Uniqueness implements ScoreComponent
{
    public function key(): string
    {
        return 'uniqueness';
    }

    public function score(ExperienceCandidate $candidate, ScoringContext $context): ComponentScore
    {
        $experience = $candidate->experience;
        $value = $experience->uniqueness / 100;

        $reasons = [];
        if ($experience->uniqueness >= 80) {
            $reasons[] = Reason::plus('You could not really do this anywhere else');
        }

        /* Repeat visitors have already seen the obvious things (spec s5.2). */
        if (in_array($context->familiarity, ['few', 'well'], true)) {
            $value = min(1.0, $value * 1.2);
        }

        if ($candidate->hasCategory('hidden_gem')) {
            $value = min(1.0, $value + 0.1);
            $reasons[] = Reason::plus('Less obvious than the headline attractions');
        }

        return new ComponentScore($this->key(), max(0.0, min(1.0, $value)), $reasons);
    }
}
