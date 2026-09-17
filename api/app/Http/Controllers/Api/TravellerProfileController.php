<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\TravellerProfile\Services\TravellerProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TravellerProfileController extends ApiController
{
    public function __construct(private readonly TravellerProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->profiles->present($this->profiles->forActor($this->actor($request))),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'travel_pace' => ['sometimes', Rule::in(['slow', 'moderate', 'fast'])],
            'walking_tolerance' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'budget_level' => ['sometimes', Rule::in(['budget', 'moderate', 'premium', 'luxury'])],
            'daily_experience_budget_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'iconic_vs_local' => ['sometimes', 'integer', 'between:0,100'],
            'food_adventurousness' => ['sometimes', 'integer', 'between:0,100'],
            'prefers_private_tours' => ['sometimes', 'boolean'],
            'accessibility' => ['sometimes', 'array'],
            'languages' => ['sometimes', 'array'],
            'interests' => ['sometimes', 'array'],
            'interests.*' => ['integer', 'between:0,100'],
        ]);

        $profile = $this->profiles->update($this->actor($request), $data);

        return response()->json(['data' => $this->profiles->present($profile)]);
    }

    /** Experience DNA (spec s2.3) — what the system has learned, in plain language. */
    public function dna(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->profiles->experienceDna($this->profiles->forActor($this->actor($request))),
        ]);
    }
}
