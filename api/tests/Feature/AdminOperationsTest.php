<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\ExternalSources\Models\ProviderSyncFailure;
use App\Domains\Experiences\Models\Experience;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance 64: admin can correct provider mappings and inspect failed syncs.
 */
class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::create([
            'name' => 'Ops',
            'email' => 'ops@example.com',
            'password' => bcrypt('secret-secret'),
        ]);

        $this->adminToken = $admin->createToken('ops', ['admin'])->plainTextToken;
    }

    public function test_provider_health_is_visible_to_operations(): void
    {
        $response = $this->getJson('/api/admin/providers', ['Authorization' => 'Bearer ' . $this->adminToken]);

        $response->assertOk();
        $providers = collect($response->json('data'))->keyBy('provider');

        $this->assertTrue($providers['sandbox']['configured']);
        $this->assertFalse($providers['viator']['configured']);
        $this->assertArrayHasKey('circuit_open', $providers['sandbox']);
    }

    public function test_failed_syncs_can_be_inspected_and_resolved(): void
    {
        $failure = ProviderSyncFailure::create([
            'provider' => 'sandbox',
            'provider_id' => 'SBX-BROKEN',
            'stage' => 'map_product',
            'message' => 'Price field missing from payload',
            'context' => ['payload' => ['id' => 'SBX-BROKEN']],
        ]);

        $list = $this->getJson('/api/admin/sync-failures', ['Authorization' => 'Bearer ' . $this->adminToken]);
        $list->assertOk();
        $this->assertSame('Price field missing from payload', $list->json('data.0.message'));

        $this->postJson("/api/admin/sync-failures/{$failure->id}/resolve", [], [
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->assertOk();

        $this->assertTrue($failure->fresh()->resolved);
        $this->getJson('/api/admin/sync-failures', ['Authorization' => 'Bearer ' . $this->adminToken])
            ->assertJsonCount(0, 'data');
    }

    public function test_a_provider_mapping_can_be_pointed_at_the_right_canonical_record(): void
    {
        $mapping = ExternalEntity::first();
        $correct = Experience::where('id', '!=', $mapping->entity_id)->first();

        $this->patchJson("/api/admin/external-entities/{$mapping->id}", [
            'entity_id' => $correct->id,
            'confidence' => 0.9,
        ], ['Authorization' => 'Bearer ' . $this->adminToken])->assertOk();

        $this->assertSame($correct->id, $mapping->fresh()->entity_id);
    }

    public function test_any_recommendation_can_be_explained_after_the_fact(): void
    {
        $setId = $this->postJson('/api/discovery/now', ['destination' => 'london'])->json('recommendation_set_id');

        $response = $this->getJson("/api/admin/recommendation-sets/{$setId}", [
            'Authorization' => 'Bearer ' . $this->adminToken,
        ]);

        $response->assertOk();
        $this->assertSame('now', $response->json('data.surface'));
        $this->assertNotNull($response->json('data.context.weights'));
        $this->assertNotNull($response->json('data.context.engine_version'));
        $this->assertNotEmpty($response->json('data.recommendations.0.reasons'));
    }

    public function test_operations_endpoints_are_closed_to_ordinary_travellers(): void
    {
        $this->getJson('/api/admin/providers')->assertStatus(401);

        /* Tokens issued by the public API are scoped to the traveller ability,
           never Sanctum's default wildcard. */
        $token = $this->postJson('/api/auth/register', [
            'name' => 'Traveller',
            'email' => 't@example.com',
            'password' => 'secret-secret',
        ])->json('token');

        $this->getJson('/api/admin/providers', ['Authorization' => 'Bearer ' . $token])->assertStatus(403);
    }
}
