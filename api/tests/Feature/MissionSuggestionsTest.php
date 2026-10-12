<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\JourneyIntelligence\Services\MissionSuggestionGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MissionSuggestionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_playbook_fallback_when_anthropic_is_not_configured(): void
    {
        config(['experience.assistant.api_key' => null]);

        $response = $this->getJson('/api/journeys/mission-suggestions?reason=business');

        $response->assertOk()
            ->assertJsonPath('data.reason', 'business')
            ->assertJsonPath('data.source', 'playbook');

        $suggestions = $response->json('data.suggestions');
        $this->assertIsArray($suggestions);
        $this->assertGreaterThanOrEqual(3, count($suggestions));
        $this->assertStringContainsString('work', strtolower($suggestions[0]));
    }

    public function test_business_and_family_suggestions_differ(): void
    {
        config(['experience.assistant.api_key' => null]);

        $business = $this->getJson('/api/journeys/mission-suggestions?reason=business')
            ->json('data.suggestions');
        $family = $this->getJson('/api/journeys/mission-suggestions?reason=family')
            ->json('data.suggestions');

        $this->assertNotSame($business, $family);
        $this->assertStringContainsString('children', strtolower(implode(' ', $family)));
    }

    public function test_anthropic_rewrites_chips_when_key_is_set(): void
    {
        config(['experience.assistant.api_key' => 'test-key']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'I want Lisbon evenings that fit around meetings.',
                        'I want one client-ready dinner near my hotel.',
                        'I want a short walk that still feels like the city.',
                        'I want coffee worth remembering between calls.',
                        'I want to leave with more than airport air.',
                    ]),
                ]],
            ]),
        ]);

        $response = $this->getJson(
            '/api/journeys/mission-suggestions?reason=business&destination=Lisbon&familiarity=never'
        );

        $response->assertOk()
            ->assertJsonPath('data.source', 'anthropic')
            ->assertJsonPath('data.reason', 'business');

        $this->assertCount(5, $response->json('data.suggestions'));
        $this->assertStringContainsString('Lisbon', $response->json('data.suggestions.0'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
    }

    public function test_anthropic_failure_falls_back_to_playbook(): void
    {
        config(['experience.assistant.api_key' => 'test-key']);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529),
        ]);

        $response = $this->getJson('/api/journeys/mission-suggestions?reason=layover');

        $response->assertOk()->assertJsonPath('data.source', 'playbook');
        $this->assertSame(
            app(MissionSuggestionGenerator::class)->fallback('layover'),
            $response->json('data.suggestions'),
        );
    }

    public function test_anthropic_failure_is_not_cached_so_retry_can_succeed(): void
    {
        config(['experience.assistant.api_key' => 'test-key']);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'overloaded']], 529)
                ->push([
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode([
                            'I want one sharp layover highlight before boarding.',
                            'I want something close enough to make the dash.',
                            'I want a memory that outlasts the connection.',
                            'I want food I can finish before the gate call.',
                            'I want the city in an hour, not a checklist.',
                        ]),
                    ]],
                ]),
        ]);

        $this->getJson('/api/journeys/mission-suggestions?reason=layover&destination=Lisbon')
            ->assertOk()
            ->assertJsonPath('data.source', 'playbook');

        $this->getJson('/api/journeys/mission-suggestions?reason=layover&destination=Lisbon')
            ->assertOk()
            ->assertJsonPath('data.source', 'anthropic');
    }

    public function test_invalid_reason_is_rejected(): void
    {
        $this->getJson('/api/journeys/mission-suggestions?reason=not-a-playbook')
            ->assertStatus(422);
    }
}
