<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

/**
 * Stops the guide stating a live fact it was not given (spec s15.1, acceptance 61).
 *
 * Prices, clock times and durations may only appear in an answer if the same
 * number came back from a tool call. Anything else is removed from the reply and
 * reported, rather than being shown to a traveller who might act on it.
 */
class GroundingGuard
{
    /**
     * @param  array<string,mixed>  $facts  tool results the model was given
     * @return array{text:string, violations:list<string>, grounded:bool}
     */
    public function verify(string $reply, array $facts): array
    {
        $allowed = $this->numericTokens($facts);
        $patterns = (array) config('experience.assistant.guarded_fact_patterns');

        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($reply)) ?: [];
        $kept = [];
        $violations = [];

        foreach ($sentences as $sentence) {
            $unsupported = null;

            foreach ($patterns as $pattern) {
                if (! preg_match_all($pattern, $sentence, $matches)) {
                    continue;
                }

                foreach ($matches[0] as $match) {
                    foreach ($this->tokenise($match) as $token) {
                        if (! isset($allowed[$token])) {
                            $unsupported = trim($match);
                            break 3;
                        }
                    }
                }
            }

            if ($unsupported === null) {
                $kept[] = $sentence;
            } else {
                $violations[] = $unsupported;
            }
        }

        $text = trim(implode(' ', $kept));

        if ($violations !== [] && $text === '') {
            $text = 'I could not verify the details for that, so I would rather not guess. Open the experience page and I will show you what the supplier reports.';
        }

        return [
            'text' => $text,
            'violations' => $violations,
            'grounded' => $violations === [],
        ];
    }

    /** Every number the tools actually returned, in the forms a reply might use. */
    private function numericTokens(array $facts): array
    {
        $json = json_encode($facts, JSON_THROW_ON_ERROR);
        $tokens = [];

        preg_match_all('/\d+(?:\.\d+)?/', $json, $matches);

        foreach ($matches[0] as $number) {
            $this->addToken($tokens, $number);

            if (str_contains($number, '.')) {
                continue;
            }

            $int = (int) $number;

            /* Minor units also authorise the major-unit rendering: 3500 -> 35, 35.00 */
            if ($int >= 100 && $int % 100 === 0) {
                $this->addToken($tokens, (string) intdiv($int, 100));
                $this->addToken($tokens, number_format($int / 100, 2, '.', ''));
            } elseif ($int >= 100) {
                $this->addToken($tokens, number_format($int / 100, 2, '.', ''));
                $this->addToken($tokens, (string) intdiv($int, 100));
            }

            /* Minutes also authorise the hour rendering: 120 -> 2 */
            if ($int > 0 && $int % 60 === 0) {
                $this->addToken($tokens, (string) intdiv($int, 60));
            }
        }

        /* Clock times inside strings: "09:30" also authorises "9:30" and "9". */
        preg_match_all('/(\d{1,2}):(\d{2})/', $json, $times, PREG_SET_ORDER);
        foreach ($times as [$whole, $hour, $minute]) {
            $this->addToken($tokens, $whole);
            $this->addToken($tokens, ltrim($hour, '0') . ':' . $minute);
            $this->addToken($tokens, ltrim($hour, '0') ?: '0');
            $hour12 = ((int) $hour % 12) ?: 12;
            $this->addToken($tokens, (string) $hour12);
            $this->addToken($tokens, $hour12 . ':' . $minute);
        }

        return $tokens;
    }

    /** @return list<string> */
    private function tokenise(string $match): array
    {
        $normalised = str_replace(',', '', trim($match));

        if (preg_match('/(\d{1,2})[:.](\d{2})/', $normalised, $m)) {
            return [ltrim($m[1], '0') . ':' . $m[2]];
        }

        preg_match_all('/\d+(?:\.\d+)?/', $normalised, $numbers);

        return array_map(
            fn (string $n) => rtrim(rtrim($n, '0'), '.') === '' ? $n : (str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n),
            $numbers[0],
        );
    }

    private function addToken(array &$tokens, string $token): void
    {
        $token = str_contains($token, '.') ? rtrim(rtrim($token, '0'), '.') : $token;
        $tokens[$token] = true;
        $tokens[ltrim($token, '0') ?: '0'] = true;
    }
}
