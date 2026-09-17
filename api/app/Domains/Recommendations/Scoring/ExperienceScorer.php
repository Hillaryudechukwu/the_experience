<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\ScoredExperience;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\Components\CompanionFit;
use App\Domains\Recommendations\Scoring\Components\CurrentTimeFit;
use App\Domains\Recommendations\Scoring\Components\JourneyPurposeFit;
use App\Domains\Recommendations\Scoring\Components\LocationConvenience;
use App\Domains\Recommendations\Scoring\Components\PersonalInterestFit;
use App\Domains\Recommendations\Scoring\Components\QualityConfidence;
use App\Domains\Recommendations\Scoring\Components\Uniqueness;
use App\Domains\Recommendations\Scoring\Components\ValueFit;
use App\Domains\Recommendations\Scoring\Components\WeatherFit;

/**
 * The Experience Score (spec s6).
 *
 * A weighted, explainable combination of nine components. Components that have
 * no data to judge on declare themselves neutral and their weight is
 * redistributed, so a missing rating does not quietly look like a bad rating.
 */
class ExperienceScorer
{
    /** @var list<ScoreComponent> */
    private array $components;

    public function __construct(
        PersonalInterestFit $interest,
        JourneyPurposeFit $purpose,
        QualityConfidence $quality,
        Uniqueness $uniqueness,
        CurrentTimeFit $time,
        LocationConvenience $location,
        ValueFit $value,
        WeatherFit $weather,
        CompanionFit $companion,
    ) {
        $this->components = [$interest, $purpose, $quality, $uniqueness, $time, $location, $value, $weather, $companion];
    }

    public function score(ExperienceCandidate $candidate, ScoringContext $context): ScoredExperience
    {
        $weights = $context->weights ?: config('experience.scoring.weights');

        $scores = [];
        $contributions = [];
        $weightedTotal = 0.0;
        $weightUsed = 0.0;

        foreach ($this->components as $component) {
            $weight = (float) ($weights[$component->key()] ?? 0);
            $result = $component->score($candidate, $context);
            $scores[] = $result;

            if ($weight <= 0) {
                continue;
            }

            if ($result->isNeutral) {
                /* No evidence: redistribute rather than score the gap. */
                $contributions[$component->key()] = null;

                continue;
            }

            $weightedTotal += $weight * $result->value;
            $weightUsed += $weight;
            $contributions[$component->key()] = round($weight * $result->value * 100, 2);
        }

        $final = $weightUsed > 0 ? (int) round(($weightedTotal / $weightUsed) * 100) : 50;

        return new ScoredExperience($candidate, max(0, min(100, $final)), $scores, $contributions);
    }

    /**
     * @param  list<ExperienceCandidate>  $candidates
     * @return list<ScoredExperience> sorted best first
     */
    public function rank(array $candidates, ScoringContext $context): array
    {
        $scored = array_map(fn (ExperienceCandidate $c) => $this->score($c, $context), $candidates);

        usort($scored, function (ScoredExperience $a, ScoredExperience $b) {
            return $b->score <=> $a->score
                ?: ($a->candidate->travelMinutes() ?? 999) <=> ($b->candidate->travelMinutes() ?? 999)
                ?: strcmp($a->candidate->experience->id, $b->candidate->experience->id);
        });

        return $scored;
    }

    /** @return list<string> */
    public function componentKeys(): array
    {
        return array_map(fn (ScoreComponent $c) => $c->key(), $this->components);
    }

    public function confidence(ScoredExperience $scored): float
    {
        $weights = config('experience.scoring.weights');
        $used = 0.0;
        foreach ($scored->components as $component) {
            /** @var ComponentScore $component */
            if (! $component->isNeutral) {
                $used += (float) ($weights[$component->component] ?? 0);
            }
        }

        return round($used, 3);
    }
}
