<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\EventProvider;
use App\Domains\ExternalSources\DTO\LiveEvent;
use App\Domains\ExternalSources\Services\OutboundHttp;
use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What's on, from the Ticketmaster Discovery API.
 *
 * Read-only: we link out to Ticketmaster for tickets and never sell them.
 * Prices come in major units and are converted to minor here so the app can
 * format them like every other price it shows.
 */
class TicketmasterEventProvider implements EventProvider
{
    /** Currencies with no minor unit, where 1 major = 1 minor. */
    private const ZERO_DECIMAL = ['JPY', 'KRW', 'ISK', 'CLP', 'VND', 'UGX', 'XAF', 'XOF'];

    /** Listings that are no longer something you can go to. */
    private const GONE = ['cancelled', 'offsale', 'postponed'];

    public function __construct(private readonly OutboundHttp $http) {}

    public function key(): string
    {
        return 'ticketmaster';
    }

    public function isConfigured(): bool
    {
        return filled(config('experience.providers.ticketmaster.api_key'));
    }

    public function near(GeoPoint $point, int $radiusKm, CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $response = $this->http
            ->for($this->key(), 10, 'application/json')
            ->get(rtrim((string) config('experience.providers.ticketmaster.base_url'), '/').'/events.json', [
                'apikey' => config('experience.providers.ticketmaster.api_key'),
                'latlong' => sprintf('%.4f,%.4f', $point->lat, $point->lng),
                'radius' => $radiusKm,
                'unit' => 'km',
                'startDateTime' => $from->utc()->format('Y-m-d\TH:i:s\Z'),
                'endDateTime' => $to->utc()->format('Y-m-d\TH:i:s\Z'),
                'sort' => 'date,asc',
                /* Asked for generously: the same show is often listed several
                   times (VIP, parking, each tier), and those are folded later. */
                'size' => min(200, $limit * 3),
                'locale' => '*',
            ]);

        if ($response->failed()) {
            return [];
        }

        $events = [];
        foreach ($response->json('_embedded.events') ?? [] as $row) {
            try {
                $event = $this->map($row);
            } catch (Throwable) {
                /* One malformed listing must not cost the traveller the rest. */
                continue;
            }

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): ?LiveEvent
    {
        if (in_array($row['dates']['status']['code'] ?? 'onsale', self::GONE, true)) {
            return null;
        }

        $start = $row['dates']['start'] ?? [];
        $timeKnown = isset($start['dateTime']) && ! ($start['noSpecificTime'] ?? false) && ! ($start['timeTBA'] ?? false);
        $startsAt = match (true) {
            isset($start['dateTime']) => CarbonImmutable::parse($start['dateTime']),
            isset($start['localDate']) => CarbonImmutable::parse($start['localDate'], $row['dates']['timezone'] ?? null),
            default => null,
        };

        if ($startsAt === null || empty($row['name'])) {
            return null;
        }

        $venue = $row['_embedded']['venues'][0] ?? null;
        $location = $venue['location'] ?? null;
        $price = $row['priceRanges'][0] ?? null;
        $currency = isset($price['currency']) ? mb_strtoupper((string) $price['currency']) : null;
        $classification = $row['classifications'][0] ?? [];

        return new LiveEvent(
            id: 'ticketmaster:'.$row['id'],
            source: $this->key(),
            title: (string) $row['name'],
            category: $this->category($classification['segment']['name'] ?? null),
            genre: $this->named($classification['genre']['name'] ?? null),
            startsAt: $startsAt,
            timeKnown: $timeKnown,
            venueName: $venue['name'] ?? null,
            venue: isset($location['latitude'], $location['longitude'])
                ? new GeoPoint((float) $location['latitude'], (float) $location['longitude'])
                : null,
            priceFromMinor: isset($price['min'], $currency)
                ? (int) round((float) $price['min'] * (in_array($currency, self::ZERO_DECIMAL, true) ? 1 : 100))
                : null,
            currency: $currency,
            imageUrl: $this->image($row['images'] ?? []),
            ticketUrl: $row['url'] ?? null,
        );
    }

    /** Ticketmaster's segments, in our own words. */
    private function category(?string $segment): ?string
    {
        return match ($segment) {
            'Music' => 'music',
            'Sports' => 'sport',
            'Arts & Theatre' => 'arts',
            'Film' => 'film',
            'Miscellaneous' => 'other',
            default => null,
        };
    }

    /** Ticketmaster fills unknown classifications with the word "Undefined". */
    private function named(?string $name): ?string
    {
        return $name === null || $name === 'Undefined' || $name === 'Other' ? null : $name;
    }

    /**
     * The widest landscape image, which suits a card; the API lists a dozen
     * crops of the same picture.
     *
     * @param  list<array<string, mixed>>  $images
     */
    private function image(array $images): ?string
    {
        $best = collect($images)
            ->filter(fn (array $image) => ($image['ratio'] ?? null) === '16_9' && empty($image['fallback']))
            ->sortByDesc(fn (array $image) => (int) ($image['width'] ?? 0))
            ->first();

        return $best['url'] ?? ($images[0]['url'] ?? null);
    }
}
