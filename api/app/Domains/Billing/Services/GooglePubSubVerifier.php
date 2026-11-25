<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proves a push came from the Pub/Sub subscription we configured.
 *
 * Same reasoning as the Apple verifier: the endpoint is public because Google
 * calls it, and it writes whether somebody may use a paid product. Google
 * signs the push with an OIDC token whose audience is the one set on the
 * subscription, so an unconfigured audience leaves nothing to check against
 * and the push is refused.
 */
class GooglePubSubVerifier
{
    private const CERTS = 'https://www.googleapis.com/oauth2/v3/certs';

    private const CACHE_KEY = 'billing.google.pubsub.jwks';

    /**
     * @param  ?string  $audience  Defaults to the Play RTDN push audience.
     * @param  ?string  $serviceAccount  Defaults to the Play RTDN push account.
     */
    public function verify(Request $request, ?string $audience = null, ?string $serviceAccount = null): bool
    {
        $audience ??= (string) config('experience.billing.google.pubsub_audience');

        if (trim($audience) === '') {
            Log::warning('Google notification refused: no Pub/Sub audience configured.');

            return false;
        }

        $token = $this->bearer($request);

        if ($token === null) {
            return false;
        }

        $claims = $this->claims($token);

        if ($claims === null) {
            return false;
        }

        /* Audience and issuer both, and an expiry that has not passed. A
           token minted for a different service is still a real Google token. */
        if (! hash_equals($audience, (string) ($claims['aud'] ?? ''))) {
            return false;
        }

        $issuer = (string) ($claims['iss'] ?? '');

        if ($issuer !== 'https://accounts.google.com' && $issuer !== 'accounts.google.com') {
            return false;
        }

        /*
         * Who minted it, not merely that Google did.
         *
         * The audience is a string we chose and put on our own subscription;
         * it is not a secret. Anyone with a Cloud project can point their own
         * push subscription at this URL with the same audience and have
         * Google sign a token for it. Requiring the service account that our
         * subscription actually uses pins the token to a sender.
         */
        $serviceAccount ??= (string) config('experience.billing.google.pubsub_service_account');

        if (trim($serviceAccount) === '') {
            Log::warning('Google notification refused: no Pub/Sub service account configured.');

            return false;
        }

        if (! hash_equals($serviceAccount, (string) ($claims['email'] ?? '')) || ($claims['email_verified'] ?? false) !== true) {
            return false;
        }

        return (int) ($claims['exp'] ?? 0) > time();
    }

    private function bearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }

    /**
     * The token's claims, once its signature has been checked against
     * Google's published keys. A failure to reach those keys is a refusal,
     * not a shortcut — unless a still-fresh cache can cover the gap.
     */
    private function claims(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $header = $this->segment($parts[0]);
        $kid = (string) ($header['kid'] ?? '');
        $keys = $this->signingKeys();
        $key = collect($keys)->firstWhere('kid', $kid);

        /* Unknown kid usually means Google rotated. Force one refresh before
           refusing — same idea as identity JWKS verification. */
        if (! is_array($key)) {
            $keys = $this->signingKeys(fresh: true);
            $key = collect($keys)->firstWhere('kid', $kid);
        }

        if (! is_array($key) || ! $this->signatureHolds($parts, $key, (string) ($header['alg'] ?? ''))) {
            return null;
        }

        return $this->segment($parts[1]);
    }

    /**
     * Google's JWKS, preferring cache. Brief outbound timeouts on shared
     * hosting used to drop every RTDN push; a short cache + one retry keeps
     * verification working across those blips.
     *
     * @return list<array<string, mixed>>
     */
    private function signingKeys(bool $fresh = false): array
    {
        if (! $fresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        }

        $fetched = $this->fetchSigningKeys();

        if ($fetched !== []) {
            return $fetched;
        }

        $stale = Cache::get(self::CACHE_KEY);
        if (is_array($stale) && $stale !== []) {
            Log::warning('Google notification JWKS fetch failed; using cached keys.');

            return $stale;
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSigningKeys(): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::timeout(10)
                    ->connectTimeout(5)
                    ->acceptJson()
                    ->get(self::CERTS);

                if ($response->failed()) {
                    $lastError = 'HTTP '.$response->status();
                    continue;
                }

                $keys = $response->json('keys');
                if (! is_array($keys) || $keys === []) {
                    $lastError = 'empty keys';
                    continue;
                }

                $ttl = 3600;
                if (preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $match) === 1) {
                    $ttl = max(3600, min(86400, (int) $match[1]));
                }

                Cache::put(self::CACHE_KEY, $keys, $ttl);

                return $keys;
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
            }
        }

        Log::warning('Google notification refused: could not read signing keys.', [
            'reason' => $lastError,
        ]);

        return [];
    }

    private function signatureHolds(array $parts, array $key, string $algorithm): bool
    {
        if ($algorithm !== 'RS256' || ($key['kty'] ?? '') !== 'RSA') {
            return false;
        }

        $modulus = $this->decode((string) ($key['n'] ?? ''));
        $exponent = $this->decode((string) ($key['e'] ?? ''));

        if ($modulus === null || $exponent === null) {
            return false;
        }

        $public = openssl_pkey_new([
            'rsa' => ['n' => $modulus, 'e' => $exponent],
        ]);

        if ($public === false) {
            return false;
        }

        $signature = $this->decode($parts[2]);

        return $signature !== null
            && openssl_verify($parts[0].'.'.$parts[1], $signature, $public, OPENSSL_ALGO_SHA256) === 1;
    }

    private function segment(string $segment): array
    {
        $decoded = json_decode((string) $this->decode($segment), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
