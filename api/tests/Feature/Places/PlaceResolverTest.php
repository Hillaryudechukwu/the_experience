<?php

declare(strict_types=1);

namespace Tests\Feature\Places;

use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\Places\Models\PlaceMergeCandidate;
use App\Domains\Places\Services\PlaceResolution;
use App\Domains\Places\Services\PlaceResolver;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/**
 * Canonical place resolution (spec s11).
 *
 * The asymmetry under test: a wrongly merged place sends people to the wrong
 * address, while a duplicate is merely untidy. So anything ambiguous is held
 * for a human rather than resolved automatically.
 */
class PlaceResolverTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_a_previously_mapped_provider_record_resolves_immediately(): void
    {
        $destination = $this->destination();
        $experience = $this->experience($destination, ['slug' => 'known', 'title' => 'Known Place']);

        ExternalEntity::create([
            'provider' => 'osm',
            'provider_id' => 'way/1',
            'entity_type' => 'place',
            'entity_id' => $experience->place_id,
            'confidence' => 1.0,
        ]);

        $result = app(PlaceResolver::class)->resolve($this->candidate('Completely Different Name', 51.9, -0.9, 'way/1'), $destination);

        $this->assertTrue($result->isMatch());
        $this->assertSame($experience->place_id, $result->place->id);
        $this->assertSame('existing_provider_mapping', $result->signals['reason']);
    }

    public function test_a_shared_wikidata_identity_is_treated_as_the_same_place(): void
    {
        $destination = $this->destination();
        $experience = $this->experience($destination, ['slug' => 'tower', 'title' => 'Tower of London']);

        ExternalEntity::create([
            'provider' => 'wikidata',
            'provider_id' => 'Q62378',
            'entity_type' => 'place',
            'entity_id' => $experience->place_id,
            'confidence' => 1.0,
        ]);

        $candidate = new PlaceCandidate(
            provider: 'google_places',
            providerId: 'ChIJ_x',
            name: 'HM Tower of London',          // spelled differently
            point: new GeoPoint(51.8, -0.9),     // and mapped elsewhere
            externalRefs: ['wikidata' => 'Q62378'],
        );

        $result = app(PlaceResolver::class)->resolve($candidate, $destination);

        $this->assertTrue($result->isMatch());
        $this->assertSame($experience->place_id, $result->place->id);
    }

    public function test_the_same_place_from_a_second_provider_is_matched_on_proximity_and_name(): void
    {
        $destination = $this->destination();
        $experience = $this->experience($destination, [
            'slug' => 'borough-market',
            'title' => 'Borough Market',
            'lat' => 51.5055,
            'lng' => -0.0910,
        ]);

        $result = app(PlaceResolver::class)->resolve(
            $this->candidate('Borough Market', 51.5056, -0.0911),
            $destination,
        );

        $this->assertTrue($result->isMatch());
        $this->assertSame($experience->place_id, $result->place->id);
        $this->assertGreaterThan(0.8, $result->confidence);
    }

    public function test_a_genuinely_different_place_nearby_is_created_not_merged(): void
    {
        $destination = $this->destination();
        $this->experience($destination, ['slug' => 'a', 'title' => 'Borough Market', 'lat' => 51.5055, 'lng' => -0.0910]);

        $result = app(PlaceResolver::class)->resolve(
            $this->candidate('Southwark Cathedral', 51.5058, -0.0905),
            $destination,
        );

        $this->assertSame(PlaceResolution::CREATED, $result->decision);
    }

    public function test_an_ambiguous_near_match_is_held_for_a_human(): void
    {
        $destination = $this->destination();
        $this->experience($destination, [
            'slug' => 'seaport-museum',
            'title' => 'South Street Seaport Museum',
            'lat' => 40.7063,
            'lng' => -74.0031,
        ]);

        $result = app(PlaceResolver::class)->resolve(
            $this->candidate('South Street Seaport Historic District', 40.7064, -74.0032),
            $destination,
        );

        $this->assertSame(PlaceResolution::NEEDS_REVIEW, $result->decision);
        $this->assertNull($result->place, 'Nothing is written until someone decides.');

        $candidate = PlaceMergeCandidate::firstOrFail();
        $this->assertSame('pending', $candidate->status);
        $this->assertSame('South Street Seaport Museum', $candidate->signals['existing_place']);
    }

    public function test_a_place_in_another_city_is_never_matched(): void
    {
        $london = $this->destination(['slug' => 'london-test', 'name' => 'London']);
        $paris = $this->destination(['slug' => 'paris-test', 'name' => 'Paris', 'lat' => 48.8566, 'lng' => 2.3522]);

        $this->experience($london, ['slug' => 'notre', 'title' => 'Notre Dame', 'lat' => 51.5055, 'lng' => -0.0910]);

        $result = app(PlaceResolver::class)->resolve(
            $this->candidate('Notre Dame', 51.5055, -0.0910),
            $paris,
        );

        $this->assertSame(PlaceResolution::CREATED, $result->decision);
    }

    private function candidate(string $name, float $lat, float $lng, string $providerId = 'node/500'): PlaceCandidate
    {
        return new PlaceCandidate(
            provider: 'osm',
            providerId: $providerId,
            name: $name,
            point: new GeoPoint($lat, $lng),
        );
    }
}
