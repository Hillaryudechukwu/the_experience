<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring\Components;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\Reason;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\ScoreComponent;

/** Suitability for the current and near-term conditions (spec s6.1, s13.4). */
class WeatherFit implements ScoreComponent
{
    public function key(): string
    {
        return 'weather_fit';
    }

    public function score(ExperienceCandidate $candidate, ScoringContext $context): ComponentScore
    {
        $weather = $context->weather;

        if ($weather === null) {
            return ComponentScore::neutral($this->key());
        }

        $exposure = $candidate->experience->weather_exposure;
        $poorNow = $weather->isPoorAt($context->now);

        /* Look ahead across the visit, not just at this minute. */
        $poorSoon = false;
        $duration = $candidate->experience->expected_duration_minutes;
        for ($m = 0; $m <= $duration; $m += 60) {
            if ($weather->isPoorAt($context->now->addMinutes($m))) {
                $poorSoon = true;
                break;
            }
        }

        $reasons = [];
        $value = match ($exposure) {
            'indoor' => $poorNow ? 0.95 : 0.6,
            'outdoor' => $poorSoon ? 0.15 : 0.9,
            'weather_sensitive' => $poorSoon ? 0.25 : 0.85,
            default => $poorSoon ? 0.55 : 0.75,
        };

        if ($exposure === 'indoor' && $poorNow) {
            $reasons[] = Reason::plus('Indoors, which suits the weather right now');
        }
        if (in_array($exposure, ['outdoor', 'weather_sensitive'], true)) {
            if ($poorSoon) {
                $reasons[] = Reason::minus(sprintf('%s expected while you would be there', ucfirst(str_replace('_', ' ', $weather->conditionAt($context->now)))));
            } else {
                $reasons[] = Reason::plus('Weather suits it');
            }
        }

        if ($weather->freshness->isStale()) {
            $reasons[] = Reason::minus('Forecast may be out of date');
        }

        return new ComponentScore($this->key(), $value, $reasons);
    }
}
