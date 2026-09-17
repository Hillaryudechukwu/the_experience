<?php

declare(strict_types=1);

namespace App\Domains\AI\Tools;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Discovery\Services\ContextEngine;
use App\Domains\ExternalSources\Services\OfferService;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Experiences\Services\ExperiencePresenter;
use App\Domains\Recommendations\DTO\ScoredExperience;
use App\Domains\Recommendations\Services\RecommendationService;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Trips\Models\Trip;
use Carbon\CarbonImmutable;

/**
 * The only facts the guide is allowed to use.
 *
 * Each method returns structured data from a trusted service; the AI reasons
 * over these results and never acts as the database itself (spec s15).
 */
class AssistantToolbox
{
    public function __construct(
        private readonly RecommendationService $recommendations,
        private readonly ContextEngine $contextEngine,
        private readonly OfferService $offers,
        private readonly ExperiencePresenter $presenter,
    ) {}

    /** @return array<string,mixed> */
    public function definitions(): array
    {
        return [
            [
                'name' => 'recommend_experiences',
                'description' => 'Rank experiences for the traveller right now, using their profile, journey and live context. Returns scores and the reasons behind them.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'window_minutes' => ['type' => 'integer', 'description' => 'Minutes the traveller has available'],
                        'mood' => ['type' => 'string'],
                        'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'exclude_categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Categories the traveller has asked not to be shown'],
                        'free_only' => ['type' => 'boolean'],
                        'max_price_minor' => ['type' => 'integer'],
                        'query' => ['type' => 'string'],
                        'limit' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'get_weather',
                'description' => 'Current conditions and short forecast for the traveller\'s destination.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'get_city_essentials',
                'description' => 'Sourced, timestamped practical information about the destination: currency, transport, tipping, emergency numbers, customs.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['category' => ['type' => 'string']],
                ],
            ],
            [
                'name' => 'get_experience',
                'description' => 'Full detail for one experience, including verified opening hours, price provenance and offers.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['experience_id' => ['type' => 'string']],
                    'required' => ['experience_id'],
                ],
            ],
            [
                'name' => 'get_availability',
                'description' => 'Live ticket availability for an experience, per supplier. Returns "unknown" where a supplier does not publish inventory.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['experience_id' => ['type' => 'string']],
                    'required' => ['experience_id'],
                ],
            ],
            [
                'name' => 'get_itinerary',
                'description' => 'The traveller\'s current plan for today, including fixed commitments.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
        ];
    }

    public function call(string $name, array $arguments, Actor $actor, array $context): array
    {
        return match ($name) {
            'recommend_experiences' => $this->recommend($actor, $arguments, $context),
            'get_weather' => $this->weather($actor, $context),
            'get_city_essentials' => $this->essentials($actor, $arguments, $context),
            'get_experience' => $this->experience($arguments['experience_id'] ?? ''),
            'get_availability' => $this->availability($arguments['experience_id'] ?? ''),
            'get_itinerary' => $this->itinerary($actor, $context),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    public function recommend(Actor $actor, array $arguments, array $context): array
    {
        $input = array_merge($context, array_filter([
            'window_minutes' => $arguments['window_minutes'] ?? null,
            'mood' => $arguments['mood'] ?? null,
            'categories' => $arguments['categories'] ?? null,
            'exclude_categories' => $arguments['exclude_categories'] ?? null,
            'weather_exposure' => $arguments['weather_exposure'] ?? null,
            'free_only' => $arguments['free_only'] ?? null,
            'max_price_minor' => $arguments['max_price_minor'] ?? null,
            'query' => $arguments['query'] ?? null,
            'radius_metres' => $arguments['radius_metres'] ?? null,
        ], fn ($v) => $v !== null), ['surface' => 'assistant']);

        $result = $this->recommendations->recommend($actor, $input, (int) ($arguments['limit'] ?? 4));

        return [
            'recommendation_set_id' => $result['set']->id,
            'context' => [
                'local_time' => $result['context']->now->format('H:i'),
                'window_minutes' => $result['context']->windowMinutes(),
                'weather' => $result['context']->weather?->condition,
                'next_anchor' => $result['context']->nextAnchor?->title,
            ],
            'results' => array_map(function (ScoredExperience $scored) {
                return [
                    'experience_id' => $scored->candidate->experience->id,
                    'title' => $scored->candidate->experience->title,
                    'score' => $scored->score,
                    'travel_minutes' => $scored->candidate->travelMinutes(),
                    'duration_minutes' => $scored->candidate->experience->expected_duration_minutes,
                    'price' => $scored->candidate->experience->priceFrom()?->toArray(),
                    'price_freshness' => $scored->candidate->experience->priceFreshness()->toArray(),
                    'is_free' => $scored->candidate->experience->is_free,
                    'why' => array_map(fn ($r) => $r->message, $scored->reasons('positive')),
                    'caveats' => array_map(fn ($r) => $r->message, $scored->reasons('negative')),
                ];
            }, $result['results']),
        ];
    }

    public function weather(Actor $actor, array $context): array
    {
        $scoring = $this->contextEngine->build($actor, array_merge($context, ['surface' => 'assistant']));

        if ($scoring->weather === null) {
            return ['available' => false, 'reason' => 'No forecast available for this location right now.'];
        }

        return ['available' => true, 'weather' => $scoring->weather->toArray()];
    }

    public function essentials(Actor $actor, array $arguments, array $context): array
    {
        $scoring = $this->contextEngine->build($actor, array_merge($context, ['surface' => 'assistant']));
        $destination = $scoring->journey?->destination
            ?? Destination::where('slug', $context['destination'] ?? '')->first();

        if ($destination === null) {
            return ['available' => false, 'reason' => 'I do not know which city you are asking about yet.'];
        }

        $items = $destination->essentials()
            ->when($arguments['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->get()
            ->map(fn ($e) => [
                'category' => $e->category,
                'title' => $e->title,
                'body' => $e->body,
                'source' => $e->source_name,
                'source_url' => $e->source_url,
                'verified_at' => $e->verified_at->toIso8601String(),
            ])->all();

        return ['available' => true, 'destination' => $destination->name, 'essentials' => $items];
    }

    public function experience(string $id): array
    {
        $experience = Experience::with(['place', 'categories', 'destination'])->find($id);

        if ($experience === null) {
            return ['available' => false, 'reason' => 'No such experience.'];
        }

        return ['available' => true, 'experience' => $this->presenter->detail($experience)];
    }

    public function availability(string $id): array
    {
        $experience = Experience::find($id);

        if ($experience === null) {
            return ['available' => false, 'reason' => 'No such experience.'];
        }

        return [
            'available' => true,
            'availability' => $this->offers->availabilityFor($experience, CarbonImmutable::now(), 2),
        ];
    }

    public function itinerary(Actor $actor, array $context): array
    {
        $scoring = $this->contextEngine->build($actor, array_merge($context, ['surface' => 'assistant']));
        $journey = $scoring->journey;

        if ($journey === null) {
            return ['available' => false, 'reason' => 'No journey set up yet.'];
        }

        $trip = Trip::where('journey_id', $journey->id)->latest()->first();
        $itinerary = $trip?->currentItinerary()?->load('days.items');

        if ($itinerary === null) {
            return [
                'available' => false,
                'reason' => 'No itinerary generated yet.',
                'anchors' => $journey->anchors->map(fn ($a) => [
                    'title' => $a->title,
                    'starts_at' => $a->starts_at->toIso8601String(),
                ])->all(),
            ];
        }

        return [
            'available' => true,
            'days' => $itinerary->days->map(fn ($day) => [
                'date' => $day->date->toDateString(),
                'items' => $day->items->map(fn ($item) => [
                    'kind' => $item->kind,
                    'title' => $item->title,
                    'starts_at' => $item->starts_at->toIso8601String(),
                    'ends_at' => $item->ends_at->toIso8601String(),
                    'locked' => $item->locked,
                ])->all(),
            ])->all(),
        ];
    }
}
