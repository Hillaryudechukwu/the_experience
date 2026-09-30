<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Services;

use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Bookings\BookingState;
use App\Domains\Bookings\Models\Booking;
use App\Domains\ExternalSources\Contracts\ProviderCapability;
use App\Domains\ExternalSources\DTO\Availability;
use App\Domains\ExternalSources\DTO\BookingRequest;
use App\Domains\ExternalSources\DTO\BookingResult;
use App\Domains\ExternalSources\DTO\DateRange;
use App\Domains\ExternalSources\Models\ProviderProduct;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use App\Domains\Shared\ValueObjects\Actor;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Booking orchestration (spec s9).
 *
 * The booking walks the state machine explicitly and the provider call is
 * wrapped in an idempotency guard, so a retried request never produces a second
 * booking.
 */
class BookingService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly BookingStateMachine $machine,
        private readonly IdempotencyGuard $idempotency,
        private readonly RecordBehaviouralEvent $events,
    ) {}

    public function create(Actor $actor, array $input): array
    {
        $product = ProviderProduct::where('provider', $input['provider'])
            ->where('provider_product_id', $input['provider_product_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $provider = $this->registry->get($product->provider);

        if ($provider === null || ! $provider->isConfigured()) {
            throw new DomainException('That supplier is not available right now.');
        }

        $key = $input['idempotency_key'];
        $quantity = max(1, (int) ($input['quantity'] ?? 1));
        $startsAt = isset($input['starts_at']) ? CarbonImmutable::parse($input['starts_at']) : null;

        $result = $this->idempotency->run(
            'booking.create',
            $key,
            [
                'provider' => $product->provider,
                'product' => $product->provider_product_id,
                'quantity' => $quantity,
                'starts_at' => $startsAt?->toIso8601String(),
                'actor' => $actor->key(),
            ],
            function () use ($actor, $product, $provider, $input, $key, $quantity, $startsAt) {
                $booking = Booking::create(array_merge($actor->ownerAttributes(), [
                    'reference' => 'EXP-' . strtoupper(Str::random(8)),
                    'journey_id' => $input['journey_id'] ?? null,
                    'trip_id' => $input['trip_id'] ?? null,
                    'state' => BookingState::Draft,
                    'provider' => $product->provider,
                    'fulfilment' => in_array(ProviderCapability::NATIVE_BOOKING, $provider->capabilities(), true) ? 'native' : 'redirect',
                    'idempotency_key' => $key,
                    'currency' => $product->currency,
                    'meta' => ['experience_id' => $product->experience_id],
                ]));

                $booking->items()->create([
                    'experience_id' => $product->experience_id,
                    'provider_product_id' => $product->id,
                    'title' => $product->title,
                    'quantity' => $quantity,
                    'unit_price_minor' => $product->price_from_minor,
                    'currency' => $product->currency,
                    'starts_at' => $startsAt,
                    'state' => BookingState::Draft->value,
                ]);

                foreach ($input['travellers'] ?? [] as $traveller) {
                    $booking->travellers()->create([
                        'full_name' => $traveller['full_name'],
                        'type' => $traveller['type'] ?? 'adult',
                        'age' => $traveller['age'] ?? null,
                    ]);
                }

                $this->machine->transition($booking, BookingState::Pricing, 'system', 'Confirming price with supplier');

                /* Only claim availability if the supplier can actually tell us. */
                if (in_array(ProviderCapability::LIVE_AVAILABILITY, $provider->capabilities(), true) && $startsAt !== null) {
                    /*
                     * Through the registry, so a supplier that throws becomes an
                     * unknown answer rather than an exception escaping a booking
                     * that has already been written to the database.
                     */
                    $availability = $this->registry->attempt(
                        $product->provider,
                        fn () => $provider->availability($product->provider_product_id, DateRange::singleDay($startsAt)),
                        Availability::unknown(
                            $product->provider,
                            $product->provider_product_id,
                            'Supplier is temporarily unreachable.',
                        ),
                    );

                    /*
                     * "We could not ask" is not "there is nothing left".
                     *
                     * An unknown availability has no slots, so checking only for
                     * an empty list told a traveller their date was sold out
                     * whenever the supplier was down — a statement about the
                     * product, made from a fact about us, which spec s15.1
                     * forbids precisely because the traveller acts on it and
                     * goes elsewhere.
                     *
                     * It still fails the booking rather than proceeding: the
                     * next transition is AvailabilityConfirmed, and that is a
                     * claim we have not earned. Nothing has been charged at this
                     * point, so the honest answer costs the traveller a retry
                     * rather than money.
                     */
                    if (! $availability->isLive) {
                        $this->machine->transition(
                            $booking,
                            BookingState::Failed,
                            'provider',
                            'Could not reach the supplier to confirm availability, so nothing was booked. '
                                . 'Nothing has been charged — please try again shortly.',
                        );

                        return $this->payload($booking->fresh(['items']));
                    }

                    if ($availability->slots === []) {
                        $this->machine->transition($booking, BookingState::Failed, 'provider', 'No availability for the requested date.');

                        return $this->payload($booking->fresh(['items']));
                    }
                }

                $this->machine->transition($booking, BookingState::AvailabilityConfirmed, 'provider');
                $this->machine->transition($booking, BookingState::AwaitingPayment, 'system');

                /*
                 * A supplier that throws must not abandon the booking.
                 *
                 * The row is written before the supplier is called and nothing
                 * wraps the two in a transaction, so an escaping exception
                 * left the booking sitting in AwaitingPayment — which is not
                 * terminal, so it stayed in the traveller's list as pending
                 * forever with nothing left that would ever move it. A timeout
                 * or a rate limit was enough to do it.
                 *
                 * The state machine records the failure instead, which is both
                 * true and terminal enough to be retried.
                 */
                try {
                    $providerResult = $provider->createBooking(new BookingRequest(
                        providerProductId: $product->provider_product_id,
                        idempotencyKey: $key,
                        quantity: $quantity,
                        startsAt: $startsAt,
                        travellers: $input['travellers'] ?? [],
                        contact: $input['contact'] ?? [],
                    ));
                } catch (Throwable $e) {
                    Log::warning('booking.provider_unreachable', [
                        'booking' => $booking->id,
                        'provider' => $product->provider,
                        'message' => $e->getMessage(),
                    ]);

                    $providerResult = BookingResult::failed(
                        'We could not reach ' . $product->provider . ' to complete this booking. '
                            . 'Nothing has been charged — please try again.',
                    );
                }

                if (! $providerResult->success) {
                    $this->machine->transition($booking, BookingState::Failed, 'provider', $providerResult->failureReason);

                    return $this->payload($booking->fresh(['items']));
                }

                $booking->update([
                    'provider_booking_id' => $providerResult->providerBookingId,
                    'redirect_url' => $providerResult->redirectUrl,
                    'cancellation_policy' => $providerResult->cancellationPolicy,
                    'total_minor' => $providerResult->total?->minor,
                    'currency' => $providerResult->total?->currency ?? $booking->currency,
                    'fulfilment' => $providerResult->fulfilment,
                ]);

                if ($providerResult->fulfilment === 'native') {
                    $this->machine->transition($booking, BookingState::Processing, 'provider');
                    $this->machine->transition($booking, BookingState::Confirmed, 'provider');
                }

                return $this->payload($booking->fresh(['items']));
            },
            /* A booking that failed took no money and reserved nothing, so the
               same key may be used to try again. If it failed because the date
               is genuinely sold out, the supplier simply refuses a second
               time — which costs a request and tells the traveller the truth,
               where replaying a cached failure tells them nothing new. */
            fn (array $response) => ($response['state'] ?? null) === BookingState::Failed->value,
        );

        if (! $result['replayed']) {
            $this->events->record($actor, 'book', [
                'subject_type' => 'experience',
                'subject_id' => $product->experience_id,
                'properties' => ['provider' => $product->provider, 'state' => $result['response']['state']],
            ]);
        }

        return array_merge($result['response'], ['replayed' => $result['replayed']]);
    }

    public function cancel(Actor $actor, Booking $booking, ?string $reason = null): array
    {
        if ($booking->state->isTerminal()) {
            throw new DomainException('This booking can no longer be changed.');
        }

        $provider = $this->registry->get($booking->provider);

        if ($booking->state === BookingState::Confirmed) {
            $this->machine->transition($booking, BookingState::CancelRequested, 'traveller', $reason);
        }

        if ($provider === null
            || ! in_array(ProviderCapability::CANCELLATION, $provider->capabilities(), true)
            || $booking->provider_booking_id === null) {
            return array_merge($this->payload($booking->fresh(['items'])), [
                'cancellable_here' => false,
                'message' => 'This booking was paid for on the supplier\'s own site, so it has to be cancelled there.',
            ]);
        }

        /*
         * Asking has to happen before claiming.
         *
         * A confirmed booking is moved to CancelRequested above, because the
         * state machine offers no other route out of Confirmed. If the
         * supplier call then throws, the booking is left claiming a
         * cancellation is under way when nothing was ever requested — and
         * CancelRequested leads only to Cancelled or Confirmed, neither of
         * which anything would do. The traveller stops watching a reservation
         * that is still live, and turns up to nothing or is charged for a
         * no-show.
         *
         * So the request is rolled back to Confirmed, which is exactly what
         * that transition exists for.
         */
        try {
            $result = $provider->cancelBooking($booking->provider_booking_id);
        } catch (Throwable $e) {
            Log::warning('booking.cancel_unreachable', [
                'booking' => $booking->id,
                'provider' => $booking->provider,
                'message' => $e->getMessage(),
            ]);

            if ($booking->fresh()->state === BookingState::CancelRequested) {
                $this->machine->transition($booking, BookingState::Confirmed, 'system', 'Supplier unreachable');
            }

            return array_merge($this->payload($booking->fresh(['items'])), [
                'cancellable_here' => false,
                'message' => 'We could not reach the supplier, so nothing was cancelled and your booking still stands. '
                    . 'Please try again shortly.',
            ]);
        }

        if (! $result->success) {
            return array_merge($this->payload($booking->fresh(['items'])), [
                'cancellable_here' => false,
                'message' => $result->reason,
            ]);
        }

        $this->machine->transition($booking, BookingState::Cancelled, 'provider', $reason);

        return array_merge($this->payload($booking->fresh(['items'])), [
            'cancellable_here' => true,
            'refund_expected' => $result->refundExpected,
        ]);
    }

    public function payload(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'state' => $booking->state->value,
            'provider' => $booking->provider,
            'fulfilment' => $booking->fulfilment,
            'redirect_url' => $booking->redirect_url,
            'provider_booking_id' => $booking->provider_booking_id,
            'total' => $booking->total()?->toArray(),
            'cancellation_policy' => $booking->cancellation_policy,
            'failure_reason' => $booking->failure_reason,
            'items' => $booking->items->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'experience_id' => $item->experience_id,
                'quantity' => $item->quantity,
                'starts_at' => $item->starts_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
