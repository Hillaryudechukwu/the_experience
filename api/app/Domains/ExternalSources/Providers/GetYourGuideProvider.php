<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\ExperienceProvider;
use App\Domains\ExternalSources\Contracts\ProviderCapability;
use App\Domains\ExternalSources\DTO\Availability;
use App\Domains\ExternalSources\DTO\AvailabilitySlot;
use App\Domains\ExternalSources\DTO\BookingRequest;
use App\Domains\ExternalSources\DTO\BookingResult;
use App\Domains\ExternalSources\DTO\CancellationResult;
use App\Domains\ExternalSources\DTO\DateRange;
use App\Domains\ExternalSources\DTO\ExperienceProduct;
use App\Domains\ExternalSources\DTO\SearchCriteria;
use App\Domains\ExternalSources\Services\OutboundHttp;
use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * GetYourGuide adapter.
 *
 * ── Read this before trusting the field names ────────────────────────────
 *
 * The wire mapping below is PROVISIONAL. It was written without access to
 * GetYourGuide's partner documentation, because the API key has not been
 * granted yet, so the endpoint paths and JSON field names are a reasonable
 * shape rather than a verified one. Everything else — the interface, the
 * capability declaration, the error handling, the mapping into our DTOs — is
 * correct regardless of what the payloads turn out to look like.
 *
 * When access arrives: record a real response into the fixtures used by
 * tests/Feature/ExternalSources/GetYourGuideProviderTest.php and run it. The
 * test pins every field this adapter reads, so a wrong guess fails loudly and
 * tells you which one. Do not skip that step because the class compiles.
 *
 * ── Why booking is redirect-only ─────────────────────────────────────────
 *
 * Native booking — taking the traveller's money inside our app and holding a
 * reservation ourselves — is a contractual tier, not merely an endpoint, and
 * claiming a capability we have not been granted would make the registry route
 * real checkouts into a call that cannot succeed. Redirect works for every
 * partner from day one: the traveller completes the purchase on GetYourGuide's
 * own checkout, which is also where the payment and the liability belong.
 *
 * Promote it when, and only when, the contract says so: add NATIVE_BOOKING to
 * capabilities() and implement createBooking() against the real endpoint.
 */
class GetYourGuideProvider implements ExperienceProvider
{
    private const BASE = 'https://api.getyourguide.com/1';

    /** Where a traveller is sent to complete the purchase. */
    private const CHECKOUT = 'https://www.getyourguide.com/activity';

    public function __construct(private readonly OutboundHttp $http) {}

    public function key(): string
    {
        return 'getyourguide';
    }

    /**
     * What this adapter can actually do today.
     *
     * Deliberately not NATIVE_BOOKING, and deliberately not REVIEWS: the
     * registry routes work by capability, so anything listed here is a promise
     * that a real call will succeed.
     */
    public function capabilities(): array
    {
        return [
            ProviderCapability::CONTENT,
            ProviderCapability::SEARCH,
            ProviderCapability::LIVE_AVAILABILITY,
            ProviderCapability::LIVE_PRICING,
            ProviderCapability::REDIRECT_BOOKING,
            ProviderCapability::CANCELLATION,
            ProviderCapability::IMAGES,
        ];
    }

    public function isConfigured(): bool
    {
        return (bool) config('experience.providers.getyourguide.api_key');
    }

    public function search(SearchCriteria $criteria): Collection
    {
        $response = $this->client()->get(self::BASE . '/tours', array_filter([
            'q' => $criteria->query,
            'cnt' => $criteria->limit,
            'currency' => config('experience.currency.base', 'GBP'),
            /* A coordinate search when we have one: the traveller is standing
               somewhere specific, and that is the whole premise of the app. */
            'coordinates' => $criteria->near === null
                ? null
                : sprintf('%.5f,%.5f', $criteria->near->lat, $criteria->near->lng),
            'radius' => $criteria->near === null ? null : ($criteria->radiusMetres ?? 5000),
        ], fn ($value) => $value !== null && $value !== ''));

        if ($response->failed()) {
            throw new RuntimeException("GetYourGuide search returned {$response->status()}.");
        }

        return collect($response->json('data.tours') ?? [])
            ->map(fn (array $tour) => $this->mapProduct($tour))
            ->values();
    }

    public function getProduct(string $providerId): ?ExperienceProduct
    {
        $response = $this->client()->get(self::BASE . '/tours/' . urlencode($providerId), [
            'currency' => config('experience.currency.base', 'GBP'),
        ]);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException("GetYourGuide product lookup returned {$response->status()}.");
        }

        $tour = $response->json('data.tours.0') ?? $response->json('data') ?? [];

        return $tour === [] ? null : $this->mapProduct($tour);
    }

    public function availability(string $providerId, DateRange $dates): Availability
    {
        $response = $this->client()->get(self::BASE . '/tours/' . urlencode($providerId) . '/availabilities', [
            'date_from' => $dates->from->toDateString(),
            'date_to' => $dates->to->toDateString(),
            'currency' => config('experience.currency.base', 'GBP'),
        ]);

        /*
         * An unknown answer, not an empty one.
         *
         * Empty slots mean "sold out", which is a fact about the product.
         * A failed call means we do not know, which is a fact about us, and
         * the two must not arrive at the traveller looking the same (spec
         * s15.1: never fabricate availability).
         */
        if ($response->failed()) {
            return Availability::unknown(
                $this->key(),
                $providerId,
                "GetYourGuide did not answer ({$response->status()}).",
            );
        }

        $slots = [];

        foreach ($response->json('data.availabilities') ?? [] as $entry) {
            $starts = $entry['start_time'] ?? $entry['date'] ?? null;

            if (! is_string($starts)) {
                continue;
            }

            $moment = CarbonImmutable::parse($starts);

            $slots[] = new AvailabilitySlot(
                date: $moment,
                /* Only when the product is genuinely timed. A date-only entry
                   with a fabricated 00:00 would read as a midnight departure. */
                startTime: str_contains($starts, 'T') ? $moment->format('H:i') : null,
                slotsRemaining: isset($entry['vacancies']) ? (int) $entry['vacancies'] : null,
                price: $this->money($entry['price'] ?? null, $entry['currency'] ?? null),
            );
        }

        return new Availability(
            provider: $this->key(),
            providerProductId: $providerId,
            slots: $slots,
            freshness: Freshness::live($this->key()),
            isLive: true,
        );
    }

    /**
     * Hands the traveller to GetYourGuide's checkout.
     *
     * No call is made: there is nothing to ask. The partner id rides on the
     * URL so the booking is attributed to us, and everything after this point
     * — payment, confirmation, the customer relationship — is theirs.
     */
    public function createBooking(BookingRequest $request): BookingResult
    {
        $partner = config('experience.providers.getyourguide.partner_id');

        $query = array_filter([
            'partner_id' => $partner,
            'date' => $request->startsAt?->toDateString(),
            'p' => $request->quantity > 0 ? $request->quantity : null,
        ], fn ($value) => $value !== null && $value !== '');

        return BookingResult::redirect(
            self::CHECKOUT . '/' . urlencode($request->providerProductId) . '?' . http_build_query($query),
            cancellationPolicy: 'Cancellation terms are set by GetYourGuide and shown at checkout.',
            meta: ['partner_id' => $partner],
        );
    }

    /**
     * A booking we redirected is not a booking we hold.
     *
     * The traveller bought it on GetYourGuide's site under their account, and
     * we have no authority over it. Saying so is the honest answer; pretending
     * to cancel and silently failing is the dangerous one, because the
     * traveller stops watching a booking that is still live.
     */
    public function cancelBooking(string $providerBookingId): CancellationResult
    {
        return new CancellationResult(
            success: false,
            reason: 'This was booked on GetYourGuide, so it has to be cancelled there — '
                . 'through the confirmation email or a GetYourGuide account.',
        );
    }

    /** @param array<string, mixed> $tour */
    private function mapProduct(array $tour): ExperienceProduct
    {
        $price = $tour['price'] ?? null;

        return new ExperienceProduct(
            provider: $this->key(),
            providerProductId: (string) ($tour['tour_id'] ?? $tour['id'] ?? ''),
            title: (string) ($tour['title'] ?? 'Untitled activity'),
            description: $tour['abstract'] ?? $tour['description'] ?? null,
            productUrl: $this->attributedUrl($tour['url'] ?? null),
            priceFrom: $this->money(
                is_array($price) ? ($price['values']['amount'] ?? null) : $price,
                is_array($price) ? ($price['currency'] ?? null) : ($tour['currency'] ?? null),
            ),
            priceFreshness: Freshness::live($this->key()),
            capabilities: $this->capabilities(),
            cancellationPolicy: $tour['cancellation_policy'] ?? null,
            meta: [
                'images' => array_values(array_filter(array_map(
                    fn ($image) => is_array($image) ? ($image['url'] ?? null) : (is_string($image) ? $image : null),
                    $tour['pictures'] ?? [],
                ))),
                'duration_minutes' => isset($tour['duration']) ? (int) $tour['duration'] : null,
            ],
        );
    }

    /** Every outbound link carries the partner id, or the booking is not ours. */
    private function attributedUrl(?string $url): ?string
    {
        $partner = config('experience.providers.getyourguide.partner_id');

        if ($url === null || $partner === null) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'partner_id=' . urlencode((string) $partner);
    }

    /**
     * Prices arrive as major units and are stored as minor.
     *
     * Rounded rather than truncated: £42.99 becoming £42 is a price we would
     * be showing the traveller that is lower than the one they will be
     * charged, which is the wrong direction to be wrong in.
     */
    private function money(mixed $amount, ?string $currency): ?Money
    {
        if (! is_numeric($amount)) {
            return null;
        }

        return Money::of(
            (int) round((float) $amount * 100),
            $currency ?? config('experience.currency.base', 'GBP'),
        );
    }

    private function client()
    {
        return $this->http
            ->for($this->key(), timeout: 8, accept: 'application/json')
            ->withHeaders([
                'X-ACCESS-TOKEN' => (string) config('experience.providers.getyourguide.api_key'),
            ]);
    }
}
