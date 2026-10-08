<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Email addresses are folded to lowercase on register, and login must find
 * travellers even when the stored row still has mixed case from an older
 * client. Without that, a mobile client that lowercases before POST breaks
 * sign-in for any account that was created with capitals.
 */
class AuthEmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_stores_the_email_in_lowercase(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Sam Traveller',
            'email' => 'Sam.Traveller@Example.COM',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'sam.traveller@example.com');

        $this->assertDatabaseHas('users', ['email' => 'sam.traveller@example.com']);
    }

    public function test_login_matches_a_mixed_case_stored_email(): void
    {
        User::create([
            'name' => 'Legacy Traveller',
            'email' => 'Legacy.User@Example.COM',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'legacy.user@example.com',
            'password' => 'correct-horse-battery',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'Legacy.User@Example.COM');
    }

    public function test_login_accepts_mixed_case_input_for_a_lowercased_account(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Sam Traveller',
            'email' => 'sam@example.com',
            'password' => 'correct-horse-battery',
        ])->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'Sam@Example.com',
            'password' => 'correct-horse-battery',
        ])->assertOk();
    }

    public function test_register_rejects_a_case_variant_of_an_existing_email(): void
    {
        User::create([
            'name' => 'Legacy Traveller',
            'email' => 'Legacy.User@Example.COM',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Sam Traveller',
            'email' => 'legacy.user@example.com',
            'password' => 'correct-horse-battery',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
