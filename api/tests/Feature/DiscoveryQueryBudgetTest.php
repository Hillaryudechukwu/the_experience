<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A budget on the hottest endpoint's query count.
 *
 * Discovery wrote a row at a time: an insert per recommendation, another per
 * reason behind it — and the scorer produces a reason per contributing
 * component — plus an impression per card, a neighbourhood lookup per result
 * and a health check per brokered call. Four cards cost sixty-six queries and
 * twelve cost well over a hundred, because almost all of it scaled with the
 * result count.
 *
 * The number below is a ceiling, not a target. It exists so that reintroducing
 * a per-row write is noticed here rather than in production, where it looks
 * like the database being slow.
 */
class DiscoveryQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const BUDGET = 45;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_full_page_of_results_stays_within_the_query_budget(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        DB::enableQueryLog();

        $response = $this->postJson('/api/discovery/now', [
            'destination' => 'london',
            'limit' => 12,
        ], ['X-Guest-Token' => $token]);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));

        $this->assertLessThanOrEqual(
            self::BUDGET,
            $queries,
            "A twelve-result discovery response took {$queries} queries. Something is writing or "
                . 'reading a row at a time again.',
        );
    }

    /**
     * The point is that it does not scale with the results.
     *
     * A budget alone would pass while a per-row write crept back in under a
     * smaller page, so the shape is asserted too: tripling the results must
     * not triple the queries.
     */
    public function test_the_query_count_barely_moves_with_the_result_count(): void
    {
        $token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        $count = function (int $limit) use ($token): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->postJson('/api/discovery/now', ['destination' => 'london', 'limit' => $limit], [
                'X-Guest-Token' => $token,
            ])->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $few = $count(4);
        $many = $count(12);

        $this->assertLessThan(
            $few * 2,
            $many,
            "Four results took {$few} queries and twelve took {$many}: the cost is still per-row.",
        );
    }
}
