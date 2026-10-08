<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Services;

use App\Domains\Destinations\ValueObjects\DestinationCandidate;
use App\Domains\Shared\ValueObjects\Actor;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\RateLimiter;

class DestinationActivationGate
{
    /** @return list<string> */
    public function keys(Actor $actor, string $ip): array
    {
        return [
            'destination-activation:actor:'.$actor->key(),
            'destination-activation:ip:'.hash('sha256', $ip),
            'destination-activation:global',
        ];
    }

    public function assertAllowed(Actor $actor, string $ip, DestinationCandidate $candidate): void
    {
        $this->assertRollout($actor, $candidate);

        $limits = [
            (int) config('experience.destination_activation.daily_actor_limit', 5),
            (int) config('experience.destination_activation.daily_ip_limit', 10),
            (int) config('experience.destination_activation.daily_global_limit', 100),
        ];

        foreach (array_map(null, $this->keys($actor, $ip), $limits) as [$key, $limit]) {
            if ($limit > 0 && RateLimiter::tooManyAttempts($key, $limit)) {
                $retryAfter = RateLimiter::availableIn($key);

                throw new HttpResponseException(response()->json([
                    'message' => 'Destination preparation has reached its current limit. Please try again later.',
                    'retry_after_seconds' => $retryAfter,
                ], 429, ['Retry-After' => (string) $retryAfter]));
            }
        }
    }

    public function record(Actor $actor, string $ip): void
    {
        foreach ($this->keys($actor, $ip) as $key) {
            RateLimiter::hit($key, now()->endOfDay()->diffInSeconds(now(), true) + 1);
        }
    }

    private function assertRollout(Actor $actor, DestinationCandidate $candidate): void
    {
        $allowed = collect(config('experience.destination_activation.allowed_cities', []))
            ->map(fn (string $city) => mb_strtolower($city))
            ->contains(mb_strtolower($candidate->name));
        $allowedCandidate = collect(config('experience.destination_activation.allowed_candidates', []))
            ->contains($candidate->provider.':'.$candidate->externalId);

        $percentage = max(0, min(100, (int) config('experience.destination_activation.rollout_percentage', 100)));
        $bucket = hexdec(substr(hash('sha256', $actor->key()), 0, 8)) % 100;

        if (! $allowed && ! $allowedCandidate && $bucket >= $percentage) {
            throw new HttpResponseException(response()->json([
                'message' => 'Destination preparation is not available for this account yet.',
                'code' => 'destination_activation_not_in_rollout',
            ], 403));
        }
    }
}
