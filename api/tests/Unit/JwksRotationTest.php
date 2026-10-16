<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Identity\Exceptions\IdentityTokenRejected;
use App\Domains\Identity\Services\GoogleIdTokenVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A provider rotating its signing keys must not lock everyone out.
 *
 * The key set is cached for between one and twenty-four hours. Google rotates
 * regularly, and the moment it does, every new token carries a kid the cached
 * set has never seen — so rejecting on that alone failed every Google and
 * Apple sign-in until the cache expired.
 *
 * The re-fetch needs a floor as well as a ceiling: the kid is supplied by an
 * unauthenticated caller, so without a cooldown a stream of tokens with random
 * kids is a way of making us hammer the provider until they rate limit us,
 * which is the same outage by a longer road.
 */
class JwksRotationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'experience.identity.google_client_ids' => ['our-app'],
            'experience.identity.jwks_refresh_cooldown' => 60,
        ]);
    }

    public function test_a_token_signed_with_a_rotated_key_still_verifies(): void
    {
        [$token, $jwk] = $this->signedWith('new-key');

        /* The cache holds yesterday's key set, which knows nothing of it. */
        Cache::put('identity.jwks.google', [$this->strangerKey()], 3600);
        Http::fake(['*' => Http::response(['keys' => [$this->strangerKey(), $jwk]])]);

        $verified = app(GoogleIdTokenVerifier::class)->verify($token);

        $this->assertSame('subject-1', $verified->subject);
        Http::assertSentCount(1);
    }

    /** The refreshed set is cached, so the next token costs nothing. */
    public function test_the_rotated_key_set_is_cached_after_the_refresh(): void
    {
        [$token, $jwk] = $this->signedWith('new-key');

        Cache::put('identity.jwks.google', [$this->strangerKey()], 3600);
        Http::fake(['*' => Http::response(['keys' => [$jwk]])]);

        app(GoogleIdTokenVerifier::class)->verify($token);
        app(GoogleIdTokenVerifier::class)->verify($token);

        Http::assertSentCount(1);
    }

    /**
     * The one that keeps this from becoming an amplifier: many tokens with
     * unknown kids must cost one upstream request, not one each.
     */
    public function test_a_flood_of_unknown_kids_triggers_a_single_refetch(): void
    {
        Cache::put('identity.jwks.google', [$this->strangerKey()], 3600);
        Http::fake(['*' => Http::response(['keys' => [$this->strangerKey()]])]);

        for ($i = 0; $i < 12; $i++) {
            [$token] = $this->signedWith('kid-'.$i);

            try {
                app(GoogleIdTokenVerifier::class)->verify($token);
                $this->fail('A token signed with an unknown key should be rejected.');
            } catch (IdentityTokenRejected $rejected) {
                $this->assertSame('unknown_key', $rejected->getMessage());
            }
        }

        Http::assertSentCount(1);
    }

    /** With the cooldown off, a genuinely unknown kid is still rejected. */
    public function test_an_unknown_key_is_still_rejected_after_refreshing(): void
    {
        config(['experience.identity.jwks_refresh_cooldown' => 0]);

        [$token] = $this->signedWith('never-published');

        Cache::put('identity.jwks.google', [$this->strangerKey()], 3600);
        Http::fake(['*' => Http::response(['keys' => [$this->strangerKey()]])]);

        $this->expectException(IdentityTokenRejected::class);
        $this->expectExceptionMessage('unknown_key');

        app(GoogleIdTokenVerifier::class)->verify($token);
    }

    /** A key set that already holds the kid must not reach the network. */
    public function test_a_known_kid_never_refetches(): void
    {
        [$token, $jwk] = $this->signedWith('current-key');

        Cache::put('identity.jwks.google', [$jwk], 3600);
        Http::preventStrayRequests();

        $this->assertSame('subject-1', app(GoogleIdTokenVerifier::class)->verify($token)->subject);
    }

    /** @return array{0: string, 1: array<string, string>} token and its public JWK */
    private function signedWith(string $kid): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $details = openssl_pkey_get_details($key);

        $jwk = [
            'kty' => 'RSA',
            'kid' => $kid,
            'alg' => 'RS256',
            'n' => $this->b64($details['rsa']['n']),
            'e' => $this->b64($details['rsa']['e']),
        ];

        $header = $this->b64(json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT']));
        $payload = $this->b64(json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'our-app',
            'sub' => 'subject-1',
            'email' => 'a@example.com',
            'email_verified' => true,
            'exp' => time() + 600,
            'iat' => time(),
        ]));

        openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256);

        return [$header.'.'.$payload.'.'.$this->b64($signature), $jwk];
    }

    /** A valid key for somebody else's token. */
    private function strangerKey(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]));

        return [
            'kty' => 'RSA',
            'kid' => 'yesterdays-key',
            'alg' => 'RS256',
            'n' => $this->b64($details['rsa']['n']),
            'e' => $this->b64($details['rsa']['e']),
        ];
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
