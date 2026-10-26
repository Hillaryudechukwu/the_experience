<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Discovery\Models\LiveIntent;
use App\Domains\Discovery\Services\LiveIntentStore;
use App\Domains\Experiences\Models\Experience;
use App\Domains\ExternalSources\Models\ProviderProduct;
use App\Domains\Identity\Actions\AdoptGuestSession;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Passport\Models\SavedExperience;
use App\Domains\Shared\ValueObjects\Actor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a guest did has to survive the guest session that did it.
 *
 * journeys.guest_session_id and saved_experiences.guest_session_id are both
 * cascadeOnDelete. Adoption moved user_id across but left the guest id in
 * place, so the rows still died with that session — and the four other tables
 * adoption touches already cleared it, which is what makes the omission a
 * mistake rather than a choice.
 */
class GuestAdoptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_adopted_trips_and_saves_outlive_the_guest_session(): void
    {
        [$user, $guest] = $this->adopted();

        $guest->delete();

        $this->assertSame(1, Journey::where('user_id', $user->id)->count(), 'The trip died with the guest session.');
        $this->assertSame(1, SavedExperience::where('user_id', $user->id)->count(), 'The save died with the guest session.');
    }

    public function test_adoption_clears_the_guest_id_rather_than_leaving_it(): void
    {
        [$user] = $this->adopted();

        $this->assertNull(Journey::where('user_id', $user->id)->value('guest_session_id'));
        $this->assertNull(SavedExperience::where('user_id', $user->id)->value('guest_session_id'));
    }

    /**
     * Saving something, signing out, saving it again as the new guest, then
     * signing back in. The table is unique on (user_id, experience_id), so
     * without deduplication the move raises a constraint violation inside the
     * adoption transaction and the sign-in itself fails.
     */
    public function test_a_save_the_account_already_holds_does_not_break_sign_in(): void
    {
        $user = User::factory()->create();
        $experience = Experience::query()->where('status', 'published')->firstOrFail();
        $guest = $this->guest();

        SavedExperience::query()->create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'experience_id' => $experience->id,
        ]);
        SavedExperience::query()->create([
            'id' => (string) Str::uuid7(),
            'guest_session_id' => $guest->id,
            'experience_id' => $experience->id,
        ]);

        app(AdoptGuestSession::class)->run($this->requestFor($guest), $user);

        $this->assertSame(1, SavedExperience::where('user_id', $user->id)->count());
        $this->assertSame(0, SavedExperience::whereNotNull('guest_session_id')->count());
    }

    /**
     * A live intent has to remain findable after adoption.
     *
     * owner_key is derived on save and the lookup goes by it, so moving the
     * row with a mass update would leave the guest key in place: the intent
     * would survive the sign-in and be invisible from that moment on.
     */
    public function test_an_adopted_live_intent_is_still_found_afterwards(): void
    {
        $user = User::factory()->create();
        $guest = $this->guest();

        LiveIntent::query()->create([
            'guest_session_id' => $guest->id,
            'mood' => 'romantic',
            'categories' => [],
            'exclude_categories' => [],
            'free_only' => false,
            'source' => 'discover',
            'expires_at' => now()->endOfDay(),
        ]);

        app(AdoptGuestSession::class)->run($this->requestFor($guest), $user);

        $merged = app(LiveIntentStore::class)
            ->mergeInto(new Actor(userId: $user->id), []);

        $this->assertSame('romantic', $merged['mood'] ?? null);
    }

    /**
     * The journey a guest builds and pays for has to be on the account.
     *
     * Adoption used to move the journey but not the trip hanging off it or the
     * bookings: the account saw its journey with no plan and an empty
     * Bookings tab, while the app said everything had come across.
     */
    public function test_registering_keeps_the_guests_plan_and_bookings(): void
    {
        $guestToken = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
        $guest = ['X-Guest-Token' => $guestToken];
        $destinationId = $this->getJson('/api/destinations?q=london')->json('data.0.id');

        $journeyId = $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'holiday',
            'starts_on' => CarbonImmutable::now()->addDays(3)->toDateString(),
            'ends_on' => CarbonImmutable::now()->addDays(5)->toDateString(),
        ], $guest)->assertCreated()->json('data.id');
        $tripId = $this->postJson('/api/trips', ['journey_id' => $journeyId], $guest)->assertCreated()->json('data.id');
        $this->postJson("/api/trips/{$tripId}/generate-itinerary", [], $guest)->assertCreated();

        $product = ProviderProduct::where('provider', 'sandbox')->firstOrFail();
        $reference = $this->postJson('/api/bookings', [
            'provider' => 'sandbox',
            'provider_product_id' => $product->provider_product_id,
            'quantity' => 1,
            'starts_at' => CarbonImmutable::now()->addDays(3)->setTime(11, 30)->toIso8601String(),
        ], $guest + ['Idempotency-Key' => 'adoption-booking'])->assertCreated()->json('data.reference');

        $token = $this->postJson('/api/auth/register', [
            'name' => 'Alex Traveller',
            'email' => 'alex@example.com',
            'password' => 'correct-horse',
        ], $guest)->assertCreated()->json('token');

        $account = ['Authorization' => 'Bearer '.$token];

        $this->assertSame($tripId, $this->getJson('/api/journeys/current', $account)->json('trip_id'));
        $this->assertNotEmpty($this->getJson("/api/trips/{$tripId}", $account)->assertOk()->json('data.itinerary.days'));
        $this->assertContains($reference, array_column($this->getJson('/api/bookings', $account)->json('data'), 'reference'));
    }

    /** @return array{0: User, 1: GuestSession} */
    private function adopted(): array
    {
        $user = User::factory()->create();
        $guest = $this->guest();
        $experience = Experience::query()->where('status', 'published')->firstOrFail();

        Journey::query()->create([
            'guest_session_id' => $guest->id,
            'destination_id' => $experience->destination_id,
            'reason' => 'holiday',
        ]);
        SavedExperience::query()->create([
            'id' => (string) Str::uuid7(),
            'guest_session_id' => $guest->id,
            'experience_id' => $experience->id,
        ]);

        app(AdoptGuestSession::class)->run($this->requestFor($guest), $user);

        return [$user, $guest->fresh()];
    }

    private function guest(): GuestSession
    {
        return GuestSession::query()->create([
            'id' => (string) Str::uuid7(),
            'token' => Str::random(48),
        ]);
    }

    private function requestFor(GuestSession $guest): Request
    {
        $request = Request::create('/api/auth/google', 'POST');
        $request->headers->set('X-Guest-Token', $guest->token);

        return $request;
    }
}
