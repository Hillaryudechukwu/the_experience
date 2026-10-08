<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Services;

use App\Domains\Destinations\Models\Destination;
use Illuminate\Support\Collection;

class DestinationQualityReporter
{
    /** @return Collection<int, array<string, mixed>> */
    public function report(): Collection
    {
        $minimum = max(1, (int) config('experience.destination_activation.ready_minimum_published', 5));

        return Destination::query()
            ->withCount([
                'experiences',
                'experiences as published_count' => fn ($query) => $query->where('status', 'published'),
                'experiences as pending_content_count' => fn ($query) => $query->where('status', 'needs_content'),
                'experiences as sourced_count' => fn ($query) => $query->whereNotNull('content_source_url'),
                'experiences as imaged_count' => fn ($query) => $query->whereNotNull('image_url'),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Destination $destination) use ($minimum) {
                $publishedCoverage = min(1, $destination->published_count / $minimum);
                $contentTotal = max(1, $destination->experiences_count);
                $sourceCoverage = $destination->sourced_count / $contentTotal;
                $imageCoverage = $destination->imaged_count / $contentTotal;
                $score = (int) round(100 * (($publishedCoverage * 0.6) + ($sourceCoverage * 0.25) + ($imageCoverage * 0.15)));
                $issues = [];

                if ($destination->published_count < $minimum) {
                    $issues[] = 'insufficient_published_experiences';
                }
                if ($destination->pending_content_count > 0) {
                    $issues[] = 'content_enrichment_backlog';
                }
                if ($sourceCoverage < 0.5) {
                    $issues[] = 'low_source_coverage';
                }
                if ($imageCoverage < 0.5) {
                    $issues[] = 'low_image_coverage';
                }

                return [
                    'destination' => $destination->only(['id', 'name', 'slug', 'country']),
                    'coverage_status' => $destination->coverage_status->value,
                    'quality_score' => $score,
                    'counts' => [
                        'experiences' => $destination->experiences_count,
                        'published' => $destination->published_count,
                        'pending_content' => $destination->pending_content_count,
                        'sourced' => $destination->sourced_count,
                        'imaged' => $destination->imaged_count,
                    ],
                    'issues' => $issues,
                    'needs_curation' => $issues !== [],
                ];
            });
    }
}
