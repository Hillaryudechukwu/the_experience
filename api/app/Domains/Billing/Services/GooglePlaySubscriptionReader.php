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
 *     paymentState?: int|null,
 * }
 */
class GooglePlaySubscriptionReader
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    /** Play is down or slow; the same token may read fine shortly. */
    public const FAILURE_TRANSIENT = 'transient';

    /** Our credentials, package or permissions are wrong; retrying won't help. */
    public const FAILURE_UNCONFIGURED = 'unconfigured';

    /** Play doesn't know the token (refunded, revoked, or never valid). */
    public const FAILURE_INVALID_TOKEN = 'invalid_token';

    private const FAILURE_PRIORITY = [
        self::FAILURE_TRANSIENT => 3,
        self::FAILURE_UNCONFIGURED => 2,
        self::FAILURE_INVALID_TOKEN => 1,
    ];

    private ?string $lastFailure = null;

    /**
     * @return SubscriptionDetail|null
     */
    public function read(string $purchaseToken, ?string $productId = null): ?array
    {
        $this->lastFailure = null;
        $package = (string) config('experience.billing.google.package_name');
        $credentials = $this->credentials();

        if ($package === '' || $credentials === null) {
            Log::warning('Play subscription read skipped: package name or service account missing.');
            $this->lastFailure = self::FAILURE_UNCONFIGURED;

            return null;
        }

        $accessToken = $this->accessToken($credentials);

        if ($accessToken === null) {
            $this->lastFailure ??= self::FAILURE_UNCONFIGURED;

            return null;
        }

        $detail = $this->readV2($package, $purchaseToken, $accessToken);

        if ($detail !== null) {
            return $detail;
        }

        if ($productId === null || $productId === '') {
            $productId = (string) config('experience.billing.products.google');
        }

        $detail = $this->readV1($package, $productId, $purchaseToken, $accessToken);

        if ($detail !== null) {
            $this->lastFailure = null;
        }

        return $detail;
    }

    /**
     * Why the last read() returned null, when it did: one of the FAILURE_*
     * constants. Null after a successful read. When v2 and v1 both fail,
     * the more retryable reason wins, so a Play outage is never reported
     * as a dead token.
     */
    public function lastFailure(): ?string
    {
        return $this->lastFailure;
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

        try {
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->withToken($accessToken)
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $exception) {
            Log::warning('Play subscriptions v2 read could not reach Play.', [
                'reason' => $exception->getMessage(),
            ]);
            $this->noteFailure(self::FAILURE_TRANSIENT);

            return null;
        }

        if (! $response->successful()) {
            $this->noteFailure($this->classify($response->status()));
            Log::info('Play subscriptions v2 read failed.', [
                'status' => $response->status(),
                'reason' => data_get($response->json(), 'error.errors.0.reason')
                    ?? data_get($response->json(), 'error.message'),
            ]);

            return null;
        }

        $body = $response->json();

        if (! is_array($body)) {
            $this->noteFailure(self::FAILURE_TRANSIENT);

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

        try {
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->withToken($accessToken)
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $exception) {
            Log::warning('Play subscriptions v1 read could not reach Play.', [
                'reason' => $exception->getMessage(),
            ]);
            $this->noteFailure(self::FAILURE_TRANSIENT);

            return null;
        }

        if (! $response->successful()) {
            $this->noteFailure($this->classify($response->status()));
            Log::warning('Play subscriptions v1 read failed.', [
                'status' => $response->status(),
                'reason' => data_get($response->json(), 'error.errors.0.reason')
                    ?? data_get($response->json(), 'error.message'),
            ]);

            return null;
        }

        $body = $response->json();

        if (! is_array($body)) {
            $this->noteFailure(self::FAILURE_TRANSIENT);

            return null;
        }

        return [
            'expiryTimeMillis' => $body['expiryTimeMillis'] ?? null,
            'autoResumeTimeMillis' => $body['autoResumeTimeMillis'] ?? null,
            'productId' => $productId,
            'autoRenewing' => isset($body['autoRenewing']) ? (bool) $body['autoRenewing'] : null,
            'acknowledgementState' => $body['acknowledgementState'] ?? null,
            'paymentState' => isset($body['paymentState']) ? (int) $body['paymentState'] : null,
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

        try {
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->asForm()
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Play service-account token exchange could not reach Google.', [
                'reason' => $exception->getMessage(),
            ]);
            $this->lastFailure = self::FAILURE_TRANSIENT;

            return null;
        }

        if (! $response->successful()) {
            $this->lastFailure = $response->serverError() || $response->status() === 429
                ? self::FAILURE_TRANSIENT
                : self::FAILURE_UNCONFIGURED;
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
        $key = $this->normalizePrivateKey((string) ($decoded['private_key'] ?? ''));

        if ($email === '' || $key === '') {
            return null;
        }

        return [
            'client_email' => $email,
            'private_key' => $key,
        ];
    }

    /**
     * .env often stores the SA JSON on one line; if the private_key field was
     * double-escaped, openssl sees literal "\n" and refuses the key. Real PEM
     * newlines are required for the Play token exchange.
     */
    private function normalizePrivateKey(string $key): string
    {
        if ($key === '') {
            return '';
        }

        if (str_contains($key, '\\n') && ! str_contains($key, "\n")) {
            $key = str_replace('\\n', "\n", $key);
        }

        return str_replace("\r\n", "\n", $key);
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

    private function classify(int $status): string
    {
        return match (true) {
            $status === 404, $status === 410 => self::FAILURE_INVALID_TOKEN,
            $status === 401, $status === 403 => self::FAILURE_UNCONFIGURED,
            default => self::FAILURE_TRANSIENT,
        };
    }

    private function noteFailure(string $failure): void
    {
        if ($this->lastFailure === null
            || self::FAILURE_PRIORITY[$failure] > self::FAILURE_PRIORITY[$this->lastFailure]) {
            $this->lastFailure = $failure;
        }
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
