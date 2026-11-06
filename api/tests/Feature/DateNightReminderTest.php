<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Experiences\Models\Experience;
use App\Domains\Identity\Models\GuestSession;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Notifications\Models\NotificationOutbox;
use App\Domains\Shared\ValueObjects\Actor;
use App\Domains\Trips\Models\Itinerary;
use App\Domains\Trips\Models\ItineraryItem;
use App\Domains\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsTravelScenarios;
use Tests\TestCase;

/**
 * Saving a date night puts every stop in the trip at once and leaves one
 * reminder for the day, which goes away only when the whole evening does.
 */
class DateNightReminderTest extends TestCase
{
    use BuildsTravelScenarios, RefreshDatabase;

    private Destination $destination;

    private Journey $journey;

    private Itinerary $itinerary;

    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, Experience> */
    private array $places;

    protected function setUp(): void
    {
        parent::setUp();

        /* A Monday afternoon in London. */
        $this->travelTo(CarbonImmutable::parse('2026-11-02 14:00', 'Europe/London'));

        $this->destination = $this->destination();
        $guest = GuestSession::create(['token' => Str::random(40)]);
        $actor = new Actor(guestSessionId: $guest->id);
        $this->headers = ['X-Guest-Token' => $guest->token];

        $this->journey = $this->journey($this->destination, $actor, [
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-10',
        ]);
        $trip = Trip::create(array_merge($actor->ownerAttributes(), [
            'journey_id' => $this->journey->id,
            'title' => 'London',
        ]));
        $this->itinerary = Itinerary::create([
            'trip_id' => $trip->id,
            'version' => 1,
            'generated_at' => CarbonImmutable::now(),
            'engine_version' => 'test',
            'is_current' => true,
        ]);

        $this->places = [
            'opener' => $this->experience($this->destination, ['slug' => 'viewpoint', 'title' => 'Sunset viewpoint']),
            'dinner' => $this->experience($this->destination, ['slug' => 'bistro', 'title' => 'Candlelit bistro']),
            'nightcap' => $this->experience($this->destination, ['slug' => 'wine-bar', 'title' => 'Wine bar']),
        ];
    }

    public function test_saving_adds_every_stop_and_reminds_on_the_afternoon_of_the_day(): void
    {
        $response = $this->save('2026-11-07', [
            'opener' => ['19:00', '20:00'],
            'dinner' => ['20:15', '22:00'],
            'nightcap' => ['22:10', '23:00'],
        ])->assertCreated();

        $this->assertCount(3, $response->json('data.items'));
        $this->assertSame(3, ItineraryItem::query()->where('reason', 'Your date night')->count());

        /* The planner's own length for each stop, not the experience's usual one. */
        $dinner = ItineraryItem::query()->where('title', 'Candlelit bistro')->sole();
        $this->assertTrue($dinner->ends_at->equalTo(CarbonImmutable::parse('2026-11-07 22:00', 'Europe/London')));

        $reminder = NotificationOutbox::query()->where('kind', 'date_night_reminder')->sole();
        $this->assertTrue($reminder->scheduled_for->equalTo(CarbonImmutable::parse('2026-11-07 15:00', 'Europe/London')));
        $this->assertStringContainsString('19:00 with Sunset viewpoint', $reminder->body);
        $this->assertStringContainsString('dinner at Candlelit bistro at 20:15', $reminder->body);
        $this->assertSame($this->itinerary->id, $reminder->payload['itinerary_id']);
        $this->assertNotNull($response->json('data.reminder_at'));
    }

    public function test_an_early_evening_is_reminded_two_hours_before_it_starts(): void
    {
        $this->save('2026-11-07', ['opener' => ['16:30', '17:30'], 'dinner' => ['18:00', '19:30']])->assertCreated();

        $reminder = NotificationOutbox::query()->where('kind', 'date_night_reminder')->sole();
        $this->assertTrue($reminder->scheduled_for->equalTo(CarbonImmutable::parse('2026-11-07 14:30', 'Europe/London')));
    }

    public function test_an_evening_saved_after_its_reminder_time_gets_none(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 17:30', 'Europe/London'));

        $response = $this->save('2026-11-02', ['dinner' => ['19:00', '20:30']])->assertCreated();

        $this->assertNull($response->json('data.reminder_at'));
        $this->assertSame(0, NotificationOutbox::query()->where('kind', 'date_night_reminder')->count());
    }

    public function test_a_clash_on_one_stop_saves_none_of_them(): void
    {
        $this->journey->anchors()->create([
            'type' => 'restaurant',
            'title' => 'Team dinner',
            'starts_at' => CarbonImmutable::parse('2026-11-07 22:30', 'Europe/London'),
            'ends_at' => CarbonImmutable::parse('2026-11-07 23:30', 'Europe/London'),
            'buffer_before_minutes' => 20,
        ]);

        $this->save('2026-11-07', [
            'opener' => ['19:00', '20:00'],
            'dinner' => ['20:15', '22:00'],
            'nightcap' => ['22:10', '23:00'],
        ])->assertStatus(422);

        $this->assertSame(0, ItineraryItem::query()->count());
        $this->assertSame(0, NotificationOutbox::query()->count());
    }

    public function test_the_reminder_stays_until_the_last_stop_is_removed(): void
    {
        $items = $this->save('2026-11-07', [
            'opener' => ['19:00', '20:00'],
            'dinner' => ['20:15', '22:00'],
            'nightcap' => ['22:10', '23:00'],
        ])->json('data.items');

        $this->deleteJson('/api/itinerary-items/'.$items[2]['id'], [], $this->headers)->assertOk();
        $this->assertSame('scheduled', NotificationOutbox::query()->where('kind', 'date_night_reminder')->sole()->status);

        $this->deleteJson('/api/itinerary-items/'.$items[0]['id'], [], $this->headers)->assertOk();
        $this->deleteJson('/api/itinerary-items/'.$items[1]['id'], [], $this->headers)->assertOk();
        $this->assertSame('cancelled', NotificationOutbox::query()->where('kind', 'date_night_reminder')->sole()->status);
    }

    public function test_stops_sent_out_of_order_still_remind_from_the_first_one(): void
    {
        $this->save('2026-11-07', ['dinner' => ['20:15', '22:00'], 'opener' => ['19:00', '20:00']])->assertCreated();

        $reminder = NotificationOutbox::query()->where('kind', 'date_night_reminder')->sole();
        $this->assertStringContainsString('starts at 19:00 with Sunset viewpoint', $reminder->body);
        $this->assertSame(
            ItineraryItem::query()->where('title', 'Sunset viewpoint')->sole()->id,
            $reminder->payload['item_ids'][0],
        );
    }

    public function test_an_evening_that_lands_on_one_already_saved_is_refused(): void
    {
        $this->save('2026-11-07', ['opener' => ['19:00', '20:00'], 'dinner' => ['20:15', '22:00']])->assertCreated();

        $this->save('2026-11-07', ['dinner' => ['21:00', '22:30']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That evening overlaps "Candlelit bistro", already in your trip from 20:15 to 22:00.');

        $this->assertSame(2, ItineraryItem::query()->count());
    }

    public function test_stops_that_overlap_each_other_are_refused(): void
    {
        $this->save('2026-11-07', ['opener' => ['19:00', '20:30'], 'dinner' => ['20:00', '21:30']])->assertStatus(422);

        $this->assertSame(0, ItineraryItem::query()->count());
    }

    public function test_someone_elses_itinerary_is_not_found(): void
    {
        $stranger = GuestSession::create(['token' => Str::random(40)]);

        $this->save('2026-11-07', ['dinner' => ['20:00', '21:30']], ['X-Guest-Token' => $stranger->token])
            ->assertNotFound();
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $stops  role => [start, end], London time
     * @param  array<string, string>|null  $headers
     */
    private function save(string $date, array $stops, ?array $headers = null): \Illuminate\Testing\TestResponse
    {
        $body = ['date' => $date, 'stops' => []];
        foreach ($stops as $role => [$starts, $ends]) {
            $body['stops'][] = [
                'role' => $role,
                'experience_id' => $this->places[$role]->id,
                'starts_at' => CarbonImmutable::parse("{$date} {$starts}", 'Europe/London')->toIso8601String(),
                'ends_at' => CarbonImmutable::parse("{$date} {$ends}", 'Europe/London')->toIso8601String(),
            ];
        }

        return $this->postJson("/api/itineraries/{$this->itinerary->id}/date-night", $body, $headers ?? $this->headers);
    }
}
