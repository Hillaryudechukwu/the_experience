<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\AI\Models\AssistantConversation;
use App\Domains\Analytics\Models\BehaviouralEvent;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Journeys\Models\JourneyContextSnapshot;
use App\Domains\Passport\Models\CompletedExperience;
use App\Domains\Passport\Models\SavedExperience;
use App\Domains\TravellerProfile\Services\TravellerProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Location and data controls (spec s23.2, acceptance 65). */
class PrivacyController extends ApiController
{
    public function __construct(private readonly TravellerProfileService $profiles) {}

    public function export(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json([
            'data' => [
                'profile' => $this->profiles->present($this->profiles->forActor($actor)),
                'journeys' => Journey::ownedBy($actor)->get(),
                'saved_experiences' => SavedExperience::ownedBy($actor)->get(),
                'completed_experiences' => CompletedExperience::ownedBy($actor)->get(),
                'behavioural_events' => BehaviouralEvent::ownedBy($actor)->latest('occurred_at')->limit(1000)->get(),
                'context_snapshots' => $this->snapshots($actor),
            ],
        ]);
    }

    /** Delete the stored journey/location context without deleting the account. */
    public function forgetLocationHistory(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $journeyIds = Journey::ownedBy($actor)->pluck('id');

        $deleted = JourneyContextSnapshot::whereIn('journey_id', $journeyIds)->delete();

        BehaviouralEvent::ownedBy($actor)->update(['properties' => []]);

        return response()->json(['deleted_snapshots' => $deleted]);
    }

    public function destroyAccountData(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        AssistantConversation::ownedBy($actor)->delete();
        SavedExperience::ownedBy($actor)->delete();
        CompletedExperience::ownedBy($actor)->delete();
        BehaviouralEvent::ownedBy($actor)->delete();
        Journey::ownedBy($actor)->delete();

        return response()->json(['status' => 'deleted']);
    }

    private function snapshots($actor)
    {
        $journeyIds = Journey::ownedBy($actor)->pluck('id');

        return JourneyContextSnapshot::whereIn('journey_id', $journeyIds)
            ->latest('captured_at')
            ->limit(200)
            ->get();
    }
}
