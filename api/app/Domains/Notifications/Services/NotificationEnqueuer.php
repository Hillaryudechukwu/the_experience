<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Notifications\Models\NotificationOutbox;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Trips\Models\Itinerary;
use App\Domains\Trips\Models\ItineraryItem;
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
        $booking->loadMissing('items');

        $row = $this->enqueue(
            $this->actorForBooking($booking),
            'booking_confirmed',
            'booking_confirmed:'.$booking->id,
            'Booking confirmed',
            sprintf('Your booking %s is confirmed.', $booking->reference),
            $this->bookingPayload($booking),
        );

        $this->scheduleBookingReminders($booking);

        return $row;
    }

    public function bookingFailed(Booking $booking): ?NotificationOutbox
    {
        $reason = $booking->failure_reason;
        $body = $reason === null || $reason === ''
            ? sprintf('Your booking %s could not be completed.', $booking->reference)
            : sprintf('Your booking %s could not be completed: %s', $booking->reference, $reason);

        $this->cancelBookingReminders($booking);

        return $this->enqueue(
            $this->actorForBooking($booking),
            'booking_failed',
            'booking_failed:'.$booking->id,
            'Booking did not complete',
            $body,
            $this->bookingPayload($booking),
        );
    }

    public function bookingCancelled(Booking $booking): ?NotificationOutbox
    {
        $this->cancelBookingReminders($booking);

        return $this->enqueue(
            $this->actorForBooking($booking),
            'booking_cancelled',
            'booking_cancelled:'.$booking->id,
            'Booking cancelled',
            sprintf('Your booking %s has been cancelled.', $booking->reference),
            $this->bookingPayload($booking),
        );
    }

    /**
     * A partial refund can be followed by the full one, so each gets its own
     * kind and key; sharing one key would swallow the second notice.
     */
    public function bookingRefunded(Booking $booking, bool $partial = false): ?NotificationOutbox
    {
        $this->cancelBookingReminders($booking);

        $kind = $partial ? 'booking_partially_refunded' : 'booking_refunded';

        return $this->enqueue(
            $this->actorForBooking($booking),
            $kind,
            $kind.':'.$booking->id,
            $partial ? 'Partial refund processed' : 'Refund processed',
            $partial
                ? sprintf('A partial refund for booking %s is on its way.', $booking->reference)
                : sprintf('A refund for booking %s is on its way.', $booking->reference),
            $this->bookingPayload($booking),
        );
    }

    /**
     * Reminders are scheduled at confirmation. Once the booking is off, any
     * that have not gone out yet must not fire.
     */
    private function cancelBookingReminders(Booking $booking): int
    {
        return NotificationOutbox::query()
            ->where('kind', 'booking_reminder')
            ->where('idempotency_key', 'like', 'booking_reminder:'.$booking->id.':%')
            ->where('status', 'scheduled')
            ->update(['status' => 'cancelled']);
    }

    public function destinationImportFinished(DestinationImport $import): ?NotificationOutbox
    {
        $actor = $this->actorForImport($import);
        if ($actor === null) {
            return null;
        }

        $status = $import->destination->coverage_status->value ?? (string) $import->stage->value;
        $name = $import->destination->name;
        $ready = $status === 'ready';

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

    public function destinationImportFailed(DestinationImport $import): ?NotificationOutbox
    {
        $actor = $this->actorForImport($import);
        if ($actor === null) {
            return null;
        }

        $name = $import->destination?->name ?? 'That city';

        return $this->enqueue(
            $actor,
            'destination_import_failed',
            'destination_import_failed:'.$import->id,
            $name.' is not ready yet',
            $name.' could not finish preparing. You can try again from Discover.',
            [
                'import_id' => $import->id,
                'destination_id' => $import->destination_id,
                'retryable' => (bool) $import->retryable,
            ],
        );
    }

    public function replanProposed(Actor $actor, Trip $trip, Itinerary $proposal, ?string $idempotencyKey = null): ?NotificationOutbox
    {
        $city = $trip->relationLoaded('journey')
            ? $trip->journey?->destination?->name
            : $trip->journey()->with('destination')->first()?->destination?->name;

        return $this->enqueue(
            $actor,
            'replan_proposed',
            $idempotencyKey ?? 'replan_proposed:'.$proposal->id,
            'A change to your plan',
            $city === null
                ? 'There is a suggested change to your plan. Nothing moves until you accept it.'
                : 'There is a suggested change to your '.$city.' plan. Nothing moves until you accept it.',
            ['trip_id' => $trip->id, 'itinerary_id' => $proposal->id],
        );
    }

    /**
     * One nudge on the day of a saved date night: mid-afternoon, or two hours
     * before the first stop if the evening starts early. An evening saved too
     * late for that needs no reminder; it was planned moments ago.
     *
     * @param  array<string, ItineraryItem>  $stops  keyed by role
     */
    public function dateNightPlanned(Actor $actor, Itinerary $itinerary, array $stops, string $timezone): ?NotificationOutbox
    {
        /* The earliest stop, not the first one handed over. */
        $first = collect($stops)->sortBy(fn (ItineraryItem $item) => $item->starts_at->getTimestamp())->first();
        if (! $first instanceof ItineraryItem) {
            return null;
        }

        $startsAt = $first->starts_at->setTimezone($timezone);
        $afternoon = $startsAt->setTimeFromTimeString((string) config('experience.date_night.reminder_at', '15:00'));
        $twoHoursBefore = $startsAt->subHours(2);
        $remindAt = $afternoon->lessThan($twoHoursBefore) ? $afternoon : $twoHoursBefore;

        if (! $remindAt->greaterThan(CarbonImmutable::now())) {
            return null;
        }

        $dinner = $stops['dinner'] ?? null;
        $body = $dinner === null || $dinner === $first
            ? sprintf('Your evening starts at %s with %s.', $startsAt->format('H:i'), $first->title)
            : sprintf(
                'Your evening starts at %s with %s, then dinner at %s at %s.',
                $startsAt->format('H:i'),
                $first->title,
                $dinner->title,
                $dinner->starts_at->setTimezone($timezone)->format('H:i'),
            );

        return $this->enqueue(
            $actor,
            'date_night_reminder',
            'date_night_reminder:'.$first->id,
            'Date night tonight',
            $body,
            [
                'trip_id' => $itinerary->trip_id,
                'itinerary_id' => $itinerary->id,
                'starts_at' => $startsAt->toIso8601String(),
                'item_ids' => array_values(array_map(fn (ItineraryItem $item) => $item->id, $stops)),
            ],
            $remindAt,
        );
    }

    /**
     * Taking one stop out of a date night still leaves an evening to remind
     * about. Taking the last one out leaves nothing, so the reminder goes.
     */
    public function withdrawDateNightReminder(ItineraryItem $removed): int
    {
        $withdrawn = 0;

        $rows = NotificationOutbox::query()
            ->where('kind', 'date_night_reminder')
            ->where('status', 'scheduled')
            ->whereJsonContains('payload->item_ids', $removed->id)
            ->get();

        foreach ($rows as $row) {
            $remaining = ItineraryItem::query()
                ->whereIn('id', $row->payload['item_ids'] ?? [])
                ->whereKeyNot($removed->id)
                ->exists();

            if (! $remaining) {
                $withdrawn += $row->update(['status' => 'cancelled']) ? 1 : 0;
            }
        }

        return $withdrawn;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        Actor $actor,
        string $kind,
        string $key,
        string $title,
        string $body,
        array $payload = [],
        ?CarbonImmutable $scheduledFor = null,
    ): ?NotificationOutbox {
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
                'scheduled_for' => $scheduledFor ?? CarbonImmutable::now(),
                'status' => 'scheduled',
            ]));
        } catch (UniqueConstraintViolationException) {
            return NotificationOutbox::query()->where('idempotency_key', $key)->first();
        } catch (\Throwable $e) {
            Log::warning('notification.enqueue_failed', ['kind' => $kind, 'message' => $e->getMessage()]);

            return null;
        }
    }

    private function scheduleBookingReminders(Booking $booking): void
    {
        $startsAt = $booking->items
            ->pluck('starts_at')
            ->filter()
            ->sort()
            ->first();

        if (! $startsAt instanceof CarbonImmutable) {
            return;
        }

        $actor = $this->actorForBooking($booking);
        $payload = $this->bookingPayload($booking);
        $payload['starts_at'] = $startsAt->toIso8601String();
        $now = CarbonImmutable::now();

        $dayBefore = $startsAt->subDay();
        if ($dayBefore->greaterThan($now)) {
            $this->enqueue(
                $actor,
                'booking_reminder',
                'booking_reminder:'.$booking->id.':t1d',
                'Tomorrow: '.$this->bookingTitle($booking),
                sprintf('Your booking %s starts tomorrow.', $booking->reference),
                $payload,
                $dayBefore,
            );
        }

        $twoHoursBefore = $startsAt->subHours(2);
        if ($twoHoursBefore->greaterThan($now)) {
            $this->enqueue(
                $actor,
                'booking_reminder',
                'booking_reminder:'.$booking->id.':t2h',
                'Starting soon: '.$this->bookingTitle($booking),
                sprintf('Your booking %s starts in about two hours.', $booking->reference),
                $payload,
                $twoHoursBefore,
            );
        }
    }

    private function actorForBooking(Booking $booking): Actor
    {
        return new Actor(
            userId: $booking->user_id,
            guestSessionId: $booking->user_id === null ? $booking->guest_session_id : null,
        );
    }

    private function actorForImport(DestinationImport $import): ?Actor
    {
        if ($import->requested_by_id === null || $import->requested_by_type === null) {
            return null;
        }

        return $import->requested_by_type === 'user'
            ? new Actor(userId: (int) $import->requested_by_id)
            : new Actor(guestSessionId: (string) $import->requested_by_id);
    }

    /** @return array<string, mixed> */
    private function bookingPayload(Booking $booking): array
    {
        $booking->loadMissing('items');

        $item = $booking->items->sortBy('starts_at')->first();

        return array_filter([
            'booking_id' => $booking->id,
            'reference' => $booking->reference,
            'trip_id' => $booking->trip_id,
            'journey_id' => $booking->journey_id,
            'experience_id' => $item?->experience_id,
            'starts_at' => $item?->starts_at?->toIso8601String(),
            'title' => $item?->title,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function bookingTitle(Booking $booking): string
    {
        $booking->loadMissing('items');

        return (string) ($booking->items->first()?->title ?? $booking->reference);
    }
}
