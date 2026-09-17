<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Experiences\Models\Experience;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance 57: an experience page distinguishes current dynamic facts from
 * descriptive content.
 */
class ExperienceDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_dynamic_facts_are_separated_from_description_and_carry_provenance(): void
    {
        $experience = Experience::where('slug', 'london-tower-of-london')->firstOrFail();

        $response = $this->getJson("/api/experiences/{$experience->id}");

        $response->assertOk();

        /* Descriptive content has no freshness — it does not go stale. */
        $this->assertNotEmpty($response->json('data.descriptive.why_it_matters'));
        $this->assertNotEmpty($response->json('data.descriptive.know_before_you_go'));
        $this->assertArrayNotHasKey('freshness', $response->json('data.descriptive'));

        /* Every dynamic fact says where it came from and how old it is. */
        foreach (['price_from', 'opening_hours'] as $fact) {
            $this->assertArrayHasKey('freshness', $response->json("data.dynamic.{$fact}"));
            $this->assertArrayHasKey('source', $response->json("data.dynamic.{$fact}.freshness"));
            $this->assertArrayHasKey('is_stale', $response->json("data.dynamic.{$fact}.freshness"));
        }

        $this->assertArrayHasKey('freshness', $response->json('data.dynamic.rating'));
        $this->assertNotNull($response->json('data.dynamic.opening_hours.open_now'));
    }

    public function test_the_page_reports_transparent_signals_rather_than_a_tourist_trap_label(): void
    {
        $experience = Experience::where('slug', 'london-tower-of-london')->firstOrFail();

        $response = $this->getJson("/api/experiences/{$experience->id}");

        $signals = $response->json('data.signals');

        $this->assertArrayHasKey('tourist_concentration', $signals);
        $this->assertArrayHasKey('value_for_money', $signals);
        $this->assertArrayHasKey('uniqueness', $signals);
        $this->assertStringNotContainsStringIgnoringCase('tourist trap', json_encode($response->json()));
    }

    public function test_accessibility_claims_are_attributed_and_never_assumed(): void
    {
        $experience = Experience::where('slug', 'london-columbia-road-flower-market')->firstOrFail();

        $response = $this->getJson("/api/experiences/{$experience->id}");

        $this->assertNotNull($response->json('data.accessibility.source'));
        $this->assertStringContainsString('Confirm with the venue', $response->json('data.accessibility.note'));
        $this->assertFalse($response->json('data.accessibility.claims.wheelchair_accessible'));
    }

    public function test_related_experiences_come_from_the_experience_graph(): void
    {
        $experience = Experience::where('slug', 'london-borough-market')->firstOrFail();

        $related = $this->getJson("/api/experiences/{$experience->id}")->json('data.related');

        $this->assertArrayHasKey('walkable_to', $related);
        $this->assertArrayHasKey('cheaper_alternative', $related);
        $this->assertSame('Maltby Street Market', $related['cheaper_alternative'][0]['title']);
    }

    public function test_the_detail_page_states_where_its_content_came_from(): void
    {
        $experience = Experience::where('slug', 'london-borough-market')->firstOrFail();

        $this->assertSame('seed_demo', $this->getJson("/api/experiences/{$experience->id}")->json('data.data_source'));
    }
}
