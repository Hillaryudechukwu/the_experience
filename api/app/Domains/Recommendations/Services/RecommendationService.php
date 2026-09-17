<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\Services;

use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Discovery\Services\CandidateBuilder;
use App\Domains\Discovery\Services\ContextEngine;
use App\Domains\ExternalSources\Services\OfferService;
use App\Domains\Recommendations\DTO\ScoredExperience;
use App\Domains\Recommendations\DTO\ScoringContext;
use App\Domains\Recommendations\Models\Recommendation;
use App\Domains\Recommendations\Models\RecommendationSet;
use App\Domains\Recommendations\Scoring\ExperienceScorer;
use App\Domains\Shared\ValueObjects\Actor;
use Illuminate\Support\Facades\DB;

/**
 * Produces a ranked, explained, persisted set of recommendations.
 *
 * Everything the traveller is shown is written down with the reasons that
 * produced it, so ranking can be debugged and measured later (spec s24, s32).
 */
class RecommendationService
{
    public function __construct(
        private readonly ContextEngine $contextEngine,
        private readonly CandidateBuilder $candidates,
        private readonly ExperienceScorer $scorer,
        private readonly OfferService $offers,
        private readonly RecordBehaviouralEvent $events,
    ) {}

    /**
     * @return array{set: RecommendationSet, context: ScoringContext, results: list<ScoredExperience>}
     */
    public function recommend(Actor $actor, array $input, int $limit = 5): array
    {
        $started = microtime(true);

        $context = $this->contextEngine->build($actor, $input);
        $candidates = $this->candidates->build($context, $input);

        /* An honest empty answer is better than a bad one, but a tight radius is
           usually an assumption rather than a requirement — widen once and say so. */
        $relaxed = [];
        if ($candidates === [] && ! empty($input['radius_metres']) && $input['radius_metres'] < 15000) {
            $candidates = $this->candidates->build($context, array_merge($input, ['radius_metres' => 15000]));
            if ($candidates !== []) {
                $relaxed[] = 'distance';
            }
        }

        /* Category preferences are softer than price or time: if they are what
           made the answer empty, drop them and say so rather than returning
           nothing at all. */
        if ($candidates === [] && ! empty($input['categories'])) {
            $candidates = $this->candidates->build($context, array_diff_key($input, array_flip(['categories', 'radius_metres'])));
            if ($candidates !== []) {
                $relaxed[] = 'categories';
            }
        }

        /* Ask suppliers about today's inventory for a shortlist only — a live
           call per candidate would be both slow and rude to the provider. */
        $shortlist = array_slice($candidates, 0, 40);
        $flags = $this->offers->liveAvailabilityFlags(
            array_map(fn ($c) => $c->experience->id, $shortlist),
            $context->now,
        );

        foreach ($shortlist as $candidate) {
            if ($flags[$candidate->experience->id] ?? false) {
                $candidate->hasLiveAvailability = true;
            }
        }

        $ranked = $this->scorer->rank($candidates, $context);
        $selected = array_slice($ranked, 0, $limit);

        $snapshot = $this->contextEngine->snapshot($context);

        $set = DB::transaction(function () use ($snapshot, $context, $input, $candidates, $selected, $started) {
            $set = RecommendationSet::create([
                'journey_context_snapshot_id' => $snapshot->id,
                'journey_id' => $context->journey?->id,
                'traveller_profile_id' => $context->profile?->id,
                'surface' => $context->surface,
                'request' => array_intersect_key($input, array_flip([
                    'surface', 'window_minutes', 'mood', 'query', 'categories', 'destination', 'free_only',
                ])),
                'candidates_considered' => count($candidates),
                'generation_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            foreach ($selected as $rank => $scored) {
                $recommendation = Recommendation::create([
                    'recommendation_set_id' => $set->id,
                    'experience_id' => $scored->candidate->experience->id,
                    'rank' => $rank + 1,
                    'score' => $scored->score,
                    'is_sponsored' => false,
                ]);

                foreach ($scored->components as $component) {
                    foreach ($component->reasons as $reason) {
                        $recommendation->reasons()->create([
                            'component' => $component->component,
                            'direction' => $reason->direction,
                            'contribution' => $scored->contributions[$component->component] ?? 0,
                            'message' => $reason->message,
                        ]);
                    }
                }
            }

            return $set;
        });

        foreach ($selected as $scored) {
            $this->events->record($actor, 'impression', [
                'subject_type' => 'experience',
                'subject_id' => $scored->candidate->experience->id,
                'recommendation_set_id' => $set->id,
                'surface' => $context->surface,
                'properties' => ['score' => $scored->score],
            ]);
        }

        return [
            'set' => $set,
            'context' => $context,
            'results' => $selected,
            'relaxed' => $relaxed,
        ];
    }
}
