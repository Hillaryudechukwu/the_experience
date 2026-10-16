<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Discovery\Services\CandidateBuilder;
use App\Domains\Discovery\Services\ContextEngine;
use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Places\Services\ExperienceDraftFactory;
use App\Domains\Recommendations\DTO\ScoredExperience;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Scoring\ExperienceScorer;
use App\Domains\Recommendations\Scoring\MoodBlend;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

class MoodBlendTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_weights_still_sum_to_one_and_mood_is_not_a_weight(): void
    {
        $weights = config('experience.scoring.weights');

        $this->assertEqualsWithDelta(1.0, array_sum($weights), 0.0001);
        $this->assertArrayNotHasKey('mood_fit', $weights);
        $this->assertArrayNotHasKey('mood_blend', $weights);
    }

    public function test_no_mood_returns_the_scorer_result_unchanged(): void
    {
        [$scored] = $this->ranked(null);

        $again = app(MoodBlend::class)->apply([$scored], $this->context(null));

        $this->assertSame($scored, $again[0]);
        $this->assertSame($scored->contributions, $again[0]->contributions);
        $this->assertCount(9, $again[0]->components);
    }

    public function test_a_high_affinity_place_outranks_a_tied_low_affinity_place(): void
    {
        $context = $this->context('hungry');
        $ranked = app(ExperienceScorer::class)->rank(
            app(CandidateBuilder::class)->build($context, [
                'destination_id' => $context->journey?->destination_id ?? $this->destinationId,
            ]),
            $context,
        );

        $tied = array_map(
            fn ($scored) => new ScoredExperience(
                $scored->candidate,
                70,
                $scored->components,
                $scored->contributions,
            ),
            $ranked,
        );
        $blended = app(MoodBlend::class)->apply($tied, $context);

        $this->assertSame('high-hungry', $blended[0]->candidate->experience->slug);
    }

    public function test_a_missing_affinity_is_the_neutral_prior_and_adds_no_reason(): void
    {
        $context = $this->context('romantic');
        $ranked = app(ExperienceScorer::class)->rank(
            app(CandidateBuilder::class)->build($context, [
                'destination_id' => $this->destinationId,
            ]),
            $context,
        );

        $blended = app(MoodBlend::class)->apply($ranked, $context);
        $reasons = array_map(
            fn ($component) => $component->component,
            $blended[0]->components,
        );

        $this->assertNotContains('mood_blend', $reasons);
    }

    public function test_different_uses_uniqueness_rather_than_the_affinity_column(): void
    {
        $context = $this->context('different');
        $ranked = app(ExperienceScorer::class)->rank(
            app(CandidateBuilder::class)->build($context, [
                'destination_id' => $this->destinationId,
            ]),
            $context,
        );

        $blended = collect(app(MoodBlend::class)->apply($ranked, $context))
            ->keyBy(fn ($scored) => $scored->candidate->experience->slug);

        $this->assertGreaterThan(
            $blended['low-hungry']->score,
            $blended['high-hungry']->score,
        );
    }

    public function test_a_food_draft_carries_a_hungry_affinity(): void
    {
        $draft = (new ExperienceDraftFactory)->build(
            new PlaceCandidate(
                provider: 'osm',
                providerId: '1',
                name: 'Kitchen',
                point: new GeoPoint(51.5, -0.12),
                kind: 'food',
            ),
            null,
            'GBP',
        );

        $this->assertGreaterThanOrEqual(90, $draft['mood_affinity']['hungry']);
    }

    private string $destinationId;

    /** @return list<ScoredExperience> */
    private function ranked(?string $mood): array
    {
        $context = $this->context($mood);

        return app(ExperienceScorer::class)->rank(
            app(CandidateBuilder::class)->build($context, [
                'destination_id' => $this->destinationId,
            ]),
            $context,
        );
    }

    private function context(?string $mood): ScoringContext
    {
        if (! isset($this->destinationId)) {
            $destination = $this->destination();
            $this->destinationId = $destination->id;
            $this->experience($destination, [
                'slug' => 'low-hungry',
                'title' => 'Quiet Gallery',
                'mood_affinity' => ['hungry' => 20],
                'uniqueness' => 20,
                'tourist_concentration' => 90,
                'lat' => 51.5074,
                'lng' => -0.1278,
            ]);
            $this->experience($destination, [
                'slug' => 'high-hungry',
                'title' => 'Market Kitchen',
                'mood_affinity' => ['hungry' => 90],
                'uniqueness' => 90,
                'tourist_concentration' => 10,
                'lat' => 51.5074,
                'lng' => -0.1278,
            ]);
        }

        $actor = new Actor(guestSessionId: GuestSession::create([
            'token' => Str::random(40),
        ])->id);

        return app(ContextEngine::class)->build($actor, array_filter([
            'destination_id' => $this->destinationId,
            'lat' => 51.5074,
            'lng' => -0.1278,
            'mood' => $mood,
        ], fn ($value) => $value !== null));
    }
}
