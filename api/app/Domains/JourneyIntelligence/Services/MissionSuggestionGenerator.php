<?php

declare(strict_types=1);

namespace App\Domains\JourneyIntelligence\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Chip suggestions for “What would make this trip feel worth it?”
 *
 * Seeded from the reason-for-travel playbook so the screen is never empty,
 * then optionally rewritten by Claude so the wording fits the purpose and
 * destination. Failures fall back to the curated list — onboarding must not
 * hang on the model.
 */
class MissionSuggestionGenerator
{
    /** @var array<string, list<string>> */
    private const FALLBACKS = [
        'holiday' => [
            'I want the essentials without feeling like I only saw the postcard.',
            'I want at least one meal I will still talk about next year.',
            'I want a mix of iconic and a little off the main square.',
        ],
        'business' => [
            "I'm here for work, but I don't want to leave without tasting the city.",
            'I want short, high-value evenings close to where I am staying.',
            'I want one impressive dinner I could take a client to.',
        ],
        'conference' => [
            'I want something memorable in the gaps between sessions.',
            'I want one neighbourhood walk that is not the hotel lobby.',
            'I want a dinner worth recommending to people I meet here.',
        ],
        'family' => [
            'I want my children to really experience this city.',
            'I want days that do not end in meltdowns and long queues.',
            'I want something we will all remember, not just endure.',
        ],
        'romantic' => [
            'I want evenings that feel like us, not like a checklist.',
            'I want good light, good food, and nowhere too crowded.',
            'I want one moment that feels unforgettable for two.',
        ],
        'anniversary' => [
            'I want an unforgettable anniversary weekend.',
            'I want dinner and a view that feel worth the trip alone.',
            'I want fewer stops and more time in the right places.',
        ],
        'birthday' => [
            'I want this birthday trip to feel celebratory, not just busy.',
            'I want one night that feels like the reason we came.',
            'I want good food, good company, and a little sparkle.',
        ],
        'honeymoon' => [
            'I want unhurried days and places that feel only ours.',
            'I want romance without the tourist crush.',
            'I want beauty, slow meals, and nowhere we have to rush.',
        ],
        'solo' => [
            'I want space to explore at my own pace.',
            'I want places I can enjoy alone — and company when I choose it.',
            'I want to come back feeling clearer, not just fuller.',
        ],
        'visiting_friends' => [
            'I want to do what locals actually do, not the tourist loop.',
            'I want recommendations my hosts will be proud to share.',
            'I want evenings that fit around seeing people I care about.',
        ],
        'layover' => [
            'I want the highest-value hours before I have to leave.',
            'I want something close, quick, and worth the dash.',
            'I want one strong memory from a short stop.',
        ],
        'pilgrimage' => [
            'I want time and quiet around the places that matter spiritually.',
            'I want to move with respect for local rhythms and sacred sites.',
            'I want reflection more than a packed sightseeing list.',
        ],
        'education' => [
            'I want to learn something real about this place.',
            'I want depth and context, without overspending.',
            'I want museums, sites, and walks that teach as much as they show.',
        ],
        'medical' => [
            'I want gentle days close to where I need to be.',
            'I want restful options that do not drain me.',
            'I want short, low-effort experiences around treatment.',
        ],
        'relocation' => [
            'I want to feel what living here would actually be like.',
            'I want neighbourhoods, parks, and everyday places — not just landmarks.',
            'I want a sense of daily life before I decide.',
        ],
        'shopping' => [
            'I want the districts and markets this city is known for.',
            'I want local makers as much as the big names.',
            'I want to leave with something I could not buy at home.',
        ],
        'sports_event' => [
            'I want the fixture to anchor the day — everything else fits around it.',
            'I want pre-match atmosphere without missing kick-off.',
            'I want one good meal and enough buffer for transport.',
        ],
        'festival' => [
            'I want the event to be the night — with room to enjoy the city around it.',
            'I want late options that still get me home safely.',
            'I want the energy of the festival without losing the rest of the trip.',
        ],
        'food' => [
            'I want to eat the way locals actually eat.',
            'I want markets, producers, and the dishes this place is known for.',
            'I want at least one meal I could not have at home.',
        ],
        'cultural' => [
            'I want depth over ticking boxes.',
            'I want to understand the place, not just photograph it.',
            'I want one museum or site that still stays with me.',
        ],
        'photography' => [
            'I want golden hour and viewpoints worth the climb.',
            'I want frames I could not get from a postcard.',
            'I want light, angles, and time to wait for them.',
        ],
        'retreat' => [
            'I want to come back feeling rested, not exhausted.',
            'I want green space, quiet, and nowhere I have to perform.',
            'I want days that restore more than they demand.',
        ],
        'backpacking' => [
            'I want great experiences without spending like a tourist.',
            'I want local life more than polished attractions.',
            'I want free or cheap highlights that still feel special.',
        ],
        'other' => [
            "I want this trip to feel like mine, not a template.",
            'I want a few things done well rather than everything half-seen.',
            'I want to leave knowing I used the time well.',
        ],
    ];

    /**
     * @return array{suggestions: list<string>, source: string, reason: string}
     */
    public function suggest(string $reason, ?string $destination = null, ?string $familiarity = null): array
    {
        $reason = array_key_exists($reason, config('experience.playbooks', []))
            ? $reason
            : 'other';

        $cacheKey = sprintf(
            'mission-suggestions:v1:%s:%s:%s',
            $reason,
            Str::slug((string) $destination) ?: 'anywhere',
            $familiarity ?: 'any',
        );

        /** @var array{suggestions: list<string>, source: string, reason: string} $payload */
        $payload = Cache::remember($cacheKey, now()->addDay(), function () use ($reason, $destination, $familiarity) {
            $fallback = $this->fallback($reason);

            if (! $this->anthropicReady()) {
                return [
                    'suggestions' => $fallback,
                    'source' => 'playbook',
                    'reason' => $reason,
                ];
            }

            $generated = $this->viaAnthropic($reason, $destination, $familiarity);

            return [
                'suggestions' => $generated === [] ? $fallback : $generated,
                'source' => $generated === [] ? 'playbook' : 'anthropic',
                'reason' => $reason,
            ];
        });

        return $payload;
    }

    /** @return list<string> */
    public function fallback(string $reason): array
    {
        return self::FALLBACKS[$reason] ?? self::FALLBACKS['other'];
    }

    private function anthropicReady(): bool
    {
        /* Chip generation is independent of the chat assistant driver. */
        return filled(config('experience.assistant.api_key'));
    }

    /** @return list<string> */
    private function viaAnthropic(string $reason, ?string $destination, ?string $familiarity): array
    {
        $playbook = config("experience.playbooks.{$reason}", config('experience.playbooks.other'));
        $label = $playbook['label'] ?? $reason;
        $goals = implode(', ', (array) ($playbook['goals'] ?? []));

        $where = filled($destination) ? $destination : 'this destination';
        $know = match ($familiarity) {
            'never' => 'They have never been.',
            'once' => 'They have been once.',
            'few' => 'They have visited a few times.',
            'well' => 'They know the place well.',
            default => 'Familiarity is unknown.',
        };

        $prompt = <<<PROMPT
You write short first-person trip intentions for a travel app onboarding chip.

Reason for travel: {$label} ({$reason})
Destination: {$where}
{$know}
Playbook soft goals (for tone only): {$goals}

Return ONLY a JSON array of exactly 5 strings.
Each string:
- is one sentence in first person ("I want…")
- is specific to this reason for travel
- mentions the destination by name when it helps, otherwise stays general
- is under 110 characters
- never invents opening times, prices, or named venues that must be booked
- is suitable to paste into a free-text mission field

Example shape: ["I want…","I want…"]
PROMPT;

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('experience.assistant.api_key'),
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout(20)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('experience.assistant.model'),
                    'max_tokens' => 400,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('mission_suggestions.anthropic_failed', [
                    'status' => $response->status(),
                    'error' => $response->json('error.message') ?? mb_substr($response->body(), 0, 300),
                ]);

                return [];
            }

            $text = collect($response->json('content') ?? [])
                ->where('type', 'text')
                ->pluck('text')
                ->implode("\n");

            return $this->parseSuggestions($text);
        } catch (\Throwable $e) {
            Log::warning('mission_suggestions.anthropic_exception', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /** @return list<string> */
    private function parseSuggestions(string $text): array
    {
        if (preg_match('/\[[\s\S]*\]/', $text, $match) !== 1) {
            return [];
        }

        try {
            $decoded = json_decode($match[0], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $clean = [];
        foreach ($decoded as $item) {
            if (! is_string($item)) {
                continue;
            }

            $line = trim(preg_replace('/\s+/', ' ', $item) ?? '');
            if ($line === '' || mb_strlen($line) > 140) {
                continue;
            }

            $clean[] = $line;
            if (count($clean) >= 5) {
                break;
            }
        }

        return count($clean) >= 3 ? $clean : [];
    }
}
