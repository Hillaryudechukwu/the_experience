<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use Illuminate\Support\Facades\Log;

/**
 * Proves a notification came from Apple before anything acts on it.
 *
 * This endpoint is public — it has to be, Apple calls it — and what it writes
 * is whether somebody may use a paid product. An endpoint that applied the
 * body it was handed would be a free subscription for anyone who can POST
 * JSON at it. So the rule here is that an unverified payload is not a
 * notification; it is a stranger's opinion.
 *
 * Apple signs with JWS and puts the certificate chain in the `x5c` header:
 * the leaf signs the token, and the chain must terminate at Apple's own root.
 * Checking the signature without checking where the chain ends proves only
 * that *somebody* signed it, which is no better than not checking.
 *
 * Until the root certificate is configured there is nothing to terminate
 * against, so verification fails. Closed, deliberately: a billing endpoint
 * that degrades to trusting its input when misconfigured is worse than one
 * that stops.
 */
class AppleNotificationVerifier
{
    /** @return array|null The decoded payload, or null when it cannot be trusted. */
    public function verify(string $signedPayload): ?array
    {
        $root = (string) config('experience.billing.apple.root_certificate');

        if (trim($root) === '') {
            Log::warning('Apple notification refused: no root certificate configured.');

            return null;
        }

        $parts = explode('.', $signedPayload);

        if (count($parts) !== 3) {
            return null;
        }

        [$header64, $claims64, $signature64] = $parts;

        $header = $this->decodeSegment($header64);
        $chain = $header['x5c'] ?? null;

        if (! is_array($chain) || $chain === []) {
            return null;
        }

        if (! $this->chainTerminatesAtRoot($chain, $root)) {
            Log::warning('Apple notification refused: certificate chain did not reach the configured root.');

            return null;
        }

        if (! $this->signatureHolds($chain[0], $header64.'.'.$claims64, $signature64, (string) ($header['alg'] ?? ''))) {
            Log::warning('Apple notification refused: signature did not verify.');

            return null;
        }

        $claims = $this->decodeSegment($claims64);

        /* A genuine notification for somebody else's app is still not ours. */
        $bundle = (string) config('experience.billing.apple.bundle_id');
        $forBundle = (string) ($claims['data']['bundleId'] ?? $claims['bundleId'] ?? '');

        if ($bundle !== '' && $forBundle !== '' && ! hash_equals($bundle, $forBundle)) {
            return null;
        }

        return $claims;
    }

    private function decodeSegment(string $segment): array
    {
        $json = base64_decode(strtr($segment, '-_', '+/'), true);

        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function chainTerminatesAtRoot(array $chain, string $root): bool
    {
        $last = $this->pem((string) end($chain));
        $rootPem = str_contains($root, 'BEGIN CERTIFICATE') ? $root : $this->pem($root);

        $given = openssl_x509_read($last);
        $expected = openssl_x509_read($rootPem);

        if ($given === false || $expected === false) {
            return false;
        }

        /* Compared by fingerprint rather than by string: the same certificate
           can be encoded with different line breaks and still be the same
           certificate. */
        return hash_equals(
            (string) openssl_x509_fingerprint($expected, 'sha256'),
            (string) openssl_x509_fingerprint($given, 'sha256'),
        );
    }

    private function signatureHolds(string $leaf, string $signed, string $signature64, string $algorithm): bool
    {
        if ($algorithm !== 'ES256') {
            return false;
        }

        $certificate = openssl_x509_read($this->pem($leaf));

        if ($certificate === false) {
            return false;
        }

        $key = openssl_pkey_get_public($certificate);

        if ($key === false) {
            return false;
        }

        $raw = base64_decode(strtr($signature64, '-_', '+/'), true);

        if ($raw === false || strlen($raw) !== 64) {
            return false;
        }

        return openssl_verify($signed, $this->derSignature($raw), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /** JWS carries r||s; OpenSSL wants the DER sequence. */
    private function derSignature(string $raw): string
    {
        $part = static function (string $half): string {
            $half = ltrim($half, "\x00");

            if ($half === '') {
                $half = "\x00";
            }

            if (ord($half[0]) > 0x7f) {
                $half = "\x00".$half;
            }

            return "\x02".chr(strlen($half)).$half;
        };

        $body = $part(substr($raw, 0, 32)).$part(substr($raw, 32, 32));

        return "\x30".chr(strlen($body)).$body;
    }

    private function pem(string $base64): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(preg_replace('/\s+/', '', $base64) ?? '', 64, "\n")
            ."-----END CERTIFICATE-----\n";
    }
}
