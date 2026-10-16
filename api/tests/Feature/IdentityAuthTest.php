<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Identity\DTO\VerifiedIdentity;
use App\Domains\Identity\Models\SocialIdentity;
use App\Domains\Identity\Services\GoogleIdTokenVerifier;
use App\Domains\Journeys\Models\Journey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_creates_then_returns_the_same_user_and_adopts_the_guest_journey(): void
    {
        $token = (string) $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $this->withHeader('X-Guest-Token', $token)->postJson('/api/journeys', [
            'destination_id' => $this->destinationId(),
            'reason' => 'holiday',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-03',
        ])->assertCreated();

        $this->mockVerifier(new VerifiedIdentity('google', 'sub-1', 'ada@example.com', true, 'Ada'));

        $created = $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/auth/google', ['id_token' => 'valid'])
            ->assertCreated()
            ->assertJsonPath('user.email', 'ada@example.com')
            ->assertJsonPath('user.providers.0', 'google');

        $this->mockVerifier(new VerifiedIdentity('google', 'sub-1', 'ada@example.com', true, 'Ada'));

        $again = $this->postJson('/api/auth/google', ['id_token' => 'valid'])->assertOk();
        $this->assertSame($created->json('user.id'), $again->json('user.id'));
        $this->assertSame(1, User::count());
        $this->assertNotNull(Journey::first()->user_id);
    }

    public function test_a_verified_email_links_to_the_password_account(): void
    {
        $existing = User::factory()->create(['email' => 'ada@example.com']);
        $this->mockVerifier(new VerifiedIdentity('google', 'sub-2', 'Ada@Example.com', true, 'Ada'));

        $this->postJson('/api/auth/google', ['id_token' => 'valid'])
            ->assertOk()
            ->assertJsonPath('user.id', $existing->id);

        $this->assertSame(1, User::count());
        $this->assertSame(1, SocialIdentity::count());
    }

    public function test_an_unverified_email_creates_nobody(): void
    {
        $this->mockVerifier(new VerifiedIdentity('google', 'sub-3', 'ada@example.com', false, 'Ada'));

        $this->postJson('/api/auth/google', ['id_token' => 'valid'])->assertStatus(422);
        $this->assertSame(0, User::count());
    }

    public function test_a_provider_account_deletes_only_with_a_matching_fresh_token(): void
    {
        $this->mockVerifier(new VerifiedIdentity('google', 'sub-4', 'ada@example.com', true, 'Ada'));
        $created = $this->postJson('/api/auth/google', ['id_token' => 'valid'])->assertCreated();
        $bearer = $created->json('token');

        $this->deleteJson('/api/auth/account', ['password' => 'invented-password'], [
            'Authorization' => "Bearer {$bearer}",
        ])->assertStatus(422);

        $this->mockVerifier(new VerifiedIdentity('google', 'someone-else', 'ada@example.com', true, 'Ada'));
        $this->deleteJson('/api/auth/account', [
            'id_token' => 'other',
            'provider' => 'google',
        ], ['Authorization' => "Bearer {$bearer}"])->assertStatus(422);
        $this->assertSame(1, User::count());

        $this->mockVerifier(new VerifiedIdentity('google', 'sub-4', 'ada@example.com', true, 'Ada'));
        $this->deleteJson('/api/auth/account', [
            'id_token' => 'fresh',
            'provider' => 'google',
        ], ['Authorization' => "Bearer {$bearer}"])->assertOk();
        $this->assertSame(0, User::count());
    }

    private function mockVerifier(VerifiedIdentity $identity): void
    {
        $this->mock(GoogleIdTokenVerifier::class, function ($mock) use ($identity) {
            $mock->shouldReceive('verify')->andReturn($identity);
        });
    }

    private function destinationId(): string
    {
        return Destination::create([
            'slug' => 'identity-city',
            'name' => 'Identity City',
            'country' => 'Testland',
            'country_code' => 'GB',
            'timezone' => 'Europe/London',
            'lat' => 51.5,
            'lng' => -0.12,
            'currency' => 'GBP',
            'languages' => ['en'],
        ])->id;
    }
}
