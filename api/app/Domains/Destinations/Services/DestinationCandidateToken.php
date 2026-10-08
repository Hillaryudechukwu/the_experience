<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Services;

use App\Domains\Destinations\ValueObjects\DestinationCandidate;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class DestinationCandidateToken
{
    public function issue(DestinationCandidate $candidate): string
    {
        return Crypt::encryptString((string) json_encode([
            'version' => 1,
            'candidate' => $candidate->toArray(),
            'issued_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function verify(string $token): DestinationCandidate
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['candidate_token' => 'This destination selection is invalid. Search again.']);
        }

        if (($payload['version'] ?? null) !== 1 || (int) ($payload['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['candidate_token' => 'This destination selection has expired. Search again.']);
        }

        $candidateData = $payload['candidate'] ?? null;
        $required = ['provider', 'external_id', 'name', 'country', 'country_code', 'lat', 'lng', 'kind'];

        if (! is_array($candidateData) || array_diff($required, array_keys($candidateData)) !== []) {
            throw ValidationException::withMessages(['candidate_token' => 'This destination selection is invalid. Search again.']);
        }

        $candidate = DestinationCandidate::fromArray($candidateData);

        if ($candidate->lat < -90 || $candidate->lat > 90 || $candidate->lng < -180 || $candidate->lng > 180) {
            throw ValidationException::withMessages(['candidate_token' => 'This destination has invalid coordinates.']);
        }

        return $candidate;
    }
}
