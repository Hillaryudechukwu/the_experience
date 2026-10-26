<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Discovery\Models\LiveIntent;
use App\Domains\ExternalSources\Contracts\WeatherProvider;
use App\Domains\ExternalSources\DTO\WeatherSnapshot;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

class ContextualGuidanceTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    public function test_a_mood_is_remembered_until_it_is_cleared_or_expires(): void
    {
        $destination = $this->destination();
        $this->pair($destination);
        $token = $this->guest();

        $mood = $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/discovery/mood', $this->body($destination, ['mood' => 'hungry']))
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'high-hungry');

        $this->assertSame($mood->json('data.0.id'), $mood->json('primary.experience_id'));

        $now = $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/discovery/now', $this->body($destination));

        $now->assertOk();
        $now->assertJsonPath('data.0.slug', 'high-hungry');
        /* Said out loud, so the app can show the mood it is still applying
           and offer to clear it after a restart. */
        $now->assertJsonPath('active_intent.mood', 'hungry');
        $now->assertJsonPath('primary.experience_id', $now->json('data.0.id'));
        $this->assertNotNull($now->json('primary.directions_url'));
        $this->assertSame('score-v1.4', $now->json('context.engine_version'));

        $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/discovery/intent', $this->body($destination, ['clear' => ['mood']]))
            ->assertOk();

        $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/discovery/now', $this->body($destination))
            ->assertJsonPath('data.0.slug', 'low-hungry');
    }

    public function test_an_expired_intent_does_not_change_the_ranking(): void
    {
        $destination = $this->destination();
        $this->pair($destination);
        $token = $this->guest();
        $guest = GuestSession::where('token', $token)->first();

        LiveIntent::create([
            'guest_session_id' => $guest->id,
            'mood' => 'hungry',
            'categories' => [],
            'exclude_categories' => [],
            'free_only' => false,
            'source' => 'discover',
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);

        $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/discovery/now', $this->body($destination))
            ->assertJsonPath('data.0.slug', 'low-hungry');

        $this->assertSame(0, LiveIntent::count());
    }

    public function test_a_guide_request_to_eat_changes_the_next_discovery(): void
    {
        $destination = $this->destination();
        $this->pair($destination);
        $token = $this->guest();

        $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/assistant/message', $this->body($destination, ['message' => 'somewhere to eat']))
            ->assertOk();

        $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/discovery/now', $this->body($destination))
            ->assertJsonPath('data.0.slug', 'high-hungry');
    }

    public function test_the_model_is_given_earlier_turns(): void
    {
        config([
            'experience.assistant.driver' => 'anthropic',
            'experience.assistant.api_key' => 'test-key',
            'experience.assistant.model' => 'claude-sonnet-5',
        ]);

        $seen = [];
        Http::fake(['api.anthropic.com/*' => function ($request) use (&$seen) {
            $seen[] = $request->data()['messages'] ?? [];

            return Http::response([
                'content' => [['type' => 'text', 'text' => 'Noted.']],
                'stop_reason' => 'end_turn',
            ]);
        }]);

        $destination = $this->destination();
        $token = $this->guest();
        $first = $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/assistant/message', $this->body($destination, ['message' => 'somewhere quiet']))
            ->assertOk();

        $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/assistant/message', $this->body($destination, [
                'message' => 'and free',
                'conversation_id' => $first->json('data.conversation_id'),
            ]))
            ->assertOk();

        $second = $seen[1] ?? [];
        $texts = array_map(fn ($message) => is_string($message['content'] ?? null) ? $message['content'] : '', $second);

        $this->assertContains('somewhere quiet', $texts);
        $this->assertContains('and free', $texts);
    }

    public function test_discovery_includes_clothing_for_a_wet_forecast_and_omits_it_when_disabled(): void
    {
        $this->app->instance(WeatherProvider::class, new class implements WeatherProvider
        {
            public function key(): string
            {
                return 'test';
            }

            public function forecast(GeoPoint $point, CarbonImmutable $from, int $hours = 24): WeatherSnapshot
            {
                return new WeatherSnapshot(
                    condition: 'rain',
                    temperatureC: 6,
                    precipitationProbability: 70,
                    hourly: [[
                        'at' => $from->toIso8601String(),
                        'condition' => 'rain',
                        'temp_c' => 6,
                        'precip_probability' => 70,
                    ]],
                    freshness: new Freshness('test', $from, Freshness::HIGHLY_DYNAMIC),
                );
            }
        });

        $destination = $this->destination();
        $this->experience($destination, ['slug' => 'museum-one', 'title' => 'Museum']);

        $wet = $this->postJson('/api/discovery/now', $this->body($destination))->assertOk();
        $this->assertStringContainsString('waterproof', $wet->json('context.weather.clothing.headline'));

        $this->withHeader('X-Guest-Token', $wet->headers->get('X-Guest-Token'))
            ->postJson('/api/assistant/message', $this->body($destination, ['message' => 'will it rain']))
            ->assertOk()
            ->assertSee('waterproof', false);

        config(['experience.weather.clothing_enabled' => false]);
        $this->postJson('/api/discovery/now', $this->body($destination))
            ->assertOk()
            ->assertJsonMissingPath('context.weather.clothing');
    }

    public function test_explicit_destination_replaces_a_conflicting_active_journey_for_weather(): void
    {
        $weather = new class implements WeatherProvider
        {
            /** @var list<array{float, float}> */
            public array $points = [];

            public function key(): string
            {
                return 'test';
            }

            public function forecast(GeoPoint $point, CarbonImmutable $from, int $hours = 24): WeatherSnapshot
            {
                $this->points[] = [$point->lat, $point->lng];

                return new WeatherSnapshot(
                    condition: 'clear',
                    temperatureC: round($point->lat, 1),
                    precipitationProbability: 0,
                    hourly: [],
                    freshness: new Freshness('test', $from, Freshness::HIGHLY_DYNAMIC),
                );
            }
        };
        $this->app->instance(WeatherProvider::class, $weather);

        $london = $this->destination([
            'slug' => 'london-test',
            'name' => 'London',
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);
        $tokyo = $this->destination([
            'slug' => 'tokyo-test',
            'name' => 'Tokyo',
            'country' => 'Japan',
            'country_code' => 'JP',
            'timezone' => 'Asia/Tokyo',
            'lat' => 35.6762,
            'lng' => 139.6503,
            'currency' => 'JPY',
        ]);
        $this->experience($tokyo, [
            'slug' => 'tokyo-museum',
            'title' => 'Tokyo Museum',
            'lat' => 35.6762,
            'lng' => 139.6503,
        ]);
        $guest = GuestSession::create(['token' => Str::random(40)]);
        $journey = $this->journey($london, new Actor(guestSessionId: $guest->id));

        $response = $this->withHeader('X-Guest-Token', $guest->token)
            ->postJson('/api/discovery/now', [
                'destination_id' => $tokyo->id,
                'destination' => $tokyo->slug,
                'journey_id' => $journey->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('context.location_precision', 'city')
            ->assertJsonPath('context.weather.temperature_c', 35.7)
            ->assertJsonPath('context.journey', null);
        $this->assertSame([[35.6762, 139.6503]], $weather->points);
    }

    public function test_an_empty_result_has_no_primary_action(): void
    {
        $destination = $this->destination();
        $this->experience($destination, ['slug' => 'museum-one', 'title' => 'Museum', 'is_free' => false]);

        $this->postJson('/api/discovery/now', $this->body($destination, ['free_only' => true]))
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('primary', null);
    }

    public function test_registering_adopts_the_live_intent(): void
    {
        $destination = $this->destination();
        $this->pair($destination);
        $token = $this->guest();

        $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/discovery/mood', $this->body($destination, ['mood' => 'hungry']))
            ->assertOk();

        $this->withHeader('X-Guest-Token', $token)
            ->postJson('/api/auth/register', [
                'name' => 'Ada',
                'email' => 'ada@example.com',
                'password' => 'password123',
            ])
            ->assertCreated();

        $intent = LiveIntent::first();
        $this->assertNotNull($intent->user_id);
        $this->assertNull($intent->guest_session_id);
        $this->assertSame('hungry', $intent->mood);
    }

    private function guest(): string
    {
        return (string) $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
    }

    private function pair(Destination $destination): void
    {
        $this->experience($destination, [
            'slug' => 'low-hungry',
            'title' => 'Quiet Gallery',
            'mood_affinity' => ['hungry' => 20],
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);
        $this->experience($destination, [
            'slug' => 'high-hungry',
            'title' => 'Market Kitchen',
            'mood_affinity' => ['hungry' => 90],
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function body(Destination $destination, array $extra = []): array
    {
        return array_merge([
            'destination_id' => $destination->id,
            'lat' => 51.5074,
            'lng' => -0.1278,
        ], $extra);
    }
}
