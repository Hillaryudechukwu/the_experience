<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Outbound HTTP with a conscience.
 *
 * Several of the sources this product depends on are free, community-run
 * infrastructure — Overpass, Nominatim, the public OSRM instance. Their usage
 * policies ask for an identifying User-Agent and restrained request rates, and
 * honouring that is a condition of using them at all, not an optimisation.
 *
 * Every provider therefore goes through here, which sets the agent string and
 * enforces a per-provider rate limit before the call is made.
 */
class OutboundHttp
{
    public function __construct(private readonly HttpFactory $http) {}

    /*
     * Accept defaults to "any" rather than "application/json" on purpose.
     * Overpass answers [out:json] queries with a text/html content type and
     * rejects a strict JSON Accept header outright, so insisting on it would
     * break a source that otherwise works perfectly well.
     */
    public function for(string $provider, int $timeout = 20, string $accept = '*/*'): PendingRequest
    {
        $this->throttle($provider);

        return $this->http
            ->withHeaders(array_filter([
                /* Keep the agent string bare. The public Overpass front end
                   rejects any User-Agent containing parentheses with a 406,
                   so the conventional "App/1.0 (+url)" form is unusable here.
                   Contact details go in From, which is the header actually
                   meant for them and which every one of these services reads. */
                'User-Agent' => (string) config('experience.http.user_agent'),
                'From' => config('experience.http.contact_email'),
                'Accept' => $accept,
            ]))
            ->timeout($timeout)
            ->retry(3, 800, throw: false);
    }

    /** Blocks the call rather than queueing it: a refused sync beats a ban. */
    private function throttle(string $provider): void
    {
        $perMinute = (int) config("experience.http.rate_limits.{$provider}", config('experience.http.rate_limits.default', 60));

        if ($perMinute <= 0) {
            return;
        }

        $allowed = RateLimiter::attempt("outbound:{$provider}", $perMinute, fn () => true, 60);

        if ($allowed === false) {
            throw new RuntimeException(
                "Rate limit for '{$provider}' reached ({$perMinute}/min). Backing off rather than pushing through.",
            );
        }
    }
}
