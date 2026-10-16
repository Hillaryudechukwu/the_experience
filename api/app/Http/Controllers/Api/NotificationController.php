<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Notifications\Models\DevicePushToken;
use App\Domains\Notifications\Models\NotificationOutbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $rows = NotificationOutbox::query()
            ->ownedBy($this->actor($request))
            ->latest('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (NotificationOutbox $row) => [
                'id' => $row->id,
                'kind' => $row->kind,
                'title' => $row->title,
                'body' => $row->body,
                'status' => $row->status,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->all(),
        ]);
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
}
