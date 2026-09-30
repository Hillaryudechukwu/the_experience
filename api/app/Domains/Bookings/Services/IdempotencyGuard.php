<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Services;

use App\Domains\Bookings\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Exactly-once semantics for provider and payment calls (spec s9.4, s23.1).
 *
 * The same key with the same request body replays the stored response; the same
 * key with a different body is a client bug and is rejected loudly.
 */
class IdempotencyGuard
{
    /**
     * @param  Closure|null  $isRetryable  given the response, may this key be used again?
     */
    public function run(
        string $scope,
        string $key,
        array $request,
        Closure $operation,
        ?Closure $isRetryable = null,
    ): array {
        $hash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));

        $existing = IdempotencyKey::where('scope', $scope)->where('key', $key)->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $hash) {
                throw new RuntimeException("Idempotency key '{$key}' was reused with a different request body.");
            }

            if ($existing->status === 'completed') {
                return ['replayed' => true, 'response' => $existing->response];
            }

            if ($existing->status === 'in_progress') {
                throw new RuntimeException("Idempotency key '{$key}' is already in progress.");
            }
        }

        /*
         * A previous attempt that failed is exactly what a retry is for.
         *
         * Only 'completed' and 'in_progress' were handled, so a 'failed' key
         * fell through to the insert below, collided with the unique index on
         * (scope, key), and was reported as already in progress — permanently.
         * A traveller whose booking failed because a supplier timed out could
         * never try that booking again, which is the opposite of what
         * idempotency is meant to buy them.
         *
         * Retrying is safe because the same key is handed to the supplier as
         * their idempotency header: if the failure happened after they had
         * already accepted it, they return the original rather than booking it
         * twice.
         */
        if ($existing !== null && $existing->status === 'failed') {
            $existing->update([
                'status' => 'in_progress',
                'locked_at' => CarbonImmutable::now(),
                'response' => null,
            ]);

            $record = $existing;
        } else {
            try {
                $record = IdempotencyKey::create([
                    'scope' => $scope,
                    'key' => $key,
                    'request_hash' => $hash,
                    'status' => 'in_progress',
                    'locked_at' => CarbonImmutable::now(),
                ]);
            } catch (QueryException) {
                // Lost the race with a concurrent identical request.
                throw new RuntimeException("Idempotency key '{$key}' is already in progress.");
            }
        }

        try {
            $response = $operation();
        } catch (\Throwable $e) {
            $record->update(['status' => 'failed', 'response' => ['error' => $e->getMessage()]]);
            throw $e;
        }

        /*
         * An outcome that failed is not one worth replaying.
         *
         * Without this, a booking that failed because a supplier was
         * unreachable was stored as a completed result and handed straight
         * back on every retry — so the traveller's second attempt returned the
         * first attempt's failure without anyone being asked again. Marking it
         * failed lets the same key be used to try once more, which is what a
         * traveller pressing the button again means by it.
         */
        $retryable = $isRetryable !== null && $isRetryable($response);

        $record->update([
            'status' => $retryable ? 'failed' : 'completed',
            'response' => $response,
        ]);

        return ['replayed' => false, 'response' => $response];
    }
}
