<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\AI\Models\AssistantConversation;
use App\Domains\AI\Services\AssistantOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends ApiController
{
    public function __construct(private readonly AssistantOrchestrator $orchestrator) {}

    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'conversation_id' => ['sometimes', 'nullable', 'uuid'],
            'lat' => ['sometimes', 'nullable', 'numeric'],
            'lng' => ['sometimes', 'nullable', 'numeric'],
            'destination' => ['sometimes', 'nullable', 'string'],
            'journey_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $context = array_filter([
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'destination' => $data['destination'] ?? null,
            'journey_id' => $data['journey_id'] ?? null,
        ], fn ($v) => $v !== null);

        return response()->json([
            'data' => $this->orchestrator->handle(
                $this->actor($request),
                $data['message'],
                $context,
                $data['conversation_id'] ?? null,
            ),
        ]);
    }

    public function history(Request $request, string $conversation): JsonResponse
    {
        $model = AssistantConversation::with('messages')
            ->ownedBy($this->actor($request))
            ->findOrFail($conversation);

        return response()->json([
            'data' => [
                'id' => $model->id,
                'title' => $model->title,
                'messages' => $model->messages->map(fn ($m) => [
                    'id' => $m->id,
                    'role' => $m->role,
                    'content' => $m->content,
                    'suggestions' => $m->suggestions,
                    'grounded' => $m->grounding['grounded'] ?? null,
                    'created_at' => $m->created_at->toIso8601String(),
                ])->all(),
            ],
        ]);
    }
}
