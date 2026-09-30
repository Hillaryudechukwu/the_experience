<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Searching by name.
 *
 * The query was only ever a hard filter, and everything through it was ranked
 * purely on fit for this traveller right now. That is right for "something
 * romantic tonight" and wrong for "Borough Market": the named place came back
 * ranked below whatever happened to suit the hour, which reads as the search
 * being broken.
 */
class SearchRelevanceTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
    }

    public function test_a_place_searched_by_name_comes_first(): void
    {
        $titles = $this->search('borough market');

        $this->assertNotEmpty($titles);
        $this->assertSame('Borough Market', $titles[0]);
    }

    public function test_it_is_not_confused_by_case_or_spacing(): void
    {
        $this->assertSame('Tower of London', $this->search('  TOWER OF LONDON ')[0]);
    }

    /** A partial name still counts, so it works while the traveller is typing. */
    public function test_a_partial_name_promotes_the_match(): void
    {
        $titles = $this->search('british mus');

        $this->assertNotEmpty($titles);
        $this->assertSame('British Museum', $titles[0]);
    }

    /**
     * A query that names nothing must not be reordered at all.
     *
     * Every result then sits in the same relevance group, so the Experience
     * Score is still the only thing deciding — which is the whole product. If
     * the promotion ever leaked into descriptive queries, the scores would
     * stop descending and this would say so.
     */
    public function test_a_descriptive_query_is_left_to_the_score(): void
    {
        $scores = array_column(
            $this->postJson('/api/discovery/search', [
                'destination' => 'london',
                'q' => 'something romantic tonight',
            ], ['X-Guest-Token' => $this->token])->assertOk()->json('data'),
            'experience_score',
        );

        $this->assertNotEmpty($scores);

        $sorted = $scores;
        rsort($sorted);

        $this->assertSame($sorted, $scores, 'Search results stopped being ordered by score.');
    }

    /** @return list<string> */
    private function search(string $query): array
    {
        return array_column(
            $this->postJson('/api/discovery/search', [
                'destination' => 'london',
                'q' => $query,
            ], ['X-Guest-Token' => $this->token])->assertOk()->json('data'),
            'title',
        );
    }
}
