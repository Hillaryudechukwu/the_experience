<?php

declare(strict_types=1);

namespace App\Domains\Discovery\Services;

/**
 * When someone types a name, put that place first.
 *
 * The search query is a hard filter and nothing more: everything through it is
 * then ranked purely on fit for this traveller right now. That is correct for
 * "something romantic tonight" and wrong for "Borough Market" — a search for a
 * specific place answered with a different place above it reads as the search
 * being broken. Someone who has typed a name is not asking to be advised, they
 * are asking to be taken there.
 *
 * This is deliberately not a tenth scoring weight. The weights must sum to one
 * and the scorer asserts it at boot, so adding a component would have
 * rebalanced Discover, mood and time-boxed — none of which have a query at
 * all. Relevance belongs to the one surface that has one.
 *
 * Its own class rather than a private method on the controller because the
 * interesting case is a list whose name order and score order disagree, and
 * through the endpoint that depends on the seed data and the hour. Here it can
 * simply be stated.
 */
class NameRelevance
{
    private const EXACT = 0;

    private const PREFIX = 1;

    private const CONTAINS = 2;

    private const ELSEWHERE = 3;

    /**
     * @param  list<array<string, mixed>>  $cards  ranked by Experience Score
     * @return list<array<string, mixed>>
     */
    public function promote(array $cards, ?string $query): array
    {
        $needle = mb_strtolower(trim((string) $query));

        if ($needle === '' || $cards === []) {
            return $cards;
        }

        /*
         * Stable: usort is not, so the original position is carried into the
         * comparison. Without it, everything the query does not name — which
         * is most of the list — would be reshuffled arbitrarily and the
         * Experience Score order would be lost.
         */
        $indexed = [];
        foreach ($cards as $position => $card) {
            $indexed[] = [
                'card' => $card,
                'rank' => $this->rank((string) ($card['title'] ?? ''), $needle),
                'position' => $position,
            ];
        }

        usort($indexed, fn (array $a, array $b) => [$a['rank'], $a['position']] <=> [$b['rank'], $b['position']]);

        return array_column($indexed, 'card');
    }

    private function rank(string $title, string $needle): int
    {
        $title = mb_strtolower($title);

        if ($title === $needle) {
            return self::EXACT;
        }

        if (str_starts_with($title, $needle)) {
            return self::PREFIX;
        }

        if (str_contains($title, $needle)) {
            return self::CONTAINS;
        }

        /* Matched on the summary or the editorial copy, not the name. */
        return self::ELSEWHERE;
    }
}
