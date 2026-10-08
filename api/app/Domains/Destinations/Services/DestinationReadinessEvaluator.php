<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Services;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Models\Destination;
use App\Domains\Experiences\Models\Experience;

class DestinationReadinessEvaluator
{
    /** @return array{status: DestinationCoverageStatus, published: int, pending_content: int, distinct_kinds: int} */
    public function evaluate(Destination $destination): array
    {
        $publishedQuery = Experience::query()
            ->where('experiences.destination_id', $destination->id)
            ->where('experiences.status', 'published')
            ->whereNotNull('experiences.place_id')
            ->whereNotNull('experiences.data_source')
            ->where('experiences.why_it_matters', '!=', '');

        $published = (clone $publishedQuery)->count();
        $distinctKinds = (clone $publishedQuery)
            ->join('places', 'places.id', '=', 'experiences.place_id')
            ->distinct('places.kind')
            ->count('places.kind');
        $pendingContent = Experience::query()
            ->where('destination_id', $destination->id)
            ->where('status', 'needs_content')
            ->count();

        $ready = $published >= (int) config('experience.destination_activation.ready_minimum_published', 5)
            && $distinctKinds >= (int) config('experience.destination_activation.ready_minimum_kinds', 3);

        return [
            'status' => $ready
                ? DestinationCoverageStatus::Ready
                : ($published > 0 ? DestinationCoverageStatus::Limited : DestinationCoverageStatus::Failed),
            'published' => $published,
            'pending_content' => $pendingContent,
            'distinct_kinds' => $distinctKinds,
        ];
    }
}
