<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Passport\Models\SavedExperience;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance 51: a guest can browse without creating an account.
 * Acceptance 62: a user can save and later revisit experiences.
 */
class GuestBrowsingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_guest_gets_a_session_token_on_first_contact_and_can_browse(): void
    {
        $response = $this->getJson('/api/destinations');

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('X-Guest-Token'));
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_a_guest_can_get_recommendations_without_an_account(): void
    {
        $response = $this->postJson('/api/discovery/now', [
            'destination' => 'london',
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
        $this->assertNotNull($response->json('data.0.experience_score'));
    }

    public function test_a_guest_can_save_an_experience_and_read_it_back(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $experienceId = $this->getJson('/api/destinations')->json('data.0.id');

        $experienceId = $this->postJson('/api/discovery/now', ['destination' => 'london'], ['X-Guest-Token' => $token])
            ->json('data.0.id');

        $this->postJson("/api/experiences/{$experienceId}/save", [], ['X-Guest-Token' => $token])
            ->assertCreated();

        $saved = $this->getJson('/api/experiences/saved', ['X-Guest-Token' => $token]);

        $saved->assertOk();
        $this->assertSame($experienceId, $saved->json('data.0.id'));
        $this->assertDatabaseCount('saved_experiences', 1);
    }

    public function test_one_guest_cannot_see_another_guests_saves(): void
    {
        $tokenA = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $tokenB = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        $experienceId = $this->postJson('/api/discovery/now', ['destination' => 'london'], ['X-Guest-Token' => $tokenA])
            ->json('data.0.id');

        $this->postJson("/api/experiences/{$experienceId}/save", [], ['X-Guest-Token' => $tokenA]);

        $this->assertCount(1, SavedExperience::all());
        $this->getJson('/api/experiences/saved', ['X-Guest-Token' => $tokenB])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_registering_adopts_what_the_guest_already_did(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $experienceId = $this->postJson('/api/discovery/now', ['destination' => 'london'], ['X-Guest-Token' => $token])
            ->json('data.0.id');

        $this->postJson("/api/experiences/{$experienceId}/save", [], ['X-Guest-Token' => $token]);

        $registration = $this->postJson('/api/auth/register', [
            'name' => 'Sam Traveller',
            'email' => 'sam@example.com',
            'password' => 'correct-horse-battery',
        ], ['X-Guest-Token' => $token]);

        $registration->assertCreated();

        $saved = $this->getJson('/api/experiences/saved', [
            'Authorization' => 'Bearer ' . $registration->json('token'),
        ]);

        $saved->assertOk();
        $this->assertSame($experienceId, $saved->json('data.0.id'));
    }
}
