<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Scoring;

use App\Domains\Recommendations\DTO\ComponentScore;
use App\Domains\Recommendations\DTO\ExperienceCandidate;
use App\Domains\Recommendations\DTO\ScoringContext;

interface ScoreComponent
{
    /** Config key in experience.scoring.weights. */
    public function key(): string;

    public function score(ExperienceCandidate $candidate, ScoringContext $context): ComponentScore;
}
