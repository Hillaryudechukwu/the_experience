<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a Google Play subscription period from a purchase token.
 *
 * Real-time developer notifications carry only the token. The period that
 * decides `paid_until` comes from the Play Developer API. Until credentials
 * exist this returns null and callers fail closed — a month that cannot be
 * read is not a month in place (Subscription JEM §11 P3).
 *
 * @phpstan-type SubscriptionDetail array{
 *     expiryTimeMillis?: string|int|null,
 *     autoResumeTimeMillis?: string|int|null,
 *     productId?: string|null,
 *     autoRenewing?: bool|null,
 *     acknowledgementState?: int|null,
 *     subscriptionState?: string|null,
 * }
 */
class GooglePlaySubscriptionReader
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    /**
     * @return SubscriptionDetail|null
     */
    public function read(string $purchaseToken, ?string $productId = null): ?array
    {
        $package = (string) config('experience.billing.google.package_name');
        $credentials = $this->credentials();

        if ($package === '' || $credentials === null) {
            Log::warning('Play subscription read skipped: package name or service account missing.');

            return null;
        }

        $accessToken = $this->accessToken($credentials);

        if ($accessToken === null) {
            return null;
        }

        $detail = $this->readV2($package, $purchaseToken, $accessToken);

        if ($detail !== null) {
            return $detail;
        }

        if ($productId === null || $productId === '') {
            $productId = (string) config('experience.billing.products.google');
        }

        return $this->readV1($package, $productId, $purchaseToken, $accessToken);
    }

    public function isConfigured(): bool
    {
        return (string) config('experience.billing.google.package_name') !== ''
            && $this->credentials() !== null;
    }

    /**
     * @return SubscriptionDetail|null
     */
    private function readV2(string $package, string $token, string $accessToken): ?array
    {
        $url = sprintf(
            'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptionsv2/tokens/%s',
            rawurlencode($package),
            rawurlencode($token),
        );

        $response = Http::connectTimeout(3)
            ->timeout(10)
            ->withToken($accessToken)
            ->acceptJson()
            ->get($url);

        if (! $response->successful()) {
            Log::info('Play subscriptions v2 read failed.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $line = is_array($body['lineItems'][0] ?? null) ? $body['lineItems'][0] : [];
        $expiry = $line['expiryTime'] ?? null;
        $expiryMs = is_string($expiry) ? $this->rfc3339ToMillis($expiry) : null;
        $productId = isset($line['productId']) ? (string) $line['productId'] : null;
        $autoRenewing = isset($line['autoRenewingPlan']);

        return array_filter([
            'expiryTimeMillis' => $expiryMs,
            'productId' => $productId,
            'autoRenewing' => $autoRenewing,
            'subscriptionState' => isset($body['subscriptionState']) ? (string) $body['subscriptionState'] : null,
            'acknowledgementState' => $body['acknowledgementState'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return SubscriptionDetail|null
     */
    private function readV1(string $package, string $productId, string $token, string $accessToken): ?array
    {
        $url = sprintf(
            'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptions/%s/tokens/%s',
            rawurlencode($package),
            rawurlencode($productId),
            rawurlencode($token),
        );

        $response = Http::connectTimeout(3)
            ->timeout(10)
            ->withToken($accessToken)
            ->acceptJson()
            ->get($url);

        if (! $response->successful()) {
            Log::warning('Play subscriptions v1 read failed.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        return [
            'expiryTimeMillis' => $body['expiryTimeMillis'] ?? null,
            'autoResumeTimeMillis' => $body['autoResumeTimeMillis'] ?? null,
            'productId' => $productId,
            'autoRenewing' => isset($body['autoRenewing']) ? (bool) $body['autoRenewing'] : null,
            'acknowledgementState' => $body['acknowledgementState'] ?? null,
        ];
    }

    /**
     * @param  array{client_email: string, private_key: string}  $credentials
     */
    private function accessToken(array $credentials): ?string
    {
        $cacheKey = 'billing:google-play-access-token:'.hash('sha256', $credentials['client_email']);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $assertion = $this->jwt([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $credentials['private_key']);

        if ($assertion === null) {
            return null;
        }

        $response = Http::connectTimeout(3)
            ->timeout(10)
            ->asForm()
            ->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if (! $response->successful()) {
            Log::warning('Play service-account token exchange failed.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if ($token === '') {
            return null;
        }

        Cache::put($cacheKey, $token, max(60, $expiresIn - 60));

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string}|null
     */
    private function credentials(): ?array
    {
        $raw = (string) config('experience.billing.google.service_account_json');

        if (trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            Log::warning('Play service account JSON is not valid JSON.');

            return null;
        }

        $email = (string) ($decoded['client_email'] ?? '');
        $key = (string) ($decoded['private_key'] ?? '');

        if ($email === '' || $key === '') {
            return null;
        }

        return [
            'client_email' => $email,
            'private_key' => $key,
        ];
    }

    /** @param  array<string, mixed>  $claims */
    private function jwt(array $claims, string $privateKey): ?string
    {
        $header = $this->b64(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = $this->b64($claims);
        $unsigned = $header.'.'.$payload;

        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            Log::warning('Play service account private key could not be parsed.');

            return null;
        }

        $signature = '';

        if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $unsigned.'.'.$this->b64url($signature);
    }

    private function rfc3339ToMillis(string $time): ?int
    {
        try {
            return (int) (new \DateTimeImmutable($time))->format('Uv');
        } catch (\Exception) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $value */
    private function b64(array $value): string
    {
        return $this->b64url(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
