<?php

declare(strict_types=1);

namespace Tests\Feature\Places;

use App\Domains\Discovery\Services\CandidateBuilder;
use App\Domains\Discovery\Services\ContextEngine;
use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Places\Services\ExperienceDraftFactory;
use App\Domains\Recommendations\Scoring\ExperienceScorer;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\GeoPoint;
use App\Domains\TravellerProfile\Services\TravellerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/**
 * Regression guard for a real failure.
 *
 * After ingesting several hundred real OpenStreetMap places, a bronze statue
 * of Captain Cook became the top recommendation for a first-time visitor to
 * London, above the British Museum, because it was four minutes closer and had
 * a Wikipedia article.
 *
 * Having a Wikipedia article means a thing is documented. It says nothing about
 * whether it deserves an afternoon. Derived prominence is therefore capped by
 * the kind of place, and this test keeps it that way — ingesting more data must
 * not make the recommendations worse.
 */
class DerivedProminenceTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_a_documented_statue_does_not_score_like_a_national_museum(): void
    {
        $factory = app(ExperienceDraftFactory::class);

        $statue = $factory->build($this->candidate('Captain James Cook', 'memorial'), null, 'GBP');
        $museum = $factory->build($this->candidate('A Large Museum', 'museum'), null, 'GBP');

        $this->assertLessThanOrEqual(32, $statue['iconic_weight']);
        $this->assertGreaterThan($statue['iconic_weight'] + 25, $museum['iconic_weight']);
    }

    public function test_a_street_memorial_is_short_outdoors_and_free(): void
    {
        $draft = app(ExperienceDraftFactory::class)->build($this->candidate('War Memorial', 'memorial'), null, 'GBP');

        $this->assertTrue($draft['is_free'], 'A monument on a public street costs nothing to look at.');
        $this->assertSame('outdoor', $draft['weather_exposure']);
        $this->assertLessThanOrEqual(20, $draft['expected_duration_minutes']);
    }

    public function test_a_curated_landmark_still_outranks_a_closer_ingested_memorial(): void
    {
        $destination = $this->destination();

        /* Editorial content, some distance away. */
        $this->experience($destination, [
            'slug' => 'great-museum',
            'title' => 'The Great Museum',
            'lat' => 51.5194,
            'lng' => -0.1270,
            'iconic_weight' => 92,
            'uniqueness' => 85,
            'expected_duration_minutes' => 150,
            'min_duration_minutes' => 60,
            'interest_affinity' => ['history' => 95, 'culture' => 90],
            'categories' => ['iconic', 'must_experience', 'culture'],
        ]);

        /* An ingested memorial, right next to the traveller. */
        $draft = app(ExperienceDraftFactory::class)->build(
            $this->candidate('A Documented Statue', 'memorial'),
            null,
            'GBP',
        );

        $this->experience($destination, array_merge(
            collect($draft)->only([
                'is_free', 'weather_exposure', 'energy_level', 'iconic_weight',
                'uniqueness', 'expected_duration_minutes', 'min_duration_minutes',
                'max_duration_minutes', 'interest_affinity',
            ])->all(),
            [
                'slug' => 'a-statue',
                'title' => 'A Documented Statue',
                'lat' => 51.5076,
                'lng' => -0.1280,
                'data_source' => 'osm',
            ],
        ));

        $guest = GuestSession::create(['token' => Str::random(40)]);
        $actor = new Actor(guestSessionId: $guest->id);

        app(TravellerProfileService::class)->update($actor, ['interests' => ['history' => 90, 'culture' => 80]]);
        $this->journey($destination, $actor, ['reason' => 'holiday', 'familiarity' => 'never']);

        $context = app(ContextEngine::class)->build($actor, [
            'destination_id' => $destination->id,
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);

        $ranked = app(ExperienceScorer::class)->rank(
            app(CandidateBuilder::class)->build($context, ['destination_id' => $destination->id]),
            $context,
        );

        $this->assertSame(
            'The Great Museum',
            $ranked[0]->candidate->experience->title,
            'Proximity must not beat prominence for a first-time visitor.',
        );
    }

    private function candidate(string $name, string $kind): PlaceCandidate
    {
        return new PlaceCandidate(
            provider: 'osm',
            providerId: 'node/' . crc32($name),
            name: $name,
            point: new GeoPoint(51.5076, -0.1280),
            kind: $kind,
            externalRefs: ['wikidata' => 'Q123', 'wikipedia' => 'en:' . $name],
        );
    }
}
