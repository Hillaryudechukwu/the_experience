<?php

declare(strict_types=1);

namespace App\Domains\Places\Services;

use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\DTO\PlaceEnrichment;

/**
 * Turns an ingested place into a first-draft experience.
 *
 * The values here are derived from what the place *is*, not invented about what
 * it is like. Duration, indoor/outdoor exposure and interest affinity follow
 * from the kind of place; prominence follows from whether the wider web
 * considers it notable (a Wikidata entity, review volume).
 *
 * These are explicitly starting points. Anything marked derived should be
 * reviewed before it is treated as editorial, which is why a draft without a
 * description is created as `needs_content` and never surfaces in discovery.
 */
class ExperienceDraftFactory
{
    /** kind => [min, expected, max] minutes */
    private const DURATION = [
        'museum' => [60, 120, 240],
        'gallery' => [45, 90, 180],
        'historic' => [40, 75, 150],
        'memorial' => [5, 15, 30],
        'attraction' => [40, 75, 150],
        'viewpoint' => [20, 40, 75],
        'artwork' => [10, 20, 40],
        'park' => [40, 75, 180],
        'market' => [30, 60, 120],
        'worship' => [20, 40, 90],
        'theatre' => [90, 150, 210],
        'zoo' => [90, 180, 300],
        'theme_park' => [180, 300, 480],
        'nightlife' => [60, 120, 210],
        'food' => [45, 75, 120],
    ];

    private const EXPOSURE = [
        'park' => 'outdoor',
        'viewpoint' => 'outdoor',
        'artwork' => 'outdoor',
        'market' => 'mixed',
        'historic' => 'mixed',
        'memorial' => 'outdoor',
        'attraction' => 'mixed',
        'zoo' => 'outdoor',
        'theme_park' => 'outdoor',
        'museum' => 'indoor',
        'gallery' => 'indoor',
        'theatre' => 'indoor',
        'worship' => 'indoor',
        'nightlife' => 'indoor',
        'food' => 'indoor',
    ];

    private const AFFINITY = [
        'museum' => ['history' => 85, 'culture' => 90, 'art' => 60],
        'gallery' => ['art' => 92, 'culture' => 85],
        'historic' => ['history' => 92, 'architecture' => 75, 'culture' => 65],
        'memorial' => ['history' => 55, 'art' => 40, 'photography' => 35],
        'attraction' => ['culture' => 70, 'photography' => 60],
        'viewpoint' => ['photography' => 92, 'architecture' => 60],
        'artwork' => ['art' => 80, 'photography' => 70, 'local_life' => 55],
        'park' => ['nature' => 90, 'wellness' => 70, 'family' => 65],
        'market' => ['food' => 88, 'local_life' => 85, 'shopping' => 60],
        'worship' => ['architecture' => 85, 'history' => 80, 'culture' => 75],
        'theatre' => ['culture' => 88, 'music' => 60, 'art' => 60],
        'zoo' => ['family' => 90, 'nature' => 75],
        'theme_park' => ['family' => 92, 'adventure' => 80],
        'nightlife' => ['nightlife' => 90, 'social' => 75],
        'food' => ['food' => 92, 'local_life' => 60],
    ];

    /** Kinds that are normally free to enter. */
    private const USUALLY_FREE = ['park', 'viewpoint', 'artwork', 'market', 'worship', 'memorial'];

    /**
     * Ceiling on derived prominence, by kind.
     *
     * Without this, a bronze statue with a Wikipedia article scores the same
     * "notable" bonus as a national museum and, being four minutes closer,
     * outranks it. Notability on the open web says a thing is documented; it
     * says nothing about whether it is worth an afternoon. Curated experiences
     * carry an editorial prominence and are not capped.
     */
    private const PROMINENCE_CEILING = [
        'memorial' => 32,
        'artwork' => 35,
        'worship' => 62,
        'viewpoint' => 62,
        'park' => 68,
        'food' => 55,
        'nightlife' => 55,
        'gallery' => 78,
        'market' => 72,
        'theatre' => 72,
        'historic' => 85,
        'museum' => 88,
        'attraction' => 80,
    ];

    public function build(PlaceCandidate $candidate, ?PlaceEnrichment $enrichment, string $destinationCurrency): array
    {
        $kind = $candidate->kind;
        [$min, $expected, $max] = self::DURATION[$kind] ?? [40, 75, 150];

        $notable = isset($candidate->externalRefs['wikidata']) || isset($candidate->externalRefs['wikipedia']);
        $reviewWeight = min(1.0, log10(max(1, $candidate->ratingCount ?? 0) + 1) / log10(20000));

        $summary = $enrichment?->summary;

        return [
            'title' => $candidate->name,
            'summary' => $summary !== null ? $this->firstSentence($summary) : $candidate->name,
            'why_it_matters' => $summary ?? '',
            'min_duration_minutes' => $min,
            'expected_duration_minutes' => $expected,
            'max_duration_minutes' => $max,
            'is_free' => in_array($kind, self::USUALLY_FREE, true) || in_array('free', $candidate->categories, true),
            'currency' => $destinationCurrency,
            'weather_exposure' => self::EXPOSURE[$kind] ?? 'mixed',
            'energy_level' => in_array($kind, ['park', 'zoo', 'theme_park'], true) ? 'high' : ($kind === 'viewpoint' ? 'low' : 'medium'),
            /* Derived prominence: notability on the open web plus review volume.
               A starting point for ranking, not an editorial judgement. */
            'iconic_weight' => min(
                self::PROMINENCE_CEILING[$kind] ?? 70,
                (int) round(30 + ($notable ? 30 : 0) + ($reviewWeight * 40)),
            ),
            'uniqueness' => min(
                self::PROMINENCE_CEILING[$kind] ?? 70,
                (int) round(45 + ($notable ? 20 : 0)),
            ),
            'tourist_concentration' => (int) round(30 + ($reviewWeight * 50)),
            'value_signal' => 60,
            'queue_risk' => (int) round(20 + ($reviewWeight * 45)),
            'child_friendly_score' => in_array($kind, ['zoo', 'theme_park', 'park'], true) ? 88 : 55,
            'romance_score' => in_array($kind, ['viewpoint', 'park'], true) ? 78 : 50,
            'social_score' => in_array($kind, ['market', 'nightlife', 'food'], true) ? 82 : 50,
            'interest_affinity' => self::AFFINITY[$kind] ?? ['culture' => 60],
            'mood_affinity' => [],
            'accessibility' => $candidate->accessibility,
            'best_time_of_day' => $kind === 'viewpoint' ? ['golden_hour', 'evening'] : [],
            'know_before_you_go' => $this->knowBeforeYouGo($candidate),
            'image_url' => $enrichment?->hasImage() ? $enrichment->imageUrl : null,
            'image_attribution' => $enrichment?->imageAttribution(),
            'content_source_name' => $enrichment?->sourceName,
            'content_source_url' => $enrichment?->sourceUrl,
            'status' => $summary !== null ? 'published' : 'needs_content',
        ];
    }

    /** Public so ingestion can promote a draft once a description arrives. */
    public function summarise(string $text): string
    {
        return $this->firstSentence($text);
    }

    private function firstSentence(string $text): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
        $summary = $sentences[0] ?? $text;

        return mb_strlen($summary) > 240 ? mb_substr($summary, 0, 237) . '…' : $summary;
    }

    /** @return list<string> */
    private function knowBeforeYouGo(PlaceCandidate $candidate): array
    {
        $notes = [];

        if ($candidate->openingHours === null) {
            $notes[] = 'Opening hours are not verified for this place — check before travelling.';
        }

        $wheelchair = $candidate->accessibility['wheelchair_accessible'] ?? null;

        if ($wheelchair === false) {
            $notes[] = 'Mapped as not step-free.';
        } elseif ($wheelchair === 'limited') {
            $notes[] = 'Mapped as having limited step-free access.';
        }

        if ($candidate->website !== null) {
            $notes[] = 'Official site: ' . $candidate->website;
        }

        return $notes;
    }
}
