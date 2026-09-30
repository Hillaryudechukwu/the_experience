<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Discovery\Services\NameRelevance;
use PHPUnit\Framework\TestCase;

/**
 * The interesting case is a list whose name order and score order disagree,
 * and through the endpoint that depends on the seed data and the hour — the
 * first version of these tests passed with the feature removed, because the
 * seeded catalogue happened to rank the named place first anyway. Stated
 * directly, it cannot be vacuous.
 */
class NameRelevanceTest extends TestCase
{
    private NameRelevance $relevance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relevance = new NameRelevance;
    }

    public function test_it_lifts_the_named_place_over_a_better_scoring_one(): void
    {
        $ordered = $this->relevance->promote([
            ['title' => 'Soho after dark', 'experience_score' => 69],
            ['title' => 'Borough Market', 'experience_score' => 52],
        ], 'borough market');

        $this->assertSame('Borough Market', $ordered[0]['title']);
    }

    public function test_an_exact_name_beats_a_place_that_merely_starts_with_it(): void
    {
        $ordered = $this->relevance->promote([
            ['title' => 'Borough Market Kitchen', 'experience_score' => 80],
            ['title' => 'Borough Market', 'experience_score' => 40],
        ], 'borough market');

        $this->assertSame(['Borough Market', 'Borough Market Kitchen'], array_column($ordered, 'title'));
    }

    public function test_case_and_surrounding_space_do_not_matter(): void
    {
        $ordered = $this->relevance->promote([
            ['title' => 'Sky Garden', 'experience_score' => 90],
            ['title' => 'Tower of London', 'experience_score' => 30],
        ], '  TOWER OF LONDON  ');

        $this->assertSame('Tower of London', $ordered[0]['title']);
    }

    /** So it is useful while the traveller is still typing. */
    public function test_a_partial_name_is_enough(): void
    {
        $ordered = $this->relevance->promote([
            ['title' => 'Sky Garden', 'experience_score' => 90],
            ['title' => 'British Museum', 'experience_score' => 30],
        ], 'british mus');

        $this->assertSame('British Museum', $ordered[0]['title']);
    }

    /**
     * The one that matters most: a sentence names nothing, so every card sits
     * in the same group and the Experience Score order must survive intact.
     */
    public function test_a_query_that_names_nothing_reorders_nothing(): void
    {
        $cards = [
            ['title' => 'Sky Garden', 'experience_score' => 90],
            ['title' => 'Little Venice walk', 'experience_score' => 72],
            ['title' => 'Borough Market', 'experience_score' => 51],
        ];

        $this->assertSame($cards, $this->relevance->promote($cards, 'something romantic tonight'));
    }

    public function test_an_empty_query_reorders_nothing(): void
    {
        $cards = [['title' => 'B', 'experience_score' => 2], ['title' => 'A', 'experience_score' => 1]];

        $this->assertSame($cards, $this->relevance->promote($cards, ''));
        $this->assertSame($cards, $this->relevance->promote($cards, null));
    }

    /** A title match anywhere still beats one found only in the description. */
    public function test_a_title_match_outranks_a_description_match(): void
    {
        $ordered = $this->relevance->promote([
            ['title' => 'Churchill War Rooms', 'experience_score' => 88],   // "museum" in its summary
            ['title' => 'Charles Dickens Museum', 'experience_score' => 41],
        ], 'museum');

        $this->assertSame('Charles Dickens Museum', $ordered[0]['title']);
    }
}
