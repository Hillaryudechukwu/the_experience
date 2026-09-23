<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\AI\Models\AssistantConversation;
use App\Domains\Analytics\Models\BehaviouralEvent;
use App\Domains\Bookings\BookingState;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingTraveller;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Journeys\Models\JourneyContextSnapshot;
use App\Domains\Passport\Models\CompletedExperience;
use App\Domains\Passport\Models\SavedExperience;
use App\Domains\Recommendations\Models\RecommendationSet;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\TravellerProfile\Models\TravellerProfile;
use App\Domains\Trips\Models\Itinerary;
use App\Domains\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Erases a traveller and everything that describes them.
 *
 * This is not the same thing as `PrivacyController::destroyAccountData`, which
 * clears a traveller's content but leaves the traveller. Apple requires an app
 * that offers account creation to offer account deletion — the record and the
 * credentials, not merely the material attached to them — so this removes the
 * user row and every token issued to it as well.
 *
 * There is one deliberate exception, and it is the interesting part.
 *
 * A booking that reached a financial state is not only our record: it is a
 * transaction with a supplier, and a counterparty, a tax authority or a
 * chargeback three months from now all have a legitimate claim on knowing it
 * happened. Deleting it outright would be losing someone else's record as well
 * as our own. So those rows are severed from the person instead — ownership
 * nulled, the travellers' names on the ticket deleted, the amount and the
 * reference kept. Nothing left on them says who booked it.
 *
 * Bookings that never got that far — a draft, a failed attempt, something
 * cancelled before payment — carry no such obligation and are deleted.
 *
 * The distinction has to be stated plainly in the app before the traveller
 * confirms, and in the privacy policy. "We deleted everything" is not true,
 * and a deletion flow that overstates itself is worse than one that explains.
 */
class DeleteTravellerAccount
{
    /**
     * Booking states that represent money having changed hands, or a supplier
     * holding a reservation in the traveller's name.
     */
    private const FINANCIAL_STATES = [
        BookingState::Processing,
        BookingState::Confirmed,
        BookingState::PartiallyConfirmed,
        BookingState::CancelRequested,
        BookingState::Cancelled,
        BookingState::Refunded,
        BookingState::PartiallyRefunded,
    ];

    /**
     * @return array{deleted: array<string, int>, retained_bookings: int}
     */
    public function run(Actor $actor): array
    {
        return DB::transaction(function () use ($actor) {
            $journeyIds = Journey::ownedBy($actor)->pluck('id');
            $tripIds = Trip::ownedBy($actor)->pluck('id');
            $profileIds = TravellerProfile::ownedBy($actor)->pluck('id');

            $retained = $this->severFinancialBookings($actor);

            $deleted = [
                /* Anything the traveller said to the guide, in their own words. */
                'assistant_conversations' => AssistantConversation::ownedBy($actor)->delete(),

                'saved_experiences' => SavedExperience::ownedBy($actor)->delete(),
                'completed_experiences' => CompletedExperience::ownedBy($actor)->delete(),
                'behavioural_events' => $this->deleteEvents($actor),

                /* Where they were and when, which is the most sensitive thing
                   the product holds. */
                'context_snapshots' => JourneyContextSnapshot::whereIn('journey_id', $journeyIds)->delete(),

                'recommendation_sets' => RecommendationSet::query()
                    ->whereIn('journey_id', $journeyIds)
                    ->orWhereIn('traveller_profile_id', $profileIds)
                    ->delete(),

                'bookings' => $this->deleteUnbilledBookings($actor),
                'itineraries' => Itinerary::whereIn('trip_id', $tripIds)->delete(),
                'trips' => Trip::ownedBy($actor)->delete(),
                'journeys' => Journey::ownedBy($actor)->delete(),
                'traveller_profiles' => TravellerProfile::ownedBy($actor)->delete(),
                'guest_sessions' => $this->deleteGuestSessions($actor),
                'users' => $this->deleteUser($actor),
            ];

            return ['deleted' => $deleted, 'retained_bookings' => $retained];
        });
    }

    /**
     * Strips the person off bookings we are obliged to keep.
     *
     * Done before the journeys and trips go, because the booking keeps its own
     * columns pointing at them and a dangling id is worse than a null one.
     */
    private function severFinancialBookings(Actor $actor): int
    {
        $bookings = Booking::ownedBy($actor)
            ->whereIn('state', array_map(fn (BookingState $s) => $s->value, self::FINANCIAL_STATES))
            ->get();

        if ($bookings->isEmpty()) {
            return 0;
        }

        BookingTraveller::whereIn('booking_id', $bookings->pluck('id'))->delete();

        Booking::whereIn('id', $bookings->pluck('id'))->update([
            'user_id' => null,
            'guest_session_id' => null,
            'journey_id' => null,
            'trip_id' => null,
            /* The provider's own reference stays — it is how a supplier
               identifies the reservation — but nothing of ours points back at
               a person. */
            'meta' => json_encode(['anonymised_at' => now()->toIso8601String()]),
        ]);

        return $bookings->count();
    }

    private function deleteUnbilledBookings(Actor $actor): int
    {
        $ids = Booking::ownedBy($actor)->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        BookingTraveller::whereIn('booking_id', $ids)->delete();

        return Booking::whereIn('id', $ids)->delete();
    }

    /**
     * Behavioural events are what the ranking learns from, and they are keyed
     * to the traveller, so they go with them rather than being anonymised.
     */
    private function deleteEvents(Actor $actor): int
    {
        return BehaviouralEvent::ownedBy($actor)->delete();
    }

    /**
     * Both the session acting right now and any earlier guest session that was
     * promoted into this account — those still carry the device's history.
     */
    private function deleteGuestSessions(Actor $actor): int
    {
        $query = GuestSession::query();

        if ($actor->userId !== null) {
            $query->where('promoted_user_id', $actor->userId);
        } elseif ($actor->guestSessionId !== null) {
            $query->where('id', $actor->guestSessionId);
        } else {
            return 0;
        }

        return $query->delete();
    }

    /** Every token first: a deleted user with a live token is a loose end. */
    private function deleteUser(Actor $actor): int
    {
        if ($actor->userId === null) {
            return 0;
        }

        $user = User::find($actor->userId);

        if ($user === null) {
            return 0;
        }

        $user->tokens()->delete();

        return (int) $user->delete();
    }
}
