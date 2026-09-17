<?php

declare(strict_types=1);

namespace App\Domains\Passport\Services;

use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Passport\Models\CompletedExperience;
use App\Domains\Passport\Models\PassportEntry;
use App\Domains\Passport\Models\UserExperienceReview;
use App\Domains\Shared\ValueObjects\Actor;
use Carbon\CarbonImmutable;

/** Experience Passport, journal and trip recap (spec s14). */
class PassportService
{
    public function __construct(private readonly RecordBehaviouralEvent $events) {}

    public function summary(Actor $actor): array
    {
        $completed = CompletedExperience::ownedBy($actor)
            ->with(['experience.destination', 'experience.categories'])
            ->orderByDesc('completed_at')
            ->get();

        $destinations = $completed->pluck('experience.destination')->filter()->unique('id');
        $countries = $destinations->pluck('country')->unique();

        $categories = $completed
            ->flatMap(fn ($c) => $c->experience?->categories->pluck('key') ?? collect())
            ->countBy()
            ->sortDesc();

        return [
            'totals' => [
                'experiences' => $completed->count(),
                'cities' => $destinations->count(),
                'countries' => $countries->count(),
            ],
            'cities' => $destinations->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'country' => $d->country,
                'lat' => $d->lat,
                'lng' => $d->lng,
                'experiences' => $completed->filter(fn ($c) => $c->experience?->destination_id === $d->id)->count(),
            ])->values()->all(),
            'categories' => $categories->take(8)->map(fn ($count, $key) => [
                'key' => $key,
                'count' => $count,
            ])->values()->all(),
            'milestones' => $this->milestones($actor, $completed->count(), $destinations->count(), $countries->count()),
            'recent' => $completed->take(12)->map(fn ($c) => [
                'experience_id' => $c->experience_id,
                'title' => $c->experience?->title,
                'image_url' => $c->experience?->image_url,
                'destination' => $c->experience?->destination?->name,
                'completed_at' => $c->completed_at->toIso8601String(),
            ])->all(),
        ];
    }

    public function journal(Actor $actor, string $experienceId, array $data): array
    {
        $review = UserExperienceReview::updateOrCreate(
            array_merge($actor->ownerAttributes(), ['experience_id' => $experienceId]),
            [
                'rating' => $data['rating'],
                'would_recommend' => $data['would_recommend'] ?? null,
                'best_part' => $data['best_part'] ?? null,
                'private_note' => $data['private_note'] ?? null,
                'photos' => $data['photos'] ?? [],
                /* Journal entries are private unless the traveller says otherwise. */
                'is_public' => $data['is_public'] ?? false,
            ],
        );

        $this->events->record($actor, 'rate', [
            'subject_type' => 'experience',
            'subject_id' => $experienceId,
            'properties' => ['rating' => $data['rating']],
        ]);

        return [
            'id' => $review->id,
            'rating' => $review->rating,
            'is_public' => $review->is_public,
            'has_private_note' => $review->private_note !== null,
        ];
    }

    /** Spec s14.4 — the trip recap. */
    public function recap(Actor $actor, string $journeyId): array
    {
        $journey = Journey::with('destination')->ownedBy($actor)->findOrFail($journeyId);

        $completed = CompletedExperience::ownedBy($actor)
            ->where('journey_id', $journey->id)
            ->with(['experience.categories'])
            ->get();

        $categories = $completed->flatMap(fn ($c) => $c->experience?->categories->pluck('key') ?? collect())->countBy();
        $reviews = UserExperienceReview::ownedBy($actor)
            ->whereIn('experience_id', $completed->pluck('experience_id'))
            ->get();

        $days = $journey->starts_on && $journey->ends_on
            ? (int) $journey->starts_on->diffInDays($journey->ends_on) + 1
            : null;

        return [
            'destination' => $journey->destination->name,
            'days' => $days,
            'experiences' => $completed->count(),
            'iconic' => $categories['iconic'] ?? 0,
            'hidden_gems' => $categories['hidden_gem'] ?? 0,
            'food_experiences' => $categories['food_experience'] ?? 0,
            'average_rating' => $reviews->isEmpty() ? null : round($reviews->avg('rating'), 1),
            'highlights' => $reviews->sortByDesc('rating')->take(3)->map(fn ($r) => [
                'experience_id' => $r->experience_id,
                'rating' => $r->rating,
                'best_part' => $r->best_part,
            ])->values()->all(),
        ];
    }

    private function milestones(Actor $actor, int $experiences, int $cities, int $countries): array
    {
        $earned = [];

        foreach ([
            ['key' => 'first_experience', 'label' => 'First experience', 'reached' => $experiences >= 1],
            ['key' => 'ten_experiences', 'label' => '10 experiences', 'reached' => $experiences >= 10],
            ['key' => 'three_cities', 'label' => '3 cities', 'reached' => $cities >= 3],
            ['key' => 'three_countries', 'label' => '3 countries', 'reached' => $countries >= 3],
        ] as $milestone) {
            if ($milestone['reached']) {
                PassportEntry::firstOrCreate(
                    array_merge($actor->ownerAttributes(), ['kind' => 'milestone', 'label' => $milestone['label']]),
                    ['earned_at' => CarbonImmutable::now()],
                );
            }
            $earned[] = $milestone;
        }

        return $earned;
    }
}
