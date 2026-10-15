<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\DTO\VerifiedIdentity;
use App\Domains\Identity\Exceptions\IdentityTokenRejected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies an RS256 ID token against a provider's published JWKS.
 *
 * The token is checked and discarded. Nothing here stores it.
 */
abstract class JwksIdTokenVerifier
{
    abstract public function provider(): string;

    abstract protected function jwksUrl(): string;

    /** @return list<string> */
    abstract protected function issuers(): array;

    /** @return list<string> */
    abstract protected function audiences(): array;

    public function verify(string $idToken): VerifiedIdentity
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new IdentityTokenRejected('malformed');
        }

        $header = json_decode($this->b64($parts[0]), true);
        $claims = json_decode($this->b64($parts[1]), true);
        if (! is_array($header) || ! is_array($claims)) {
            throw new IdentityTokenRejected('malformed');
        }

        $subHash = isset($claims['sub']) ? hash('sha256', (string) $claims['sub']) : null;

        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new IdentityTokenRejected('algorithm', $subHash);
        }

        $pem = $this->pemForKid($header['kid']);
        $signature = $this->b64($parts[2]);
        $verified = openssl_verify($parts[0].'.'.$parts[1], $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new IdentityTokenRejected('signature', $subHash);
        }

        if (! in_array($claims['iss'] ?? null, $this->issuers(), true)) {
            throw new IdentityTokenRejected('issuer', $subHash);
        }

        $audiences = $claims['aud'] ?? null;
        $audiences = is_array($audiences) ? $audiences : [$audiences];
        $allowed = $this->audiences();
        if ($allowed === [] || array_intersect($audiences, $allowed) === []) {
            throw new IdentityTokenRejected('audience', $subHash);
        }

        $now = time();
        $exp = (int) ($claims['exp'] ?? 0);
        if ($exp < ($now - 60)) {
            throw new IdentityTokenRejected('expired', $subHash);
        }
        if (isset($claims['nbf']) && (int) $claims['nbf'] > ($now + 60)) {
            throw new IdentityTokenRejected('not_yet_valid', $subHash);
        }

        $subject = trim((string) ($claims['sub'] ?? ''));
        if ($subject === '') {
            throw new IdentityTokenRejected('subject', $subHash);
        }

        $email = isset($claims['email']) ? strtolower(trim((string) $claims['email'])) : null;
        if ($email === '') {
            $email = null;
        }

        $name = isset($claims['name']) && is_string($claims['name']) && trim($claims['name']) !== ''
            ? trim($claims['name'])
            : null;

        return new VerifiedIdentity(
            provider: $this->provider(),
            subject: $subject,
            email: $email,
            emailVerified: $this->isTrue($claims['email_verified'] ?? false),
            name: $name,
        );
    }

    private function pemForKid(string $kid): string
    {
        $keys = $this->keys();
        foreach ($keys as $key) {
            if (($key['kid'] ?? null) !== $kid || ($key['kty'] ?? null) !== 'RSA') {
                continue;
            }
            if (! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
                continue;
            }

            return $this->pem($key['n'], $key['e']);
        }

        throw new IdentityTokenRejected('unknown_key');
    }

    /** @return list<array<string, mixed>> */
    private function keys(): array
    {
        $cacheKey = 'identity.jwks.'.$this->provider();
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $response = Http::timeout(8)->acceptJson()->get($this->jwksUrl());
        if ($response->failed()) {
            throw new IdentityTokenRejected('jwks_unavailable');
        }

        $keys = $response->json('keys');
        if (! is_array($keys)) {
            throw new IdentityTokenRejected('jwks_unavailable');
        }

        $ttl = 3600;
        if (preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $match) === 1) {
            $ttl = max(3600, min(86400, (int) $match[1]));
        }

        Cache::put($cacheKey, $keys, $ttl);

        return $keys;
    }

    private function pem(string $modulus, string $exponent): string
    {
        $modulus = $this->unsigned($this->b64($modulus));
        $exponent = $this->unsigned($this->b64($exponent));
        $rsa = $this->der(0x30, $this->der(0x02, $modulus).$this->der(0x02, $exponent));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $body = $this->der(0x30, $algorithm.$this->der(0x03, "\x00".$rsa));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($body), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function unsigned(string $value): string
    {
        if ($value !== '' && (ord($value[0]) > 0x7F)) {
            return "\x00".$value;
        }

        return $value;
    }

    private function der(int $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 128) {
            return chr($tag).chr($length).$value;
        }

        $encoded = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(0x80 | strlen($encoded)).$encoded.$value;
    }

    private function b64(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }

        $raw = base64_decode($value, true);
        if ($raw === false) {
            throw new IdentityTokenRejected('malformed');
        }

        return $raw;
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
