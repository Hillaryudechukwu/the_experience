<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Billing\Services\GooglePubSubVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * RTDN verification must survive brief outbound timeouts to Google's JWKS,
 * without letting unauthenticated tokens drive outbound fetches.
 */
class GooglePubSubJwksCacheTest extends TestCase
{
    private const CACHE_KEY = 'billing.google.pubsub.jwks.v2';

    private const CERTS = 'www.googleapis.com/oauth2/v3/certs';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_signing_keys_are_cached_after_a_successful_fetch(): void
    {
        Http::fake([
            self::CERTS => Http::response(
                ['keys' => [$this->jwk('abc')]],
                200,
                ['Cache-Control' => 'public, max-age=7200'],
            ),
        ]);

        [$keys, $fetched] = $this->invokeSigningKeys(fresh: true);

        $this->assertTrue($fetched);
        $this->assertSame('abc', $keys[0]['kid']);
        $entry = Cache::get(self::CACHE_KEY);
        $this->assertSame($keys, $entry['keys']);
        $this->assertEqualsWithDelta(time() + 7200, $entry['fresh_until'], 5);
        Http::assertSentCount(1);

        Http::fake([self::CERTS => Http::response(['keys' => []], 500)]);

        [$cached, $fetchedAgain] = $this->invokeSigningKeys(fresh: false);

        $this->assertFalse($fetchedAgain);
        $this->assertSame('abc', $cached[0]['kid']);
        Http::assertSentCount(0);
    }

    public function test_expired_keys_are_used_when_google_times_out(): void
    {
        Cache::put(self::CACHE_KEY, [
            'keys' => [$this->jwk('stale')],
            'fresh_until' => time() - 60,
        ], 86400);

        Http::fake([self::CERTS => fn () => throw new ConnectionException('Connection timed out')]);
        Log::spy();

        [$keys] = $this->invokeSigningKeys(fresh: false);

        $this->assertSame('stale', $keys[0]['kid']);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'using cached keys')
        )->once();
    }

    public function test_empty_result_when_fetch_fails_and_cache_is_empty(): void
    {
        Http::fake([self::CERTS => fn () => throw new ConnectionException('Connection timed out')]);
        Log::spy();

        [$keys] = $this->invokeSigningKeys(fresh: true);

        $this->assertSame([], $keys);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'could not read signing keys')
        )->once();
    }

    public function test_unknown_kid_forces_at_most_one_refresh_per_cooldown(): void
    {
        Cache::put(self::CACHE_KEY, [
            'keys' => [$this->jwk('known')],
            'fresh_until' => time() + 3600,
        ], 86400);

        $calls = 0;
        Http::fake([self::CERTS => function () use (&$calls) {
            $calls++;

            return Http::response(['keys' => [$this->jwk('known')]]);
        }]);

        $verifier = new GooglePubSubVerifier;
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($this->invokeClaims($verifier, $this->token('attacker-'.$i)));
        }

        $this->assertSame(1, $calls);
    }

    public function test_cold_cache_failure_does_not_retry_twice(): void
    {
        $calls = 0;
        Http::fake([self::CERTS => function () use (&$calls) {
            $calls++;
            throw new ConnectionException('Connection timed out');
        }]);

        $this->assertNull($this->invokeClaims(new GooglePubSubVerifier, $this->token('any')));

        $this->assertSame(2, $calls);
    }

    /** @return array<string, string> */
    private function jwk(string $kid): array
    {
        return ['kid' => $kid, 'kty' => 'RSA', 'n' => 'n', 'e' => 'AQAB'];
    }

    private function token(string $kid): string
    {
        $encode = fn (array $data) => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'kid' => $kid]).'.'.$encode(['sub' => 'x']).'.c2ln';
    }

    /** @return array{0: list<array<string, mixed>>, 1: bool} */
    private function invokeSigningKeys(bool $fresh): array
    {
        $verifier = new GooglePubSubVerifier;
        $method = new \ReflectionMethod($verifier, 'signingKeys');

        return $method->invoke($verifier, $fresh);
    }

    private function invokeClaims(GooglePubSubVerifier $verifier, string $token): ?array
    {
        return (new \ReflectionMethod($verifier, 'claims'))->invoke($verifier, $token);
    }
}
