<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Identity\Mail\ResetPasswordMail;
use App\Domains\Identity\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthPasswordAndEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_queues_a_verification_email_and_leaves_the_mailbox_unconfirmed(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password123',
        ])->assertCreated();

        $response->assertJsonPath('user.email_verified', false);

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'email_verified_at' => null,
        ]);

        Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail) => $mail->hasTo('ada@example.com'));
    }

    public function test_verify_email_marks_the_account_confirmed(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'ada@example.com',
        ]);

        $this->postJson('/api/auth/email/verify', [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ])
            ->assertOk()
            ->assertJsonPath('status', 'verified')
            ->assertJsonPath('user.email_verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_forgot_password_queues_a_reset_mail_without_revealing_whether_the_email_exists(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/auth/password/forgot', ['email' => 'ada@example.com'])
            ->assertOk()
            ->assertJsonPath('status', 'sent');

        $this->postJson('/api/auth/password/forgot', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('status', 'sent');

        Mail::assertQueued(ResetPasswordMail::class, 1);
    }

    public function test_reset_password_updates_the_hash_and_revokes_tokens(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'old-password-1',
        ]);
        $user->createToken('mobile', ['traveller']);

        $resetToken = Password::broker()->createToken($user);

        $this->postJson('/api/auth/password/reset', [
            'email' => 'ada@example.com',
            'token' => $resetToken,
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'reset');

        $this->assertTrue(Hash::check('new-password-9', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'old-password-1',
        ])->assertUnprocessable();

        $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'new-password-9',
        ])->assertOk();
    }

    public function test_signed_in_traveller_can_resend_verification(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('status', 'sent');

        Mail::assertQueued(VerifyEmailMail::class, 1);
    }
}
