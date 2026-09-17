<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Discovery\Services\CandidateBuilder;
use App\Domains\Discovery\Services\ContextEngine;
use App\Domains\Recommendations\Scoring\ExperienceScorer;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\TravellerProfile\Services\TravellerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/**
 * Acceptance 54: the engine produces an explainable score from traveller +
 * journey + live context.
 * Spec s6.3: purpose fit can reorder a ranking that interest alone would not.
 */
class ExperienceScoreTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_weights_are_configured_to_sum_to_one(): void
    {
        $this->assertEqualsWithDelta(1.0, array_sum(config('experience.scoring.weights')), 0.0001);
    }

    public function test_a_score_is_explainable_and_bounded(): void
    {
        [$scored] = $this->rankFor('holiday', null);

        $this->assertGreaterThanOrEqual(0, $scored->score);
        $this->assertLessThanOrEqual(100, $scored->score);

        $explanation = $scored->explanation();
        $this->assertNotEmpty($explanation['positive'], 'A recommendation must say why it was made.');
        $this->assertArrayHasKey('personal_interest_fit', $explanation['contributions']);
        $this->assertArrayHasKey('journey_purpose_fit', $explanation['contributions']);
        $this->assertCount(9, $explanation['components']);
    }

    public function test_journey_purpose_can_outrank_raw_interest_fit(): void
    {
        /* The traveller is a history enthusiast, so on a normal city break the
           long museum visit should win. */
        $holiday = $this->rankFor('holiday', null);
        $this->assertStringStartsWith('museum', $holiday[0]->candidate->experience->slug);

        /* On a layover with two hours, the same traveller is better served by
           the short food stop — nothing about their interests changed. */
        $layover = $this->rankFor('layover', 120);
        $this->assertStringStartsWith('market', $layover[0]->candidate->experience->slug);
    }

    public function test_a_component_without_data_is_neutral_rather_than_zero(): void
    {
        $destination = $this->destination();
        $this->experience($destination, [
            'slug' => 'unrated',
            'title' => 'Unrated place',
            'rating' => null,
            'rating_count' => null,
        ]);

        $context = app(ContextEngine::class)->build(new Actor(guestSessionId: null), [
            'destination_id' => $destination->id,
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);

        $candidates = app(CandidateBuilder::class)->build($context, ['destination_id' => $destination->id]);
        $scored = app(ExperienceScorer::class)->score($candidates[0], $context);

        $quality = collect($scored->components)->firstWhere('component', 'quality_confidence');
        $this->assertTrue($quality->isNeutral);
        $this->assertNull($scored->contributions['quality_confidence']);
    }

    /** @return list<\App\Domains\Recommendations\DTO\ScoredExperience> */
    private function rankFor(string $reason, ?int $windowMinutes): array
    {
        $destination = $this->destination();
        $suffix = \Illuminate\Support\Str::random(5);

        $this->experience($destination, [
            'slug' => 'museum-' . $suffix,
            'title' => 'Grand History Museum',
            'min_duration_minutes' => 60,
            'expected_duration_minutes' => 180,
            'max_duration_minutes' => 240,
            'iconic_weight' => 95,
            'uniqueness' => 80,
            'interest_affinity' => ['history' => 95, 'culture' => 80],
            'categories' => ['iconic', 'culture', 'half_day'],
            'lat' => 51.5090,
            'lng' => -0.1280,
        ]);

        $this->experience($destination, [
            'slug' => 'market-' . $suffix,
            'title' => 'Market Lunch Stand',
            'min_duration_minutes' => 25,
            'expected_duration_minutes' => 45,
            'max_duration_minutes' => 75,
            'iconic_weight' => 35,
            'uniqueness' => 60,
            'interest_affinity' => ['food' => 90, 'local_life' => 60],
            'categories' => ['quick_experience', 'food_experience'],
            'lat' => 51.5080,
            'lng' => -0.1275,
        ]);

        $actor = new Actor(guestSessionId: \App\Domains\Identity\Models\GuestSession::create(['token' => \Illuminate\Support\Str::random(40)])->id);

        app(TravellerProfileService::class)->update($actor, [
            'interests' => ['history' => 90, 'food' => 55],
        ]);

        $this->journey($destination, $actor, ['reason' => $reason, 'adults' => 1]);

        $input = ['destination_id' => $destination->id, 'lat' => 51.5074, 'lng' => -0.1278];
        if ($windowMinutes !== null) {
            $input['window_minutes'] = $windowMinutes;
        }

        $context = app(ContextEngine::class)->build($actor, $input);
        $candidates = app(CandidateBuilder::class)->build($context, ['destination_id' => $destination->id]);

        return app(ExperienceScorer::class)->rank($candidates, $context);
    }
}
