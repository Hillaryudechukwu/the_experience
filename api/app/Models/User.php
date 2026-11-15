<?php

namespace App\Models;

use App\Domains\Identity\Mail\ResetPasswordMail;
use App\Domains\Identity\Mail\VerifyEmailMail;
use App\Domains\Identity\Models\SocialIdentity;
use App\Domains\Identity\Services\AuthMailLinks;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function socialIdentities(): HasMany
    {
        return $this->hasMany(SocialIdentity::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function getEmailForVerification(): string
    {
        return (string) $this->email;
    }

    public function sendEmailVerificationNotification(): void
    {
        $links = app(AuthMailLinks::class);

        Mail::to($this->email)->send(new VerifyEmailMail(
            travellerName: (string) ($this->name ?? ''),
            verifyUrl: $links->verifyEmailUrl($this),
        ));
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $links = app(AuthMailLinks::class);

        Mail::to($this->email)->send(new ResetPasswordMail(
            travellerName: (string) ($this->name ?? ''),
            resetUrl: $links->resetPasswordUrl((string) $this->email, $token),
        ));
    }
}
