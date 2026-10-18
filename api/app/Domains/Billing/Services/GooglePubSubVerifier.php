<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use Illuminate\Http\Request;
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

    public function verify(Request $request): bool
    {
        $audience = (string) config('experience.billing.google.pubsub_audience');

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
     * not a shortcut.
     */
    private function claims(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        try {
            $keys = Http::timeout(5)->get(self::CERTS)->json('keys');
        } catch (\Throwable $exception) {
            Log::warning('Google notification refused: could not read signing keys.', [
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! is_array($keys)) {
            return null;
        }

        $header = $this->segment($parts[0]);
        $kid = (string) ($header['kid'] ?? '');
        $key = collect($keys)->firstWhere('kid', $kid);

        if (! is_array($key) || ! $this->signatureHolds($parts, $key, (string) ($header['alg'] ?? ''))) {
            return null;
        }

        return $this->segment($parts[1]);
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
