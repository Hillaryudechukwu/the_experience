<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Discovery\Models\LiveIntent;
use App\Domains\Discovery\Services\LiveIntentStore;
use App\Domains\Shared\ValueObjects\Actor;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "One row per actor and journey" is now enforced, not merely documented.
 *
 * Two requests arriving together — Discover does this whenever a mood is
 * tapped while the first fetch is still in flight — both found no row and both
 * inserted. The remembered mood then alternated between them depending on
 * which the database happened to return first, and clearing it only ever
 * cleared one.
 */
class LiveIntentUniquenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['experience.scoring.use_live_intent' => true]);
    }

    public function test_a_second_write_updates_the_row_rather_than_adding_one(): void
    {
        $actor = new Actor(userId: User::factory()->create()->id);
        $store = app(LiveIntentStore::class);

        $store->upsert($actor, [], ['mood' => 'romantic'], 'discover');
        $store->upsert($actor, [], ['mood' => 'hungry'], 'discover');

        $this->assertSame(1, LiveIntent::query()->count());
        $this->assertSame('hungry', LiveIntent::query()->value('mood'));
    }

    /**
     * The race itself: a row appears between the store's read and its write,
     * exactly as a concurrent request would cause. The insert must lose
     * gracefully and update the winner rather than throw.
     */
    public function test_losing_the_insert_race_updates_the_winning_row(): void
    {
        $user = User::factory()->create();
        $actor = new Actor(userId: $user->id);
        $store = app(LiveIntentStore::class);

        /* The row the "other request" already wrote, under the same key. */
        LiveIntent::query()->create([
            'owner_key' => LiveIntent::ownerKeyFor($user->id, null, null),
            'user_id' => $user->id,
            'mood' => 'relaxed',
            'categories' => [],
            'exclude_categories' => [],
            'free_only' => false,
            'source' => 'discover',
            'expires_at' => now()->endOfDay(),
        ]);

        $store->upsert($actor, [], ['mood' => 'adventurous'], 'discover');

        $this->assertSame(1, LiveIntent::query()->count());
        $this->assertSame('adventurous', LiveIntent::query()->value('mood'));
    }

    /** The database refuses a duplicate outright, whatever the code does. */
    public function test_the_database_refuses_a_second_row_for_the_same_owner(): void
    {
        $user = User::factory()->create();
        $attributes = [
            'owner_key' => LiveIntent::ownerKeyFor($user->id, null, null),
            'user_id' => $user->id,
            'categories' => [],
            'exclude_categories' => [],
            'free_only' => false,
            'source' => 'discover',
            'expires_at' => now()->endOfDay(),
        ];

        LiveIntent::query()->create($attributes);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        LiveIntent::query()->create($attributes);
    }

    /** Separate travellers, and separate journeys, remain separate. */
    public function test_different_owners_keep_their_own_row(): void
    {
        $store = app(LiveIntentStore::class);
        $one = new Actor(userId: User::factory()->create()->id);
        $two = new Actor(userId: User::factory()->create()->id);

        $store->upsert($one, [], ['mood' => 'romantic'], 'discover');
        $store->upsert($two, [], ['mood' => 'hungry'], 'discover');

        $this->assertSame(2, LiveIntent::query()->count());
    }
}
