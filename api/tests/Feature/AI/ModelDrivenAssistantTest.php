<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Domains\AI\Services\AssistantOrchestrator;
use App\Domains\AI\Tools\AssistantToolbox;
use App\Domains\Shared\ValueObjects\Actor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The tool-calling loop against the Claude API.
 *
 * Faked end to end so the suite neither spends money nor depends on the
 * network, but the request shape asserted here is the one that broke in
 * practice.
 */
class ModelDrivenAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config([
            'experience.assistant.driver' => 'anthropic',
            'experience.assistant.api_key' => 'test-key',
            'experience.assistant.model' => 'claude-sonnet-5',
        ]);
    }

    public function test_a_tool_called_with_no_arguments_is_echoed_back_as_an_object(): void
    {
        /*
         * The bug this guards: `{}` decodes to an empty PHP array and
         * re-encodes as `[]`, which the API rejects with
         * "tool_use.input: Input should be an object". The orchestrator then
         * falls back to the rules driver, so the only visible symptom is that
         * the model silently stops being used.
         */
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('get_weather', []))
            ->push($this->text('It is cloudy.')),
        ]);

        $reply = app(AssistantOrchestrator::class)->handle(
            new Actor,
            'What is the weather doing?',
            ['destination' => 'london'],
        );

        $this->assertSame('anthropic', $reply['message']['driver']);

        $secondRequest = null;
        Http::assertSent(function (Request $request) use (&$secondRequest) {
            $messages = $request->data()['messages'] ?? [];
            if (count($messages) > 1) {
                $secondRequest = $request;
            }

            return true;
        });

        $this->assertNotNull($secondRequest, 'The loop should have made a second call with the tool result.');

        /* Inspect the wire format, not the PHP array: that is where it broke. */
        $body = json_decode($secondRequest->body(), true, 512, JSON_THROW_ON_ERROR);
        $toolUse = collect($body['messages'][1]['content'])->firstWhere('type', 'tool_use');

        $this->assertStringContainsString('"input":{}', $secondRequest->body());
        $this->assertIsArray($toolUse);
    }

    public function test_the_model_answers_from_tool_results(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('recommend_experiences', ['limit' => 3]))
            ->push($this->text('Borough Market is the pick — it is free and close by.')),
        ]);

        $reply = app(AssistantOrchestrator::class)->handle(
            new Actor,
            'What is worth doing near Borough?',
            ['destination' => 'london', 'lat' => 51.5055, 'lng' => -0.0910],
        );

        $this->assertSame('anthropic', $reply['message']['driver']);
        $this->assertContains('recommend_experiences', $reply['message']['tool_calls']);
        $this->assertStringContainsString('Borough Market', $reply['message']['content']);
    }

    public function test_a_price_the_model_invented_is_removed_before_the_traveller_sees_it(): void
    {
        /*
         * The tool result is pinned rather than ranked, and that is the point
         * of this test rather than an implementation detail of it.
         *
         * The premise is that £42 appears nowhere in what the model was given.
         * Letting the live ranker pick the experiences made that premise
         * depend on the clock and the weather: the guard treats minor units as
         * authorising the major-unit rendering, so any price between £42.00
         * and £42.99 anywhere in the result set legitimately grounds a bare
         * "42" — as does any duration of 2520 to 2579 minutes, by the same
         * rule applied to hours. The test passed or failed according to which
         * experiences happened to rank near Borough at the hour it ran.
         *
         * Nothing about the behaviour under test needs a real ranking. What it
         * needs is a known set of facts that demonstrably excludes the number,
         * which is asserted below rather than assumed.
         */
        $facts = [
            'recommendation_set_id' => 'set-under-test',
            'context' => ['local_time' => '10:15', 'window_minutes' => null, 'weather' => 'cloudy'],
            'results' => [[
                'experience_id' => 'exp-borough-market',
                'title' => 'Borough Market',
                'score' => 88,
                'travel_minutes' => 7,
                'duration_minutes' => 60,
                'price' => null,
                'is_free' => true,
                'why' => ['Matches your interest in food'],
                'caveats' => [],
            ]],
        ];

        $this->assertStringNotContainsString(
            '42',
            json_encode($facts, JSON_THROW_ON_ERROR),
            'The fixture must not contain the number the model is supposed to have invented.',
        );

        $this->partialMock(
            AssistantToolbox::class,
            fn (MockInterface $toolbox) => $toolbox->shouldReceive('call')->once()->andReturn($facts),
        );

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('recommend_experiences', ['limit' => 2]))
            ->push($this->text('Borough Market is great. Entry is £42 on the door.')),
        ]);

        $reply = app(AssistantOrchestrator::class)->handle(
            new Actor,
            'What is worth doing near Borough?',
            ['destination' => 'london', 'lat' => 51.5055, 'lng' => -0.0910],
        );

        $this->assertFalse($reply['message']['grounded']);
        $this->assertStringNotContainsString('£42', $reply['message']['content']);
        $this->assertContains('£42', $reply['message']['removed_claims']);
        $this->assertStringContainsString('Borough Market is great.', $reply['message']['content']);
    }

    public function test_an_api_failure_degrades_to_the_deterministic_driver(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        $reply = app(AssistantOrchestrator::class)->handle(
            new Actor,
            'What should I do right now?',
            ['destination' => 'london'],
        );

        $this->assertSame('rules', $reply['message']['driver'], 'The guide must keep working without the model.');
        $this->assertNotEmpty($reply['message']['content']);
    }

    public function test_without_a_key_the_model_is_never_called(): void
    {
        config(['experience.assistant.api_key' => null]);
        Http::fake();

        $reply = app(AssistantOrchestrator::class)->handle(
            new Actor,
            'What should I do right now?',
            ['destination' => 'london'],
        );

        $this->assertSame('rules', $reply['message']['driver']);
        Http::assertNothingSent();
    }

    private function toolUse(string $name, array $input): array
    {
        return [
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'text', 'text' => 'Let me check.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => $name, 'input' => $input],
            ],
        ];
    }

    private function text(string $text): array
    {
        return [
            'id' => 'msg_2',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }
}
