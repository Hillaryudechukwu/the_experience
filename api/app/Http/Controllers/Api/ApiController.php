<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Shared\ValueObjects\Actor;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class ApiController extends Controller
{
    protected function actor(Request $request): Actor
    {
        return $request->attributes->get('actor') ?? new Actor;
    }

    /**
     * Reject a date range longer than the planner will build.
     *
     * @throws ValidationException
     */
    protected function assertPlannableSpan(DateTimeInterface|string|null $startsOn, DateTimeInterface|string|null $endsOn, string $field): void
    {
        if ($startsOn === null || $endsOn === null) {
            return;
        }

        $maxDays = (int) config('experience.itinerary.max_planned_days');
        $days = (int) CarbonImmutable::parse($startsOn)->startOfDay()->diffInDays(CarbonImmutable::parse($endsOn)->startOfDay()) + 1;

        if ($days > $maxDays) {
            throw ValidationException::withMessages([
                $field => "We can plan up to {$maxDays} days at a time.",
            ]);
        }
    }
}
