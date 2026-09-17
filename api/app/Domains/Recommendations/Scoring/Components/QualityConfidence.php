<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring\Components;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\Reason;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\ScoreComponent;

/**
 * External rating, discounted by how much we should trust it (spec s6.1).
 *
 * A 4.9 from 12 reviews is pulled towards the mean; a 4.6 from 84,000 is not.
 * A missing or stale rating scores neutral rather than zero — absence of a
 * review is not evidence of poor quality.
 */
class QualityConfidence implements ScoreComponent
{
    public function key(): string
    {
        return 'quality_confidence';
    }

    public function score(ExperienceCandidate $candidate, ScoringContext $context): ComponentScore
    {
        $place = $candidate->experience->place;

        if ($place === null || $place->rating === null || $place->rating_count === null) {
            return ComponentScore::neutral($this->key());
        }

        $freshness = $place->ratingFreshness();
        $rating = (float) $place->rating;
        $count = max(0, (int) $place->rating_count);

        /* Volume confidence saturates around 5,000 reviews. */
        $confidence = min(1.0, log10($count + 1) / log10(5000));

        /* 3.0 stars maps to 0, 5.0 maps to 1. */
        $normalised = max(0.0, min(1.0, ($rating - 3.0) / 2.0));

        /* Shrink towards the neutral prior when we have little evidence. */
        $value = ($normalised * $confidence) + (0.5 * (1 - $confidence));

        $reasons = [];
        if ($rating >= 4.5 && $count >= 1000) {
            $reasons[] = Reason::plus(sprintf('Rated %.1f from %s reviews', $rating, number_format($count)));
        } elseif ($count < 50) {
            $reasons[] = Reason::minus('Few reviews so far — treat the rating carefully');
        }
        if ($freshness->isStale()) {
            $reasons[] = Reason::minus('Rating was last checked ' . ($freshness->verifiedAt?->diffForHumans() ?? 'some time ago'));
        }

        return new ComponentScore($this->key(), $value, $reasons);
    }
}
