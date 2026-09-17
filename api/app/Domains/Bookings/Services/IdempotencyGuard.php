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
    public function run(string $scope, string $key, array $request, Closure $operation): array
    {
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

        try {
            $response = $operation();
        } catch (\Throwable $e) {
            $record->update(['status' => 'failed', 'response' => ['error' => $e->getMessage()]]);
            throw $e;
        }

        $record->update(['status' => 'completed', 'response' => $response]);

        return ['replayed' => false, 'response' => $response];
    }
}
