<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\JourneyIntelligence\Services\MissionInterpreter;
use Tests\TestCase;

/** Spec s3.3: a Journey Mission becomes structured soft goals. */
class MissionInterpreterTest extends TestCase
{
    public function test_a_business_mission_produces_the_goals_the_words_imply(): void
    {
        $goals = app(MissionInterpreter::class)->interpret(
            "I'm here mainly for meetings, but this is my first visit to Japan and I don't want to feel like I only saw hotel rooms.",
            'business',
        );

        $keys = array_column($goals, 'goal');

        $this->assertContains('close_to_base', $keys);
        $this->assertNotEmpty(array_filter($goals, fn ($g) => $g['evidence'] !== ''));
    }

    public function test_the_reason_for_travel_contributes_baseline_goals(): void
    {
        $goals = app(MissionInterpreter::class)->interpret(null, 'family');
        $keys = array_column($goals, 'goal');

        $this->assertContains('age_appropriate', $keys);
        $this->assertContains('short_queues', $keys);
    }

    public function test_a_refusal_lowers_a_goal_rather_than_raising_it(): void
    {
        $interpreter = app(MissionInterpreter::class);

        $wanted = $interpreter->weights($interpreter->interpret('I want to relax and do nothing much', 'holiday'));
        $refused = $interpreter->weights($interpreter->interpret('I do not want to relax, I want to be busy', 'holiday'));

        $this->assertGreaterThan($refused['restful'], $wanted['restful']);
    }
}
