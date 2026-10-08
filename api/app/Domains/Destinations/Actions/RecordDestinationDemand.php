<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Actions;

use App\Domains\Destinations\Models\DestinationCandidateDemand;
use App\Domains\Destinations\ValueObjects\DestinationCandidate;
use App\Domains\Shared\ValueObjects\Actor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RecordDestinationDemand
{
    public function record(DestinationCandidate $candidate, Actor $actor): void
    {
        $identity = hash('sha256', $candidate->provider.'|'.$candidate->externalId.'|'.$actor->key().'|'.today()->toDateString());

        if (! Cache::add('destination-demand:'.$identity, true, now()->endOfDay())) {
            return;
        }

        DB::transaction(function () use ($candidate) {
            $demand = DestinationCandidateDemand::query()
                ->where('provider', $candidate->provider)
                ->where('external_id', $candidate->externalId)
                ->lockForUpdate()
                ->first();

            if ($demand === null) {
                DestinationCandidateDemand::create([
                    'provider' => $candidate->provider,
                    'external_id' => $candidate->externalId,
                    'name' => $candidate->name,
                    'region' => $candidate->region,
                    'country' => $candidate->country,
                    'country_code' => $candidate->countryCode,
                    'lat' => $candidate->lat,
                    'lng' => $candidate->lng,
                    'kind' => $candidate->kind,
                    'search_count' => 1,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]);

                return;
            }

            $demand->update([
                'search_count' => $demand->search_count + 1,
                'last_seen_at' => now(),
            ]);
        });
    }
}
