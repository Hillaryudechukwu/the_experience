<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Actions;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Enums\DestinationImportStage;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use App\Domains\Destinations\Jobs\ImportDestination;
use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Destinations\Services\DestinationCandidateToken;
use App\Domains\Destinations\Services\DestinationCoverageStateMachine;
use App\Domains\Destinations\Services\DestinationMetadataResolver;
use App\Domains\Experiences\Models\Experience;
use App\Domains\ExternalSources\Contracts\PlaceDataProvider;
use App\Domains\Shared\ValueObjects\Actor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivateDestination
{
    public function __construct(
        private readonly DestinationCandidateToken $tokens,
        private readonly DestinationMetadataResolver $metadata,
        private readonly DestinationCoverageStateMachine $coverage,
        private readonly PlaceDataProvider $places,
    ) {}

    /** @return array{destination: Destination, import: ?DestinationImport, created: bool, import_created: bool} */
    public function handle(string $candidateToken, Actor $actor): array
    {
        $candidate = $this->tokens->verify($candidateToken);
        $identity = hash('sha256', $candidate->provider.'|'.$candidate->externalId);

        return Cache::lock("destination-activation:{$identity}", 15)->block(5, function () use ($candidate, $actor) {
            return DB::transaction(function () use ($candidate, $actor) {
                $destination = Destination::query()
                    ->where('discovery_source', $candidate->provider)
                    ->where('discovery_external_id', $candidate->externalId)
                    ->lockForUpdate()
                    ->first();

                if ($destination === null) {
                    $destination = Destination::query()
                        ->whereRaw('lower(name) = ?', [mb_strtolower($candidate->name)])
                        ->where('country_code', $candidate->countryCode)
                        ->lockForUpdate()
                        ->first();
                }

                $created = $destination === null;

                if ($destination === null) {
                    $destination = Destination::create([
                        'slug' => $this->uniqueSlug($candidate->name, $candidate->countryCode),
                        'name' => $candidate->name,
                        'country' => $candidate->country,
                        'country_code' => $candidate->countryCode,
                        'region' => $candidate->region,
                        'timezone' => $this->metadata->timezone($candidate->lat, $candidate->lng),
                        'lat' => $candidate->lat,
                        'lng' => $candidate->lng,
                        'currency' => $this->metadata->currency($candidate->countryCode),
                        'languages' => ['en'],
                        'default_radius_m' => 10000,
                        'coverage_status' => DestinationCoverageStatus::Discovered,
                        'discovery_source' => $candidate->provider,
                        'discovery_external_id' => $candidate->externalId,
                        'activated_at' => now(),
                    ]);
                }

                /* Usable with content → done. Usable but empty → fall through and
                   queue the same catalogue fill as discovery (Ready → Queued). */
                if ($destination->coverage_status->isUsable() && $this->hasPublishedExperiences($destination->id)) {
                    return ['destination' => $destination, 'import' => null, 'created' => $created, 'import_created' => false];
                }

                $activeImport = $destination->imports()
                    ->whereIn('status', [DestinationImportStatus::Queued->value, DestinationImportStatus::Running->value])
                    ->latest()
                    ->first();

                if ($activeImport !== null) {
                    return ['destination' => $destination, 'import' => $activeImport, 'created' => $created, 'import_created' => false];
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

                return ['destination' => $destination->fresh(), 'import' => $import, 'created' => $created, 'import_created' => true];
            });
        });
    }

    private function uniqueSlug(string $name, string $countryCode): string
    {
        $base = Str::slug($name.'-'.$countryCode);
        $slug = $base;
        $suffix = 2;

        while (Destination::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function hasPublishedExperiences(string $destinationId): bool
    {
        return Experience::query()
            ->where('destination_id', $destinationId)
            ->where('status', 'published')
            ->exists();
    }
}
