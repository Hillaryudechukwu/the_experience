<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Identity\Exceptions\IdentityTokenRejected;
use App\Domains\Identity\Services\GoogleIdTokenVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleIdTokenVerifierTest extends TestCase
{
    public function test_a_token_for_another_audience_is_rejected(): void
    {
        [$token] = $this->token(['aud' => 'some-other-app', 'email' => 'a@example.com', 'email_verified' => true]);
        config(['experience.identity.google_client_ids' => ['our-app']]);

        $this->expectException(IdentityTokenRejected::class);
        $this->expectExceptionMessage('audience');

        app(GoogleIdTokenVerifier::class)->verify($token);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        [$token] = $this->token([
            'aud' => 'our-app',
            'exp' => time() - 120,
            'email' => 'a@example.com',
            'email_verified' => true,
        ]);
        config(['experience.identity.google_client_ids' => ['our-app']]);

        $this->expectException(IdentityTokenRejected::class);
        $this->expectExceptionMessage('expired');

        app(GoogleIdTokenVerifier::class)->verify($token);
    }

    public function test_a_valid_token_returns_the_subject_and_verified_email(): void
    {
        [$token] = $this->token([
            'aud' => 'our-app',
            'email' => 'A@Example.com',
            'email_verified' => true,
            'name' => 'Ada',
        ]);
        config(['experience.identity.google_client_ids' => ['our-app']]);

        $verified = app(GoogleIdTokenVerifier::class)->verify($token);

        $this->assertSame('google', $verified->provider);
        $this->assertSame('subject-1', $verified->subject);
        $this->assertSame('a@example.com', $verified->email);
        $this->assertTrue($verified->emailVerified);
        $this->assertSame('Ada', $verified->name);
    }

    /** @param array<string, mixed> $claims @return array{0: string} */
    private function token(array $claims): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $details = openssl_pkey_get_details($key);
        $n = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
        $e = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');

        Cache::put('identity.jwks.google', [[
            'kty' => 'RSA',
            'kid' => 'test-key',
            'alg' => 'RS256',
            'n' => $n,
            'e' => $e,
        ]], 3600);

        Http::preventStrayRequests();

        $header = $this->b64(json_encode(['alg' => 'RS256', 'kid' => 'test-key', 'typ' => 'JWT']));
        $payload = $this->b64(json_encode(array_merge([
            'iss' => 'https://accounts.google.com',
            'sub' => 'subject-1',
            'exp' => time() + 600,
            'iat' => time(),
        ], $claims)));
        openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256);

        return [$header.'.'.$payload.'.'.$this->b64($signature)];
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
