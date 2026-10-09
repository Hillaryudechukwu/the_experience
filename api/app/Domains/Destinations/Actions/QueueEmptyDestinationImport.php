<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Actions;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Enums\DestinationImportStage;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use App\Domains\Destinations\Jobs\ImportDestination;
use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Destinations\Services\DestinationCoverageStateMachine;
use App\Domains\Experiences\Models\Experience;
use App\Domains\ExternalSources\Contracts\PlaceDataProvider;
use App\Domains\Shared\ValueObjects\Actor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue cities are seeded as "ready" shells. When a traveller asks for one
 * that still has no published experiences, kick the same import pipeline used
 * by destination activation — equivalent to experience:sync-places for that city.
 */
class QueueEmptyDestinationImport
{
    public function __construct(
        private readonly DestinationCoverageStateMachine $coverage,
        private readonly PlaceDataProvider $places,
    ) {}

    /** @return DestinationImport|null null when the city already has published experiences */
    public function handle(Destination $destination, Actor $actor): ?DestinationImport
    {
        if ($this->hasPublishedExperiences($destination->id)) {
            return null;
        }

        return Cache::lock("destination-catalogue-fill:{$destination->id}", 15)->block(5, function () use ($destination, $actor) {
            $destination->refresh();

            if ($this->hasPublishedExperiences($destination->id)) {
                return null;
            }

            $active = $destination->imports()
                ->whereIn('status', [DestinationImportStatus::Queued->value, DestinationImportStatus::Running->value])
                ->latest()
                ->first();

            if ($active !== null) {
                return $active;
            }

            return DB::transaction(function () use ($destination, $actor) {
                $destination = Destination::query()->lockForUpdate()->findOrFail($destination->id);

                if ($this->hasPublishedExperiences($destination->id)) {
                    return null;
                }

                $active = $destination->imports()
                    ->whereIn('status', [DestinationImportStatus::Queued->value, DestinationImportStatus::Running->value])
                    ->latest()
                    ->first();

                if ($active !== null) {
                    return $active;
                }

                if ($destination->coverage_status !== DestinationCoverageStatus::Queued) {
                    $this->coverage->transition($destination, DestinationCoverageStatus::Queued);
                }

                $import = $destination->imports()->create([
                    'active_destination_id' => $destination->id,
                    'requested_by_type' => $actor->isGuest() ? 'guest' : 'user',
                    'requested_by_id' => $actor->isGuest() ? $actor->guestSessionId : (string) $actor->userId,
                    'status' => DestinationImportStatus::Queued,
                    'stage' => DestinationImportStage::Queued,
                    'provider_key' => $this->places->key(),
                    'last_heartbeat_at' => now(),
                ]);

                ImportDestination::dispatch($import->id)->afterCommit();

                return $import;
            });
        });
    }

    private function hasPublishedExperiences(string $destinationId): bool
    {
        return Experience::query()
            ->where('destination_id', $destinationId)
            ->where('status', 'published')
            ->exists();
    }
}
