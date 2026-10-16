<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Notifications\Models\NotificationOutbox;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Trips\Models\Itinerary;
use App\Domains\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Writes one outbox row per real-world event.
 *
 * The key is the event, not the attempt. A booking that confirms twice, or a
 * flush that crashes after the insert, must not produce a second message.
 */
class NotificationEnqueuer
{
    public function bookingConfirmed(Booking $booking): ?NotificationOutbox
    {
        return $this->enqueue(
            new Actor(
                userId: $booking->user_id,
                guestSessionId: $booking->user_id === null ? $booking->guest_session_id : null,
            ),
            'booking_confirmed',
            'booking_confirmed:'.$booking->id,
            'Booking confirmed',
            sprintf('Your booking %s is confirmed.', $booking->reference),
            ['booking_id' => $booking->id, 'reference' => $booking->reference],
        );
    }

    public function destinationImportFinished(DestinationImport $import): ?NotificationOutbox
    {
        $status = $import->destination->coverage_status->value ?? (string) $import->stage->value;
        $name = $import->destination->name;
        $ready = $status === 'ready';

        $actor = $import->requested_by_type === 'user'
            ? new Actor(userId: (int) $import->requested_by_id)
            : new Actor(guestSessionId: $import->requested_by_id);

        return $this->enqueue(
            $actor,
            'destination_import_ready',
            'destination_import_ready:'.$import->id,
            $ready ? $name.' is ready' : 'A few places are ready in '.$name,
            $ready
                ? $name.' has recommendations you can use now.'
                : $name.' has a shorter list than usual. You can still look, or try again later.',
            ['import_id' => $import->id, 'destination_id' => $import->destination_id, 'status' => $status],
        );
    }

    public function replanProposed(Actor $actor, Trip $trip, Itinerary $proposal): ?NotificationOutbox
    {
        $city = $trip->relationLoaded('journey')
            ? $trip->journey?->destination?->name
            : $trip->journey()->with('destination')->first()?->destination?->name;

        return $this->enqueue(
            $actor,
            'replan_proposed',
            'replan_proposed:'.$proposal->id,
            'A change to your plan',
            $city === null
                ? 'There is a suggested change to your plan. Nothing moves until you accept it.'
                : 'There is a suggested change to your '.$city.' plan. Nothing moves until you accept it.',
            ['trip_id' => $trip->id, 'itinerary_id' => $proposal->id],
        );
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(Actor $actor, string $kind, string $key, string $title, string $body, array $payload = []): ?NotificationOutbox
    {
        if (! config('experience.notifications.enabled', true)) {
            return null;
        }

        if ($actor->isAnonymous()) {
            return null;
        }

        $existing = NotificationOutbox::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return NotificationOutbox::query()->create(array_merge($actor->ownerAttributes(), [
                'kind' => $kind,
                'idempotency_key' => $key,
                'title' => $title,
                'body' => $body,
                'payload' => $payload,
                'scheduled_for' => CarbonImmutable::now(),
                'status' => 'scheduled',
            ]));
        } catch (UniqueConstraintViolationException) {
            return NotificationOutbox::query()->where('idempotency_key', $key)->first();
        } catch (\Throwable $e) {
            Log::warning('notification.enqueue_failed', ['kind' => $kind, 'message' => $e->getMessage()]);

            return null;
        }
    }
}
