<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Billing\Services\GooglePubSubVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * RTDN verification must survive brief outbound timeouts to Google's JWKS.
 */
class GooglePubSubJwksCacheTest extends TestCase
{
    public function test_signing_keys_are_cached_after_a_successful_fetch(): void
    {
        Http::fake([
            'www.googleapis.com/oauth2/v3/certs' => Http::response(
                ['keys' => [['kid' => 'abc', 'kty' => 'RSA', 'n' => 'n', 'e' => 'AQAB']]],
                200,
                ['Cache-Control' => 'public, max-age=7200'],
            ),
        ]);

        $keys = $this->invokeSigningKeys(fresh: true);

        $this->assertCount(1, $keys);
        $this->assertSame('abc', $keys[0]['kid']);
        $this->assertSame($keys, Cache::get('billing.google.pubsub.jwks'));
        Http::assertSentCount(1);

        /* A second call should hit the cache, not Google. */
        Http::fake([
            'www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => []], 500),
        ]);

        $cached = $this->invokeSigningKeys(fresh: false);

        $this->assertSame('abc', $cached[0]['kid']);
        Http::assertSentCount(0);
    }

    public function test_stale_cache_is_used_when_google_times_out(): void
    {
        Cache::put('billing.google.pubsub.jwks', [
            ['kid' => 'stale', 'kty' => 'RSA', 'n' => 'n', 'e' => 'AQAB'],
        ], 3600);

        Http::fake([
            'www.googleapis.com/oauth2/v3/certs' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out after 5001 milliseconds');
            },
        ]);

        Log::spy();

        $keys = $this->invokeSigningKeys(fresh: true);

        $this->assertSame('stale', $keys[0]['kid']);
        Log::shouldHaveReceived('warning')->withArgs(function (string $message) {
            return str_contains($message, 'using cached keys');
        })->once();
    }

    public function test_empty_result_when_fetch_fails_and_cache_is_empty(): void
    {
        Cache::forget('billing.google.pubsub.jwks');

        Http::fake([
            'www.googleapis.com/oauth2/v3/certs' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out after 5001 milliseconds');
            },
        ]);

        Log::spy();

        $keys = $this->invokeSigningKeys(fresh: true);

        $this->assertSame([], $keys);
        Log::shouldHaveReceived('warning')->withArgs(function (string $message) {
            return str_contains($message, 'could not read signing keys');
        })->once();
    }

    /** @return list<array<string, mixed>> */
    private function invokeSigningKeys(bool $fresh): array
    {
        $verifier = new GooglePubSubVerifier;
        $method = new \ReflectionMethod($verifier, 'signingKeys');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $keys */
        $keys = $method->invoke($verifier, $fresh);

        return $keys;
    }
}
