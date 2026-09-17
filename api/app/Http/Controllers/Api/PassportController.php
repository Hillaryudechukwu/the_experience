<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Passport\Services\PassportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PassportController extends ApiController
{
    public function __construct(private readonly PassportService $passport) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->passport->summary($this->actor($request))]);
    }

    public function journal(Request $request, string $experience): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'would_recommend' => ['sometimes', 'boolean'],
            'best_part' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'private_note' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'photos' => ['sometimes', 'array'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        return response()->json([
            'data' => $this->passport->journal($this->actor($request), $experience, $data),
        ], 201);
    }

    public function recap(Request $request, string $journey): JsonResponse
    {
        return response()->json(['data' => $this->passport->recap($this->actor($request), $journey)]);
    }
}
