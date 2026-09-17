<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Support;

/**
 * Parser for the OpenStreetMap `opening_hours` tag.
 *
 * The full grammar is enormous and supports things no traveller-facing product
 * can act on ("Mo-Fr 09:00-17:00 open \"ring the bell\""). This parser handles
 * the common, unambiguous subset and returns null for everything else.
 *
 * That failure mode is deliberate. Downstream, null means "hours not verified",
 * which is rendered as such and never scored as open. A half-understood
 * schedule is worse than an admitted gap: it would send someone across a city
 * to a locked door.
 */
final class OpeningHoursParser
{
    private const DAYS = ['mo' => 'mon', 'tu' => 'tue', 'we' => 'wed', 'th' => 'thu', 'fr' => 'fri', 'sa' => 'sat', 'su' => 'sun'];

    private const ORDER = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @return array{schedule: array<string, list<array{0:string,1:string}>>, confidence: float}|null
     */
    public static function parse(?string $value): ?array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalised = mb_strtolower($value);

        if ($normalised === '24/7') {
            return [
                'schedule' => array_fill_keys(self::ORDER, [['00:00', '23:59']]),
                'confidence' => 1.0,
            ];
        }

        /* Anything conditional, seasonal or annotated is beyond what we will
           claim to understand. */
        if (preg_match('/(ph|sh|easter|sunrise|sunset|week \d|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|"|\[)/i', $normalised)) {
            return null;
        }

        $schedule = [];
        $sawRule = false;

        foreach (preg_split('/\s*;\s*/', $normalised) as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            $parsed = self::parseRule($rule);

            if ($parsed === null) {
                return null;   // one unreadable rule invalidates the whole tag
            }

            $sawRule = true;

            foreach ($parsed['days'] as $day) {
                $schedule[$day] = $parsed['ranges'];
            }
        }

        if (! $sawRule) {
            return null;
        }

        /* Days the tag never mentions are unknown rather than closed, so they
           are simply absent: OpeningHours treats a missing key as "we do not
           know" and a present empty array as "closed". */
        return ['schedule' => $schedule, 'confidence' => count($schedule) >= 7 ? 1.0 : 0.8];
    }

    /**
     * @return array{days: list<string>, ranges: list<array{0:string,1:string}>}|null
     */
    private static function parseRule(string $rule): ?array
    {
        /* "mo-fr 09:00-17:00,18:00-20:00" or "su off" or bare "09:00-17:00". */
        if (! preg_match('/^(?<days>(?:[a-z]{2}(?:-[a-z]{2})?)(?:\s*,\s*[a-z]{2}(?:-[a-z]{2})?)*)?\s*(?<times>.*)$/', $rule, $m)) {
            return null;
        }

        $days = trim($m['days'] ?? '') === '' ? self::ORDER : self::expandDays($m['days']);

        if ($days === null) {
            return null;
        }

        $times = trim($m['times']);

        if ($times === 'off' || $times === 'closed') {
            return ['days' => $days, 'ranges' => []];
        }

        $ranges = [];

        foreach (preg_split('/\s*,\s*/', $times) as $span) {
            if (! preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', trim($span), $t)) {
                return null;
            }

            $open = sprintf('%02d:%02d', (int) $t[1], (int) $t[2]);
            $close = sprintf('%02d:%02d', min(23, (int) $t[3]), (int) $t[4]);

            if ((int) $t[3] >= 24) {
                $close = '23:59';
            }

            $ranges[] = [$open, $close];
        }

        return $ranges === [] ? null : ['days' => $days, 'ranges' => $ranges];
    }

    /** @return list<string>|null */
    private static function expandDays(string $spec): ?array
    {
        $days = [];

        foreach (preg_split('/\s*,\s*/', trim($spec)) as $part) {
            if (preg_match('/^([a-z]{2})-([a-z]{2})$/', $part, $m)) {
                $from = self::DAYS[$m[1]] ?? null;
                $to = self::DAYS[$m[2]] ?? null;

                if ($from === null || $to === null) {
                    return null;
                }

                $start = array_search($from, self::ORDER, true);
                $end = array_search($to, self::ORDER, true);

                for ($i = $start; ; $i = ($i + 1) % 7) {
                    $days[] = self::ORDER[$i];
                    if ($i === $end) {
                        break;
                    }
                }

                continue;
            }

            $day = self::DAYS[$part] ?? null;

            if ($day === null) {
                return null;
            }

            $days[] = $day;
        }

        return array_values(array_unique($days));
    }
}
