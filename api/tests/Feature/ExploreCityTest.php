<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Discovery\Services\EventFinder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/**
 * Explore my city: what is on and what is always good, wherever the phone is.
 */
class ExploreCityTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    private Destination $destination;

    protected function setUp(): void
    {
        parent::setUp();

        /* A Wednesday afternoon in London. */
        $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00', 'Europe/London'));

        config([
            'experience.place_data.osm.nominatim_url' => 'https://nominatim.test',
            'experience.providers.ticketmaster.base_url' => 'https://tm.test/discovery/v2',
        ]);

        $this->destination = $this->destination();
        $this->experience($this->destination, [
            'slug' => 'gallery',
            'title' => 'Riverside gallery',
            'lat' => 51.5080, 'lng' => -0.1280,
        ]);
    }

    public function test_a_covered_city_returns_its_experiences_and_whats_on(): void
    {
        config(['experience.providers.ticketmaster.api_key' => 'tm-key']);
        Http::preventStrayRequests();
        Http::fake(['tm.test/*' => Http::response($this->listings())]);

        $response = $this->explore();

        $response->assertOk()
            ->assertJsonPath('data.city.destination_id', $this->destination->id)
            ->assertJsonPath('data.candidate', null)
            ->assertJsonPath('data.preparing', null)
            ->assertJsonPath('data.events_available', true)
            ->assertJsonPath('data.sections.top_picks.0.title', 'Riverside gallery');

        /* The gig is listed three times (two tiers and a VIP package) and
           shows once; the cancelled match does not show at all. */
        $this->assertSame(['Evening gig', 'Late comedy'], array_column($response->json('data.events'), 'title'));
        $this->assertSame(2500, $response->json('data.events.0.price_from_minor'));
        $this->assertSame('music', $response->json('data.events.0.category'));
        $this->assertNotNull($response->json('data.events.0.distance_metres'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'latlong=51.5074%2C-0.1278')
            && $request['apikey'] === 'tm-key');
    }

    public function test_without_a_ticketmaster_key_it_shows_experiences_and_asks_no_one(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->explore()
            ->assertOk()
            ->assertJsonPath('data.events', [])
            ->assertJsonPath('data.events_available', false)
            ->assertJsonPath('data.sections.top_picks.0.title', 'Riverside gallery');

        Http::assertNothingSent();
    }

    public function test_a_failing_events_provider_does_not_take_the_screen_down(): void
    {
        config(['experience.providers.ticketmaster.api_key' => 'tm-key']);
        Http::fake(['tm.test/*' => Http::response('Server error', 500)]);

        $this->explore()
            ->assertOk()
            ->assertJsonPath('data.events', [])
            ->assertJsonPath('data.sections.top_picks.0.title', 'Riverside gallery');
    }

    public function test_an_uncovered_city_is_offered_for_activation_with_events_still_shown(): void
    {
        config(['experience.providers.ticketmaster.api_key' => 'tm-key']);
        Http::fake([
            'nominatim.test/reverse*' => Http::response([
                'osm_type' => 'relation',
                'osm_id' => 112233,
                'lat' => '53.4808',
                'lon' => '-2.2426',
                'addresstype' => 'city',
                'address' => ['city' => 'Manchester', 'state' => 'England', 'country' => 'United Kingdom', 'country_code' => 'gb'],
            ]),
            'tm.test/*' => Http::response($this->listings()),
        ]);

        $response = $this->explore(['lat' => 53.4808, 'lng' => -2.2426]);

        $response->assertOk()
            ->assertJsonPath('data.city', null)
            ->assertJsonPath('data.sections', null)
            ->assertJsonPath('data.candidate.name', 'Manchester')
            ->assertJsonPath('data.candidate.coverage_status', 'discoverable');
        $this->assertNotEmpty($response->json('data.candidate.candidate_token'));
        $this->assertCount(2, $response->json('data.events'));
    }

    public function test_somewhere_that_is_not_a_city_gets_no_candidate(): void
    {
        Http::fake([
            'nominatim.test/reverse*' => Http::response(['error' => 'Unable to geocode']),
        ]);

        $this->explore(['lat' => 0.0, 'lng' => -30.0])
            ->assertOk()
            ->assertJsonPath('data.city', null)
            ->assertJsonPath('data.candidate', null);
    }

    public function test_a_place_shows_in_one_row_only(): void
    {
        /* The gallery is free, so the free row would repeat it. */
        $this->experience($this->destination, [
            'slug' => 'park',
            'title' => 'Pocket park',
            'lat' => 51.5076, 'lng' => -0.1276,
        ]);
        config(['experience.explore_city.section_size' => 1]);

        $sections = $this->explore()->assertOk()->json('data.sections');

        $ids = array_merge(
            array_column($sections['top_picks'], 'id'),
            array_column($sections['hidden_gems'], 'id'),
            array_column($sections['free'], 'id'),
            ...array_map(fn (array $area) => array_column($area['items'], 'id'), $sections['by_neighbourhood']),
        );
        $this->assertCount(1, $sections['top_picks']);
        $this->assertCount(1, $sections['free']);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_coordinates_are_required(): void
    {
        $this->postJson('/api/discovery/explore-city', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng']);
    }

    public function test_it_is_behind_the_paywall_and_spends_nothing_when_refused(): void
    {
        config([
            'experience.billing.enforced' => true,
            'experience.providers.ticketmaster.api_key' => 'tm-key',
        ]);
        Http::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->explore()->assertStatus(402);

        Http::assertNothingSent();
    }

    public function test_a_weekday_weekend_runs_from_friday_evening_to_sunday_night(): void
    {
        $finder = app(EventFinder::class);
        $wednesday = CarbonImmutable::parse('2026-11-04 15:00', 'Europe/London');

        [$from, $to] = $finder->window('weekend', $wednesday);
        $this->assertSame('2026-11-06 17:00', $from->format('Y-m-d H:i'));
        $this->assertSame('2026-11-08 23:59', $to->format('Y-m-d H:i'));

        /* Saturday: the weekend has started, so it runs from now. */
        [$from, $to] = $finder->window('weekend', CarbonImmutable::parse('2026-11-07 11:00', 'Europe/London'));
        $this->assertSame('2026-11-07 11:00', $from->format('Y-m-d H:i'));
        $this->assertSame('2026-11-08 23:59', $to->format('Y-m-d H:i'));
    }

    private function explore(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/discovery/explore-city', array_merge([
            'lat' => 51.5074,
            'lng' => -0.1278,
        ], $body));
    }

    /** @return array<string, mixed> */
    private function listings(): array
    {
        $venue = [['name' => 'Hall', 'location' => ['latitude' => '51.5100', 'longitude' => '-0.1300']]];
        $gig = fn (string $id, float $min) => [
            'id' => $id,
            'name' => 'Evening gig',
            'url' => "https://tm.test/event/{$id}",
            'dates' => ['start' => ['dateTime' => '2026-11-04T19:30:00Z'], 'status' => ['code' => 'onsale']],
            'classifications' => [['segment' => ['name' => 'Music'], 'genre' => ['name' => 'Rock']]],
            'priceRanges' => [['min' => $min, 'max' => 60.0, 'currency' => 'GBP']],
            'images' => [['url' => 'https://img.test/a.jpg', 'ratio' => '16_9', 'width' => 1024]],
            '_embedded' => ['venues' => $venue],
        ];

        return ['_embedded' => ['events' => [
            $gig('g1', 25.0),
            $gig('g2', 40.0),
            $gig('g3-vip', 120.0),
            [
                'id' => 'm1',
                'name' => 'Cup match',
                'dates' => ['start' => ['dateTime' => '2026-11-04T20:00:00Z'], 'status' => ['code' => 'cancelled']],
                '_embedded' => ['venues' => $venue],
            ],
            [
                'id' => 'c1',
                'name' => 'Late comedy',
                'url' => 'https://tm.test/event/c1',
                'dates' => ['start' => ['dateTime' => '2026-11-04T21:30:00Z'], 'status' => ['code' => 'onsale']],
                'classifications' => [['segment' => ['name' => 'Arts & Theatre'], 'genre' => ['name' => 'Comedy']]],
                '_embedded' => ['venues' => $venue],
            ],
        ]]];
    }
}
