<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends ApiController
{
    public function __construct(private readonly BookingService $bookings) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string'],
            'provider_product_id' => ['required', 'string'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'journey_id' => ['sometimes', 'nullable', 'uuid'],
            'trip_id' => ['sometimes', 'nullable', 'uuid'],
            'travellers' => ['sometimes', 'array'],
            'travellers.*.full_name' => ['required', 'string', 'max:120'],
            'travellers.*.type' => ['sometimes', 'string'],
            'travellers.*.age' => ['sometimes', 'nullable', 'integer'],
            'contact' => ['sometimes', 'array'],
        ]);

        /* The client supplies the idempotency key so a retry is safe. */
        $data['idempotency_key'] = $request->header('Idempotency-Key')
            ?? $request->input('idempotency_key')
            ?? abort(422, 'An Idempotency-Key header is required to create a booking.');

        return response()->json(['data' => $this->bookings->create($this->actor($request), $data)], 201);
    }

    public function show(Request $request, string $booking): JsonResponse
    {
        $model = Booking::with(['items', 'transitions'])
            ->ownedBy($this->actor($request))
            ->findOrFail($booking);

        return response()->json([
            'data' => array_merge($this->bookings->payload($model), [
                'history' => $model->transitions->map(fn ($t) => [
                    'from' => $t->from_state,
                    'to' => $t->to_state,
                    'actor' => $t->actor,
                    'reason' => $t->reason,
                    'at' => $t->occurred_at->toIso8601String(),
                ])->all(),
            ]),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::with('items')->ownedBy($this->actor($request))->latest()->get();

        return response()->json([
            'data' => $bookings->map(fn (Booking $b) => $this->bookings->payload($b))->all(),
        ]);
    }

    public function cancel(Request $request, string $booking): JsonResponse
    {
        $model = Booking::with('items')->ownedBy($this->actor($request))->findOrFail($booking);

        return response()->json([
            'data' => $this->bookings->cancel($this->actor($request), $model, $request->input('reason')),
        ]);
    }
}
