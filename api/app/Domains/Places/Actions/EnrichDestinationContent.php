<?php

declare(strict_types=1);

namespace App\Domains\Places\Actions;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Experiences\Models\Experience;
use App\Domains\ExternalSources\Contracts\PlaceEnricher;
use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\Places\Services\ExperienceDraftFactory;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnrichDestinationContent
{
    public function __construct(
        private readonly PlaceEnricher $enricher,
        private readonly ExperienceDraftFactory $drafts,
    ) {}

    /** @return array{enriched: int, missed: int, failed: int} */
    public function run(Destination $destination, int $limit = 50): array
    {
        $tally = ['enriched' => 0, 'missed' => 0, 'failed' => 0];
        $experiences = Experience::query()
            ->with('place')
            ->where('destination_id', $destination->id)
            ->where('status', 'needs_content')
            ->whereNotNull('place_id')
            ->limit($limit)
            ->get();

        foreach ($experiences as $experience) {
            try {
                $mapping = ExternalEntity::query()
                    ->where('entity_type', 'place')
                    ->where('entity_id', $experience->place_id)
                    ->whereNotNull('metadata')
                    ->latest('last_synced_at')
                    ->first();
                $candidate = $this->candidate($mapping?->metadata, $experience);
                $enrichment = $this->enricher->enrich($candidate);

                if ($enrichment?->summary === null) {
                    $tally['missed']++;

                    continue;
                }

                $updates = [
                    'why_it_matters' => $enrichment->summary,
                    'summary' => $this->drafts->summarise($enrichment->summary),
                    'content_source_name' => $enrichment->sourceName,
                    'content_source_url' => $enrichment->sourceUrl,
                    'status' => 'published',
                ];
                if ($experience->image_url === null && $enrichment->hasImage()) {
                    $updates['image_url'] = $enrichment->imageUrl;
                    $updates['image_attribution'] = $enrichment->imageAttribution();
                }
                $experience->update($updates);
                $tally['enriched']++;
            } catch (Throwable $exception) {
                $tally['failed']++;
                Log::warning('destination enrichment record failed', [
                    'destination_id' => $destination->id,
                    'experience_id' => $experience->id,
                    'error_type' => $exception::class,
                ]);
            }
        }

        return $tally;
    }

    /** @param array<string, mixed>|null $metadata */
    private function candidate(?array $metadata, Experience $experience): PlaceCandidate
    {
        $place = $experience->place;

        return new PlaceCandidate(
            provider: (string) ($metadata['provider'] ?? $place->data_source ?? 'canonical'),
            providerId: (string) ($metadata['provider_id'] ?? $place->id),
            name: (string) ($metadata['name'] ?? $place->name),
            point: new GeoPoint((float) ($metadata['lat'] ?? $place->lat), (float) ($metadata['lng'] ?? $place->lng)),
            address: $metadata['address'] ?? $place->address,
            kind: (string) ($metadata['kind'] ?? $place->kind),
            website: $metadata['website'] ?? $place->website,
            phone: $metadata['phone'] ?? $place->phone,
            externalRefs: (array) ($metadata['external_refs'] ?? []),
            categories: (array) ($metadata['categories'] ?? []),
        );
    }
}
