<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Discovery\Services\CandidateBuilder;
use App\Domains\Discovery\Services\ContextEngine;
use App\Domains\ExternalSources\Services\OfferService;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Experiences\Services\ExperiencePresenter;
use App\Domains\Passport\Models\CompletedExperience;
use App\Domains\Passport\Models\PassportEntry;
use App\Domains\Passport\Models\SavedExperience;
use App\Domains\Recommendations\Scoring\ExperienceScorer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExperienceController extends ApiController
{
    public function __construct(
        private readonly ExperiencePresenter $presenter,
        private readonly ContextEngine $contextEngine,
        private readonly CandidateBuilder $candidates,
        private readonly ExperienceScorer $scorer,
        private readonly OfferService $offers,
        private readonly RecordBehaviouralEvent $events,
    ) {}

    public function show(Request $request, string $experience): JsonResponse
    {
        $model = Experience::with(['place.neighbourhood', 'categories', 'destination'])
            ->where('id', $experience)
            ->orWhere('slug', $experience)
            ->firstOrFail();

        $actor = $this->actor($request);
        $context = $this->contextEngine->build($actor, array_merge($request->all(), ['surface' => 'detail']));
        $scored = $this->scorer->score($this->candidates->toCandidate($model, $context), $context);

        $this->events->record($actor, 'view', [
            'subject_type' => 'experience',
            'subject_id' => $model->id,
            'surface' => 'detail',
        ]);

        $payload = $this->presenter->detail($model, $scored, $context->now);
        $payload['is_saved'] = in_array($model->id, $context->savedExperienceIds, true);
        $payload['is_completed'] = in_array($model->id, $context->completedExperienceIds, true);

        return response()->json(['data' => $payload]);
    }

    public function availability(Request $request, string $experience): JsonResponse
    {
        $model = Experience::findOrFail($experience);

        return response()->json([
            'data' => $this->offers->availabilityFor(
                $model,
                CarbonImmutable::parse($request->input('from', 'now')),
                (int) $request->input('days', 3),
            ),
        ]);
    }

    public function offers(string $experience): JsonResponse
    {
        return response()->json(['data' => $this->offers->offersFor(Experience::findOrFail($experience))]);
    }

    public function save(Request $request, string $experience): JsonResponse
    {
        $model = Experience::findOrFail($experience);
        $actor = $this->actor($request);

        $saved = SavedExperience::updateOrCreate(
            array_merge($actor->ownerAttributes(), ['experience_id' => $model->id]),
            ['note' => $request->input('note'), 'journey_id' => $request->input('journey_id')],
        );

        $this->events->record($actor, 'save', [
            'subject_type' => 'experience',
            'subject_id' => $model->id,
            'recommendation_set_id' => $request->input('recommendation_set_id'),
            'surface' => $request->input('surface'),
        ]);

        return response()->json(['data' => ['id' => $saved->id, 'saved' => true]], 201);
    }

    public function unsave(Request $request, string $experience): JsonResponse
    {
        $actor = $this->actor($request);
        SavedExperience::ownedBy($actor)->where('experience_id', $experience)->delete();
        $this->events->record($actor, 'unsave', ['subject_type' => 'experience', 'subject_id' => $experience]);

        return response()->json(['data' => ['saved' => false]]);
    }

    public function saved(Request $request): JsonResponse
    {
        $saved = SavedExperience::ownedBy($this->actor($request))
            ->with(['experience.place', 'experience.categories'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $saved->filter(fn ($s) => $s->experience !== null)
                ->map(fn ($s) => array_merge($this->presenter->card($s->experience), ['note' => $s->note]))
                ->values()
                ->all(),
        ]);
    }

    public function complete(Request $request, string $experience): JsonResponse
    {
        $model = Experience::with('destination')->findOrFail($experience);
        $actor = $this->actor($request);

        $completed = CompletedExperience::create(array_merge($actor->ownerAttributes(), [
            'experience_id' => $model->id,
            'journey_id' => $request->input('journey_id'),
            'completed_at' => CarbonImmutable::parse($request->input('completed_at', 'now')),
        ]));

        PassportEntry::create(array_merge($actor->ownerAttributes(), [
            'experience_id' => $model->id,
            'destination_id' => $model->destination_id,
            'kind' => 'experience',
            'label' => $model->title,
            'earned_at' => $completed->completed_at,
            'meta' => ['destination' => $model->destination?->name],
        ]));

        $this->events->record($actor, 'complete', [
            'subject_type' => 'experience',
            'subject_id' => $model->id,
        ]);

        return response()->json(['data' => ['completed' => true, 'id' => $completed->id]], 201);
    }
}
