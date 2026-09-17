<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AssistantConversation;
use App\Domains\AI\Models\AssistantMessage;
use App\Domains\AI\Tools\AssistantToolbox;
use App\Domains\Shared\ValueObjects\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * The AI Experience Guide (spec s15).
 *
 * The orchestrator reasons *over* trusted services; it is never the factual
 * database. Two drivers share the same toolbox and the same grounding guard:
 *
 *   rules      deterministic composition from tool results. Always available,
 *              no network, and what the regression tests run against.
 *   anthropic  a tool-calling loop. Facts still come only from the toolbox, and
 *              every reply is passed through GroundingGuard before it is stored
 *              or returned (acceptance 61).
 */
class AssistantOrchestrator
{
    public function __construct(
        private readonly AssistantToolbox $toolbox,
        private readonly IntentParser $intents,
        private readonly GroundingGuard $guard,
        private readonly HttpFactory $http,
    ) {}

    public function handle(Actor $actor, string $message, array $context = [], ?string $conversationId = null): array
    {
        $conversation = $this->conversation($actor, $conversationId, $message);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $message,
            'driver' => null,
        ]);

        $driver = config('experience.assistant.driver');
        $answer = $driver === 'anthropic' && config('experience.assistant.model') && env('ANTHROPIC_API_KEY')
            ? $this->viaAnthropic($actor, $message, $context, $conversation)
            : $this->viaRules($actor, $message, $context);

        $verified = $this->guard->verify($answer['text'], $answer['facts']);

        if (! $verified['grounded']) {
            Log::warning('assistant.ungrounded_claim', [
                'driver' => $answer['driver'],
                'violations' => $verified['violations'],
            ]);
        }

        $stored = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $verified['text'],
            'tool_calls' => $answer['tool_calls'],
            'grounding' => [
                'facts' => $answer['facts'],
                'grounded' => $verified['grounded'],
                'removed_claims' => $verified['violations'],
                'retrieved_at' => CarbonImmutable::now()->toIso8601String(),
            ],
            'suggestions' => $answer['suggestions'],
            'driver' => $answer['driver'],
        ]);

        return [
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $stored->id,
                'role' => 'assistant',
                'content' => $verified['text'],
                'suggestions' => $answer['suggestions'],
                'experiences' => $answer['experiences'],
                'grounded' => $verified['grounded'],
                'removed_claims' => $verified['violations'],
                'tool_calls' => array_column($answer['tool_calls'], 'name'),
                'driver' => $answer['driver'],
            ],
        ];
    }

    /** Deterministic composition — no model, no invented facts. */
    private function viaRules(Actor $actor, string $message, array $context): array
    {
        $now = CarbonImmutable::now();
        $intent = $this->intents->parse($message, $now);
        $toolCalls = [];
        $facts = [];
        $experiences = [];

        if ($intent['kind'] === 'essentials') {
            $facts['essentials'] = $this->toolbox->essentials($actor, [], $context);
            $toolCalls[] = ['name' => 'get_city_essentials', 'arguments' => []];
            $text = $this->composeEssentials($facts['essentials']);
        } elseif ($intent['kind'] === 'weather') {
            $facts['weather'] = $this->toolbox->weather($actor, $context);
            $toolCalls[] = ['name' => 'get_weather', 'arguments' => []];
            $text = $this->composeWeather($facts['weather']);
        } elseif ($intent['kind'] === 'itinerary') {
            $facts['itinerary'] = $this->toolbox->itinerary($actor, $context);
            $toolCalls[] = ['name' => 'get_itinerary', 'arguments' => []];
            $text = $this->composeItinerary($facts['itinerary']);
        } else {
            $arguments = array_filter(array_merge($intent['filters'], [
                'window_minutes' => $intent['window_minutes'],
                'mood' => $intent['mood'],
                'limit' => 4,
            ]), fn ($v) => $v !== null && $v !== []);

            $facts['recommendations'] = $this->toolbox->recommend($actor, $arguments, $context);
            $toolCalls[] = ['name' => 'recommend_experiences', 'arguments' => $arguments];
            $text = $this->composeRecommendations($facts['recommendations'], $intent);
            $experiences = $facts['recommendations']['results'] ?? [];
        }

        return [
            'text' => $text,
            'facts' => $facts,
            'tool_calls' => $toolCalls,
            'experiences' => $experiences,
            'suggestions' => $this->suggestions($intent),
            'driver' => 'rules',
        ];
    }

    /** Tool-calling loop against the Claude API. */
    private function viaAnthropic(Actor $actor, string $message, array $context, AssistantConversation $conversation): array
    {
        $messages = [['role' => 'user', 'content' => $message]];
        $facts = [];
        $toolCalls = [];
        $experiences = [];
        $rounds = (int) config('experience.assistant.max_tool_rounds', 4);

        for ($round = 0; $round < $rounds; $round++) {
            $response = $this->http
                ->withHeaders([
                    'x-api-key' => (string) env('ANTHROPIC_API_KEY'),
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout(30)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('experience.assistant.model'),
                    'max_tokens' => 1024,
                    'system' => $this->systemPrompt(),
                    'tools' => $this->toolbox->definitions(),
                    'messages' => $messages,
                ]);

            if ($response->failed()) {
                Log::warning('assistant.anthropic_failed', ['status' => $response->status()]);

                return $this->viaRules($actor, $message, $context);
            }

            $body = $response->json();
            $messages[] = ['role' => 'assistant', 'content' => $body['content']];

            $toolUses = array_values(array_filter($body['content'] ?? [], fn ($b) => ($b['type'] ?? '') === 'tool_use'));

            if ($toolUses === []) {
                $text = implode("\n", array_column(
                    array_filter($body['content'] ?? [], fn ($b) => ($b['type'] ?? '') === 'text'),
                    'text',
                ));

                return [
                    'text' => $text,
                    'facts' => $facts,
                    'tool_calls' => $toolCalls,
                    'experiences' => $experiences,
                    'suggestions' => [],
                    'driver' => 'anthropic',
                ];
            }

            $results = [];
            foreach ($toolUses as $use) {
                $result = $this->toolbox->call($use['name'], $use['input'] ?? [], $actor, $context);
                $facts[$use['name']][] = $result;
                $toolCalls[] = ['name' => $use['name'], 'arguments' => $use['input'] ?? []];

                if ($use['name'] === 'recommend_experiences') {
                    $experiences = array_merge($experiences, $result['results'] ?? []);
                }

                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $use['id'],
                    'content' => json_encode($result, JSON_THROW_ON_ERROR),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return $this->viaRules($actor, $message, $context);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are the Experience Guide inside a travel app.

        Rules you must follow:
        - Every price, opening time, duration, travel time and availability you state must come
          verbatim from a tool result in this conversation. If a tool did not return it, say you
          do not have it rather than estimating.
        - Prefer two to four concrete options over a long list. Say why each one suits this
          traveller, using the reasons the recommendation tool gave you.
        - Respect fixed commitments. If the traveller has an anchor coming up, never suggest
          something that would put it at risk.
        - Be brief and practical. Plain sentences, no marketing language, no emoji.
        PROMPT;
    }

    private function composeRecommendations(array $facts, array $intent): string
    {
        $results = $facts['results'] ?? [];

        if ($results === []) {
            return 'I could not find anything that genuinely fits those constraints right now. Widening the time you have or the distance you are willing to travel would open it up.';
        }

        $opening = $intent['echo'] === []
            ? sprintf('Here %s %d worth your time right now.', count($results) === 1 ? 'is' : 'are', count($results))
            : sprintf('For %s, %s.', implode(' and ', $intent['echo']), count($results) === 1 ? 'this is the one that fits' : 'these fit');

        $lines = [$opening];

        foreach ($results as $index => $result) {
            $why = $result['why'][0] ?? null;
            $parts = [sprintf('%d. %s (score %d)', $index + 1, $result['title'], $result['score'])];

            if ($result['travel_minutes'] !== null) {
                $parts[] = sprintf('%d minutes away', $result['travel_minutes']);
            }
            $parts[] = $result['is_free']
                ? 'free'
                : ($result['price']['formatted'] ?? 'price not verified');

            $line = implode(' · ', $parts);
            if ($why !== null) {
                $line .= '. ' . $why . '.';
            }
            if (($result['caveats'][0] ?? null) !== null) {
                $line .= ' One thing to know: ' . lcfirst($result['caveats'][0]) . '.';
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function composeWeather(array $facts): string
    {
        if (! ($facts['available'] ?? false)) {
            return 'I do not have a forecast for your location at the moment.';
        }

        $weather = $facts['weather'];

        return sprintf(
            'Right now it is %s at %s degrees, with a %s percent chance of rain. %s',
            str_replace('_', ' ', $weather['condition']),
            rtrim(rtrim(number_format($weather['temperature_c'], 1), '0'), '.'),
            $weather['precipitation_probability'],
            $weather['is_poor']
                ? 'Worth leaning towards indoor options for now.'
                : 'Good conditions for being outside.',
        );
    }

    private function composeEssentials(array $facts): string
    {
        if (! ($facts['available'] ?? false)) {
            return $facts['reason'] ?? 'I do not have that yet.';
        }

        $lines = [sprintf('Practical things worth knowing in %s:', $facts['destination'])];

        foreach (array_slice($facts['essentials'], 0, 6) as $item) {
            $lines[] = sprintf('- %s: %s (%s)', $item['title'], $item['body'], $item['source']);
        }

        return implode("\n", $lines);
    }

    private function composeItinerary(array $facts): string
    {
        if (! ($facts['available'] ?? false)) {
            $anchors = $facts['anchors'] ?? [];

            return $anchors === []
                ? 'You do not have a plan built yet. Tell me how long you have and I will put one together.'
                : 'No itinerary has been generated yet, but I can see your fixed commitments. Say the word and I will build the day around them.';
        }

        $lines = ['Here is how your plan looks.'];

        foreach ($facts['days'] as $day) {
            $lines[] = $day['date'] . ':';
            foreach ($day['items'] as $item) {
                $lines[] = sprintf(
                    '- %s %s%s',
                    CarbonImmutable::parse($item['starts_at'])->format('H:i'),
                    $item['title'],
                    $item['locked'] ? ' (fixed)' : '',
                );
            }
        }

        return implode("\n", $lines);
    }

    private function suggestions(array $intent): array
    {
        return match ($intent['kind']) {
            'weather' => ['What should I do indoors?', 'Is it better tomorrow?'],
            'essentials' => ['How do I pay for transport?', 'Anything I should avoid doing?'],
            'itinerary' => ['Replan around the weather', 'I have a free evening — ideas?'],
            default => ['I only have an hour', 'Something a local would do', 'Somewhere to eat nearby'],
        };
    }

    private function conversation(Actor $actor, ?string $id, string $firstMessage): AssistantConversation
    {
        if ($id !== null) {
            $existing = AssistantConversation::ownedBy($actor)->find($id);
            if ($existing !== null) {
                return $existing;
            }
        }

        return AssistantConversation::create(array_merge($actor->ownerAttributes(), [
            'title' => mb_substr($firstMessage, 0, 60),
        ]));
    }
}
