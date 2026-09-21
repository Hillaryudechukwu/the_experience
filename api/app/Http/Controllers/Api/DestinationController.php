<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DestinationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Destination::query();

        if ($term = $request->query('q')) {
            $query->whereRaw('lower(name) like ?', ['%' . mb_strtolower($term) . '%']);
        }

        $destinations = $query->orderBy('name')->limit(40)->get();

        /* When the traveller shares a location, put the nearest city first. */
        if ($request->filled(['lat', 'lng'])) {
            $point = new GeoPoint((float) $request->query('lat'), (float) $request->query('lng'));
            $destinations = $destinations->sortBy(fn (Destination $d) => $point->distanceTo($d->point()))->values();
        }

        return response()->json([
            'data' => $destinations->map(fn (Destination $d) => [
                'id' => $d->id,
                'slug' => $d->slug,
                'name' => $d->name,
                'country' => $d->country,
                'timezone' => $d->timezone,
                'currency' => $d->currency,
                'lat' => $d->lat,
                'lng' => $d->lng,
                'hero_image_url' => $d->hero_image_url,
                'summary' => $d->summary,
            ])->all(),
        ]);
    }

    public function show(string $destination): JsonResponse
    {
        /* PostgreSQL refuses to compare a uuid column to an arbitrary string, so
           the id branch is only taken when the value actually looks like one.
           Without this, every lookup by slug raised a 22P02 and the whole
           endpoint 500'd. */
        $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $destination);

        $model = Destination::with(['neighbourhoods', 'essentials', 'signatureItems'])
            ->when($isUuid, fn ($q) => $q->where('id', $destination), fn ($q) => $q->where('slug', $destination))
            ->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $model->id,
                'slug' => $model->slug,
                'name' => $model->name,
                'country' => $model->country,
                'timezone' => $model->timezone,
                'currency' => $model->currency,
                'languages' => $model->languages,
                'lat' => $model->lat,
                'lng' => $model->lng,
                'summary' => $model->summary,
                'hero_image_url' => $model->hero_image_url,
                'neighbourhoods' => $model->neighbourhoods->map(fn ($n) => [
                    'id' => $n->id,
                    'slug' => $n->slug,
                    'name' => $n->name,
                    'character' => $n->character,
                    'best_for' => $n->best_for,
                    'ideal_duration_minutes' => $n->ideal_duration_minutes,
                    'lat' => $n->lat,
                    'lng' => $n->lng,
                    'image_url' => $n->image_url,
                    'image_attribution' => $n->image_attribution,
                ])->all(),
                /* Spec s5.3 — the culturally meaningful checklist. */
                'dont_leave_without' => $model->signatureItems->map(fn ($i) => [
                    'title' => $i->title,
                    'description' => $i->description,
                    'kind' => $i->kind,
                    'experience_id' => $i->experience_id,
                ])->all(),
                /* Spec s12.1 — sourced and timestamped, never invented. */
                'city_essentials' => $model->essentials->map(fn ($e) => [
                    'category' => $e->category,
                    'title' => $e->title,
                    'body' => $e->body,
                    'source' => ['name' => $e->source_name, 'url' => $e->source_url],
                    'verified_at' => $e->verified_at->toIso8601String(),
                ])->all(),
            ],
        ]);
    }
}
