<?php

declare(strict_types=1);

namespace App\Domains\Places\Support;

/**
 * pg_trgm's similarity(), in PHP.
 *
 * Two places are "the same place" only if both code paths that ask agree, so
 * this lives in one place and both use it.
 *
 * It used to be similar_text() at each call site, and that does not measure
 * the same thing: similar_text() rewards the longest common substring, so
 * "south street seaport museum" and "south street seaport historic district"
 * scored 0.677 — above both callers' 0.60 threshold — and two genuinely
 * different places were merged. PostgreSQL scores that pair 0.463, which falls
 * in the band where a human decides.
 *
 * Trigrams are built the way pg_trgm builds them: split on anything that is
 * not a letter or a number, pad each word with two leading and one trailing
 * space, and take the Jaccard index of the resulting sets.
 *
 * Letters means letters in any script. Splitting on /[^a-z0-9]+/ would cut
 * "café" into "caf", which normalisedName() takes care to avoid (it keeps
 * \p{L}) and which pg_trgm does not do either — and the divergence is not
 * academic: "café du marché" against "cafe du marche" scores 0.647 that way
 * and 0.579 this way, which is the difference between merging the two and
 * holding them for review.
 */
final class NameSimilarity
{
    public static function between(string $a, string $b): float
    {
        $left = self::trigrams($a);
        $right = self::trigrams($b);

        if ($left === [] || $right === []) {
            return 0.0;
        }

        $shared = count(array_intersect_key($left, $right));
        $union = count($left + $right);

        return $union === 0 ? 0.0 : round($shared / $union, 3);
    }

    /** @return array<string, true> */
    private static function trigrams(string $value): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $trigrams = [];

        foreach ($words as $word) {
            /* Characters, not bytes: a three-byte window over "café" would cut
               the accented letter in half and invent trigrams nothing matches. */
            $characters = mb_str_split('  '.$word.' ');
            $length = count($characters);

            for ($i = 0; $i + 3 <= $length; $i++) {
                $trigrams[implode('', array_slice($characters, $i, 3))] = true;
            }
        }

        return $trigrams;
    }
}
