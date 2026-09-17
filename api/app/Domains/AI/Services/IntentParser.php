<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Reads a traveller's own words into structured discovery filters (spec s5.6).
 *
 * Deliberately deterministic: the same sentence always produces the same
 * filters, which keeps recommendation regression tests meaningful.
 */
class IntentParser
{
    public function parse(string $message, CarbonImmutable $now): array
    {
        $text = mb_strtolower(trim($message));

        $intent = [
            'kind' => 'recommend',
            'filters' => [],
            'window_minutes' => null,
            'mood' => null,
            'echo' => [],
        ];

        if ($this->mentions($text, [
            'currency', 'tipping', 'do i tip', 'should i tip', 'how much to tip', 'emergency number',
            'power socket', 'plug ', 'adapter', 'which side of the road', 'local customs', 'etiquette',
            'scam', 'local law', 'how do i pay', 'how to pay', 'oyster', 'metro card', 'travel card',
            'is it safe', 'what language', 'do they speak',
        ])) {
            $intent['kind'] = 'essentials';

            return $intent;
        }

        if ($this->mentions($text, ['weather', 'will it rain', 'forecast', 'temperature'])) {
            $intent['kind'] = 'weather';

            return $intent;
        }

        if ($this->mentions($text, ['my day', 'my itinerary', 'my plan', 'what am i doing', 'schedule'])) {
            $intent['kind'] = 'itinerary';

            return $intent;
        }

        /* "I have 3 hours", "45 minutes", "half a day" */
        if (preg_match('/(\d+(?:\.\d+)?)\s*(hour|hr|h)\b/', $text, $m)) {
            $intent['window_minutes'] = (int) round((float) $m[1] * 60);
            $intent['echo'][] = sprintf('a %s-hour window', rtrim(rtrim($m[1], '0'), '.'));
        } elseif (preg_match('/(\d+)\s*(minute|min)\b/', $text, $m)) {
            $intent['window_minutes'] = (int) $m[1];
            $intent['echo'][] = sprintf('%d minutes', (int) $m[1]);
        } elseif ($this->mentions($text, ['half a day', 'half day'])) {
            $intent['window_minutes'] = 300;
            $intent['echo'][] = 'half a day';
        } elseif ($this->mentions($text, ['all day', 'whole day', 'full day'])) {
            $intent['window_minutes'] = 600;
            $intent['echo'][] = 'the whole day';
        }

        /* "before my flight at 7pm" */
        if (preg_match('/before .*?(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/', $text, $m)) {
            $hour = (int) $m[1];
            $minute = (int) ($m[2] ?? 0);
            if (($m[3] ?? '') === 'pm' && $hour < 12) {
                $hour += 12;
            }
            $deadline = $now->setTime($hour, $minute);
            if ($deadline->gt($now)) {
                $intent['window_minutes'] = min($intent['window_minutes'] ?? 10000, (int) $now->diffInMinutes($deadline));
                $intent['echo'][] = 'the time before ' . $deadline->format('H:i');
            }
        }

        if (preg_match('/under\s*[£$€]?\s*(\d+)/', $text, $m)) {
            $intent['filters']['max_price_minor'] = ((int) $m[1]) * 100;
            $intent['echo'][] = 'under ' . $m[0];
        }

        if ($this->mentions($text, ['free', 'no cost', 'without spending'])) {
            $intent['filters']['free_only'] = true;
            $intent['echo'][] = 'free things';
        }

        $categoryMap = [
            'family' => ['child', 'children', 'kid', 'kids', 'family', 'toddler'],
            'romantic' => ['romantic', 'romance', 'date night', 'my partner'],
            'food_experience' => ['food', 'eat', 'hungry', 'lunch', 'dinner', 'restaurant', 'market'],
            'nightlife' => ['nightlife', 'bar', 'drinks', 'club', 'tonight out'],
            'culture' => ['museum', 'gallery', 'culture', 'art', 'history', 'historical'],
            'hidden_gem' => ['unusual', 'hidden', 'off the beaten', 'different', 'secret'],
            'local_favourite' => ['locals', 'a local', 'like a local', 'not touristy', 'non touristy', 'authentic'],
            'adventure' => ['adventure', 'adrenaline', 'active'],
            'nature' => ['park', 'nature', 'green space', 'garden', 'walk outside'],
            'shopping' => ['shopping', 'shops', 'market stalls'],
        ];

        foreach ($categoryMap as $category => $words) {
            foreach ($words as $word) {
                if (! Str::contains($text, $word)) {
                    continue;
                }

                /* "I don't want another museum" is a filter too — just an inverted one. */
                if ($this->isNegated($text, $word)) {
                    $intent['filters']['exclude_categories'][] = $category;
                    $intent['echo'][] = 'no ' . str_replace('_', ' ', $category);
                } else {
                    $intent['filters']['categories'][] = $category;
                }
                break;
            }
        }

        if ($this->mentions($text, ['rain', 'rainy', 'wet', 'indoors', 'indoor'])) {
            $intent['filters']['weather_exposure'] = 'indoor';
            $intent['echo'][] = 'somewhere indoors';
        }

        if ($this->mentions($text, ['sunset', 'golden hour', 'watch the sun'])) {
            $intent['filters']['best_time'] = 'golden_hour';
            $intent['echo'][] = 'somewhere to be at golden hour';
        } elseif ($this->mentions($text, ['sunrise', 'early morning', 'first thing'])) {
            $intent['filters']['best_time'] = 'morning';
            $intent['echo'][] = 'an early start';
        }

        foreach (config('experience.moods') as $mood) {
            if (Str::contains($text, $mood)) {
                $intent['mood'] = $mood;
            }
        }

        if ($this->mentions($text, ['walking distance', 'near me', 'nearby', 'close to me', 'around here'])) {
            $intent['filters']['radius_metres'] = 2000;
            $intent['echo'][] = 'within walking distance';
        }

        /* Only fall back to a text query when nothing structured was understood.
           Otherwise "free things for children" would also demand those three
           words appear in the title, and match nothing. */
        $structured = array_diff_key($intent['filters'], array_flip(['radius_metres']));

        if ($structured === [] && $intent['window_minutes'] === null && $intent['mood'] === null) {
            $intent['filters']['query'] = $this->freeText($text);
        }

        return array_filter($intent, fn ($v) => $v !== null && $v !== []) + ['kind' => $intent['kind'], 'filters' => array_filter($intent['filters'], fn ($v) => $v !== null), 'echo' => $intent['echo'], 'window_minutes' => $intent['window_minutes'], 'mood' => $intent['mood']];
    }

    private function freeText(string $text): ?string
    {
        $stripped = preg_replace('/\b(what|whats|where|when|can|i|me|my|the|a|an|is|are|to|do|for|near|with|something|things|good|best|show|find|any)\b/', ' ', $text);
        $stripped = trim(preg_replace('/\s+/', ' ', (string) $stripped));

        return mb_strlen($stripped) >= 4 && str_word_count($stripped) <= 4 ? $stripped : null;
    }

    /** True when the phrase is preceded by a refusal, e.g. "not another museum". */
    private function isNegated(string $text, string $phrase): bool
    {
        $position = mb_strpos($text, $phrase);

        if ($position === false) {
            return false;
        }

        $preceding = mb_substr($text, max(0, $position - 40), min(40, $position));

        /* A refusal only applies inside its own clause: "no museums. somewhere a
           local would go" must not negate the second half of the sentence. */
        $boundary = max(
            (int) mb_strrpos(' ' . $preceding, '.'),
            (int) mb_strrpos(' ' . $preceding, ','),
            (int) mb_strrpos(' ' . $preceding, ';'),
        );

        if ($boundary > 0) {
            $preceding = mb_substr($preceding, $boundary);
        }

        foreach (["don't want", 'do not want', 'dont want', 'avoid', 'no more', 'not another',
            'rather not', 'sick of', 'tired of', 'anything but', 'except', 'without'] as $negation) {
            if (Str::contains($preceding, $negation)) {
                return true;
            }
        }

        return false;
    }

    private function mentions(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (Str::contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
