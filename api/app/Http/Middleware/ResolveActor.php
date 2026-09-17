<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Models\GuestSession;
use App\Domains\Shared\ValueObjects\Actor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guest-first identity (spec s17.4).
 *
 * Browsing, saving and planning all work without an account. A guest presents
 * an X-Guest-Token; if they do not have one yet we mint it and hand it back on
 * the response, so the very first request already works.
 */
class ResolveActor
{
    public function handle(Request $request, Closure $next): Response
    {
        /* Resolve through the sanctum guard explicitly: most routes here are open
           to guests, so the bearer token is never validated by an auth middleware. */
        $user = $request->user() ?? Auth::guard('sanctum')->user();

        if ($user !== null) {
            $request->setUserResolver(fn () => $user);
        }
        $guest = null;

        if ($user === null) {
            $token = $request->header('X-Guest-Token');
            $guest = $token ? GuestSession::where('token', $token)->first() : null;

            if ($guest === null) {
                $guest = GuestSession::create([
                    'token' => Str::random(48),
                    'platform' => $request->header('X-Client-Platform'),
                    'locale' => $request->getPreferredLanguage(),
                    'last_seen_at' => CarbonImmutable::now(),
                ]);
            } else {
                $guest->forceFill(['last_seen_at' => CarbonImmutable::now()])->saveQuietly();
            }
        }

        $actor = new Actor($user?->id, $guest?->id);
        app()->instance(Actor::class, $actor);
        $request->attributes->set('actor', $actor);

        $response = $next($request);

        if ($guest !== null) {
            $response->headers->set('X-Guest-Token', $guest->token);
        }

        return $response;
    }
}
