<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Services;

use App\Domains\Analytics\Actions\RecordBehaviouralEvent;
use App\Domains\Bookings\BookingState;
use App\Domains\Bookings\Models\Booking;
use App\Domains\ExternalSources\Contracts\ProviderCapability;
use App\Domains\ExternalSources\DTO\BookingRequest;
use App\Domains\ExternalSources\DTO\DateRange;
use App\Domains\ExternalSources\Models\ProviderProduct;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use App\Domains\Shared\ValueObjects\Actor;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Str;

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
                    $availability = $provider->availability($product->provider_product_id, DateRange::singleDay($startsAt));

                    if ($availability->slots === []) {
                        $this->machine->transition($booking, BookingState::Failed, 'provider', 'No availability for the requested date.');

                        return $this->payload($booking->fresh(['items']));
                    }
                }

                $this->machine->transition($booking, BookingState::AvailabilityConfirmed, 'provider');
                $this->machine->transition($booking, BookingState::AwaitingPayment, 'system');

                $providerResult = $provider->createBooking(new BookingRequest(
                    providerProductId: $product->provider_product_id,
                    idempotencyKey: $key,
                    quantity: $quantity,
                    startsAt: $startsAt,
                    travellers: $input['travellers'] ?? [],
                    contact: $input['contact'] ?? [],
                ));

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

        $result = $provider->cancelBooking($booking->provider_booking_id);

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
