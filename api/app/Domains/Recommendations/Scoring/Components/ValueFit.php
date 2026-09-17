<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring\Components;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\Reason;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\ScoreComponent;
use App\Domains\Shared\ValueObjects\Money;

/**
 * Expected value relative to price, budget and alternatives (spec s6.1, s6.4).
 *
 * Deliberately avoids the phrase "tourist trap": it reports price against the
 * traveller's own budget and a transparent value signal instead.
 */
class ValueFit implements ScoreComponent
{
    public function key(): string
    {
        return 'value';
    }

    public function score(ExperienceCandidate $candidate, ScoringContext $context): ComponentScore
    {
        $experience = $candidate->experience;
        $reasons = [];

        if ($experience->is_free) {
            return new ComponentScore($this->key(), 0.95, [Reason::plus('Free to visit')]);
        }

        $price = $experience->price_from_minor;

        if ($price === null) {
            return ComponentScore::neutral($this->key(), 'We do not have a verified price for this');
        }

        if ($experience->priceFreshness()->isStale()) {
            $reasons[] = Reason::minus('Price was last checked ' . ($experience->price_verified_at?->diffForHumans() ?? 'some time ago'));
        }

        /* Start from the transparent value signal rather than raw cheapness. */
        $value = $experience->value_signal / 100;

        $budget = $context->budgetRemainingMinor;
        if ($budget !== null) {
            $partyCost = $price * max(1, $context->adults + $context->children);
            if ($partyCost > $budget) {
                $value -= 0.45;
                $reasons[] = Reason::minus(sprintf(
                    '%s for your group is over what is left of your budget',
                    (new Money($partyCost, $context->currency))->format(),
                ));
            } elseif ($partyCost <= $budget * 0.3) {
                $value += 0.15;
            }
        }

        if ($price >= 4000) {
            $value -= 0.1;
            $reasons[] = Reason::minus('Relatively expensive');
        }

        if ($experience->value_signal >= 75 && $price > 0) {
            $reasons[] = Reason::plus('Travellers consistently rate this good value');
        } elseif ($experience->value_signal <= 35) {
            $reasons[] = Reason::minus('Mixed feedback on value for money');
        }

        return new ComponentScore($this->key(), max(0.0, min(1.0, $value)), $reasons);
    }
}
