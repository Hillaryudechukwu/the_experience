<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Notifications\Models\DevicePushToken;
use App\Domains\Notifications\Models\NotificationOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $rows = NotificationOutbox::query()
            ->ownedBy($this->actor($request))
            ->latest('created_at')
            ->limit(40)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (NotificationOutbox $row) => $this->present($row))->all(),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $row = NotificationOutbox::query()
            ->ownedBy($this->actor($request))
            ->findOrFail($notification);

        if ($row->read_at === null) {
            $row->read_at = CarbonImmutable::now();
            $row->save();
        }

        return response()->json(['data' => $this->present($row)]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = NotificationOutbox::query()
            ->ownedBy($this->actor($request))
            ->whereNull('read_at')
            ->update(['read_at' => CarbonImmutable::now()]);

        return response()->json(['data' => ['updated' => $updated]]);
    }

    public function storePushToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        $actor = $this->actor($request);

        if ($actor->isAnonymous()) {
            return response()->json(['message' => 'A session is required before a device can be registered.'], 422);
        }

        $token = DevicePushToken::query()->updateOrCreate(
            ['token' => $data['token']],
            array_merge($actor->ownerAttributes(), [
                'platform' => $data['platform'] ?? null,
            ]),
        );

        return response()->json(['data' => ['id' => $token->id]], 201);
    }

    /** @return array<string, mixed> */
    private function present(NotificationOutbox $row): array
    {
        $payload = $row->payload ?? [];

        return [
            'id' => $row->id,
            'kind' => $row->kind,
            'title' => $row->title,
            'body' => $row->body,
            'status' => $row->status,
            'payload' => [
                'booking_id' => $payload['booking_id'] ?? null,
                'trip_id' => $payload['trip_id'] ?? null,
                'destination_id' => $payload['destination_id'] ?? null,
                'import_id' => $payload['import_id'] ?? null,
                'itinerary_id' => $payload['itinerary_id'] ?? null,
                'experience_id' => $payload['experience_id'] ?? null,
                'reference' => $payload['reference'] ?? null,
                'path' => $this->pathFor($row->kind),
            ],
            'read_at' => $row->read_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function pathFor(string $kind): string
    {
        return match (true) {
            str_starts_with($kind, 'booking_') => '/(tabs)/bookings',
            str_starts_with($kind, 'destination_import_') => '/(tabs)/index',
            str_starts_with($kind, 'replan_') => '/(tabs)/trip',
            default => '/account/notifications',
        };
    }
}
