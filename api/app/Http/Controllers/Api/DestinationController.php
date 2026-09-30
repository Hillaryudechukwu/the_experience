<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Destinations\Models\Destination;
use App\Domains\ExternalSources\Providers\NominatimGeocoder;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class DestinationController extends ApiController
{
    /**
     * Place types worth offering as a destination.
     *
     * A traveller types where they are going, and that is a settlement or a
     * country — never a building, a road or a shop, all of which Nominatim
     * will return for a short query.
     */
    private const TRAVELLABLE = ['city', 'town', 'village', 'municipality', 'state', 'province', 'country', 'island'];

    public function __construct(private readonly NominatimGeocoder $geocoder) {}

    public function index(Request $request): JsonResponse
    {
        $query = Destination::query();

        if ($term = $request->query('q')) {
            $like = '%' . mb_strtolower($term) . '%';

            /*
             * Country as well as city.
             *
             * Matching only the city name meant "Japan" found nothing while
             * Tokyo sat in the catalogue, and "United Kingdom" found nothing
             * while London did. Someone who types their country is not making
             * a mistake — they are telling us the one thing they are sure of,
             * and answering that with an empty list reads as "we do not go
             * there" rather than "try the city".
             */
            $query->where(function ($q) use ($like) {
                $q->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(country) like ?', [$like]);
            });
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
                'hero_image_attribution' => $d->hero_image_attribution,
                'summary' => $d->summary,
            ])->all(),

            /*
             * Anywhere else on earth, deliberately in its own key.
             *
             * The catalogue is four cities, so a traveller typing "Paris" used
             * to get an empty list and no idea whether the place or the app was
             * at fault. Geocoding answers that: the city exists, we simply do
             * not cover it.
             *
             * These are NOT in `data`, and that separation is the whole point.
             * A geocoded city has no experiences behind it, so returning it
             * alongside London — identical shape, no id — would let the app
             * treat it as selectable and hand the traveller an empty screen,
             * which is a worse answer than "not yet". A distinct key makes that
             * impossible to do by accident.
             */
            'elsewhere' => $this->elsewhere($request, $destinations->count()),
        ]);
    }

    /**
     * Cities we do not cover, found by geocoding what the traveller typed.
     *
     * Only when the catalogue came back empty: Nominatim's usage policy allows
     * about one request a second, and asking it on every keystroke of a search
     * that already succeeded would spend that budget insulting the people who
     * run it for free.
     *
     * @return list<array<string, mixed>>
     */
    private function elsewhere(Request $request, int $covered): array
    {
        $term = trim((string) $request->query('q'));

        if ($covered > 0 || mb_strlen($term) < 3) {
            return [];
        }

        try {
            $found = $this->geocoder->search($term, 5);
        } catch (Throwable $e) {
            /* OutboundHttp refuses a call over the rate limit by throwing, and
               an unreachable geocoder must not take the destination list down
               with it — the covered cities are the answer that matters. */
            Log::info('geocode.unavailable', ['term' => $term, 'message' => $e->getMessage()]);

            return [];
        }

        return collect($found)
            /* Somewhere you could go, not a street or a shop: Nominatim
               answers a vague query with whatever it can find, including
               postboxes. */
            ->filter(fn (array $row) => $row['name'] !== ''
                && $row['country'] !== null
                && in_array($row['kind'] ?? '', self::TRAVELLABLE, true))
            /* Nominatim returns the same city more than once — the settlement
               and the administrative area that shares its name — and three
               identical rows reads as a broken list. */
            ->unique(fn (array $row) => mb_strtolower($row['name'] . '|' . $row['country']))
            ->take(4)
            ->map(fn (array $row) => [
                'name' => $row['name'],
                'display_name' => $row['display_name'],
                'country' => $row['country'],
                'country_code' => $row['country_code'],
                'lat' => $row['lat'],
                'lng' => $row['lng'],
                /* Stated rather than implied, so nothing downstream has to
                   infer it from a missing id. */
                'covered' => false,
            ])
            ->values()
            ->all();
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
                'hero_image_attribution' => $model->hero_image_attribution,
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
