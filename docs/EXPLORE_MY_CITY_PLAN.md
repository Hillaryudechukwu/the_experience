# Explore My City — feature plan

Branch: `feat/explore-my-city`, cut from `feat/date-night` because both edit the
same chip row; merge Date night first.

## What it is

An **Explore my city** chip in Discover's "What are you working with?" row, in the
slot `2 hours` holds today. `2 hours` is removed, so the row reads `Right now`,
`Date night`, `Explore my city`, `1 hour`, `3 hours`, …

Tapping it uses the device's location to work out which city the traveller is
standing in, then opens a screen of things to enjoy there: what is on (dated
events — gigs, festivals, markets, exhibitions) and what is always good (the
experiences we already score). It is built for "I'm here, what's good?", not for
a trip planned in advance, so it follows the phone, not the chosen destination.

Like Date night and Add dates, it is an action chip: it opens a screen and never
stays selected.

## What already exists (and gets reused)

| Need | Already in the codebase |
| --- | --- |
| Device location | `session.refreshLocation()` / `coords` in `mobile/src/store/session.ts:221` (reads the grant, never prompts) |
| Prompting for location | `Location.requestForegroundPermissionsAsync()` in `mobile/app/onboarding/destination.tsx:136` |
| Coordinates → city | `NominatimGeocoder::reverse()` (`api/app/Domains/ExternalSources/Providers/NominatimGeocoder.php:91`) |
| City not covered yet | Dynamic destination import: `QueueEmptyDestinationImport`, `ImportDestination` job, `destination-imports/{import}` polling, `useDestinationImport` |
| Scoring "good right now" near a point | `ContextEngine::build()` + `discovery/now` (location is already in `common()`) |
| Weather | `OpenMeteoWeatherProvider` |
| Experience cards | `ExperienceCardView`, `presenter->card()` |
| Save to trip | `POST itineraries/{id}/items` |
| Chip row | `TIME_WINDOWS` + chip `ScrollView` in `mobile/app/(tabs)/index.tsx:54,378` |

What does **not** exist: anything dated. `experiences` are evergreen (duration,
price, opening hours) — there is no event, occurrence or start date. Events are
the new part of this feature.

## Events source

None of the current providers (Viator, GetYourGuide, Tiqets, Google Places,
Overpass) give us "what's on tonight".

**Recommendation: Ticketmaster Discovery API** as the first provider.
- Free key, ~5,000 calls/day, searches by `latlong` + `radius` + date range.
- Covers concerts, sport, theatre, comedy, family; strong in US/UK/EU.
- Returns venue coordinates, start time, price range, image and a ticket URL
  (affiliate-able later).

Behind an `EventProvider` interface (same shape as the other providers, with
the circuit breaker and `OutboundHttp`), so PredictHQ or a local listings feed
can be added per city later. Eventbrite is ruled out: its public event search
was withdrawn.

Fallback when no key / no results: the section hides and the screen shows only
experiences. No placeholder events.

## API

### `POST /api/discovery/explore-city` (inside the `subscribed` group)

Fully paywalled, like Date night: the route sits in the `subscribed` group in
`routes/api.php:102`, next to `discovery/date-night`. Without a paid month the
middleware refuses it before any geocoding or provider call runs, so a free
user never costs us a Ticketmaster or Nominatim request.

Request: the usual `common()` fields; `lat`/`lng` are **required** here.
Optional `when`: `"today"` (default) | `"weekend"` | `"week"`.

Steps:
1. **Resolve the city.** Reverse-geocode the point (cached per ~1 km, as
   Nominatim already does). Match to an existing `Destination` within its
   bounds / nearest within 30 km.
   - Matched and active → continue.
   - Matched but importing → return `202` with the `destination_import` id; the
     app polls `destination-imports/{id}` as it does today.
   - No match → `RecordDestinationDemand` + `QueueEmptyDestinationImport` (subject
     to the existing activation gate / rollout %), return `202` as above.
     Events can still be returned straight away, since they don't depend on our
     catalogue.
2. **Experiences.** `ContextEngine::build()` with `surface = explore_city`, the
   real location, no window. Group the scored results into sections:
   `top_picks` (6), `hidden_gems` (6), `free` (6), `by_neighbourhood` (top 3
   neighbourhoods with 3 each).
3. **Events.** `EventFinder` queries the provider for the chosen range within
   ~15 km, de-dupes, drops sold-out / past, ranks by start time + distance +
   category fit with the traveller profile, and caches per city+range for
   30 min.

Response:

```json
{
  "data": {
    "city": { "name": "Lisbon", "destination_id": "…", "slug": "lisbon", "timezone": "Europe/Lisbon" },
    "events": [
      { "id": "tm:…", "title": "…", "category": "music", "starts_at": "…", "venue": { "name": "…", "lat": 0, "lng": 0 },
        "distance_metres": 1800, "price_from_minor": 2500, "currency": "EUR", "image_url": "…", "ticket_url": "…", "source": "ticketmaster" }
    ],
    "sections": {
      "top_picks": [ /* cards */ ],
      "hidden_gems": [ /* cards */ ],
      "free": [ /* cards */ ],
      "by_neighbourhood": [ { "neighbourhood": "Alfama", "items": [ /* cards */ ] } ]
    },
    "weather": { /* snapshot */ }
  },
  "destination_import": null,
  "recommendation_set_id": "…"
}
```

### Storage

New table `event_occurrences` (provider, external_id, destination_id nullable,
title, category, starts_at, ends_at, venue name/lat/lng, price range, currency,
image_url, ticket_url, fetched_at). Short-lived: rows past `ends_at` are pruned
daily. It is a cache plus a stable id for saving, not a catalogue.

Saving an event to a trip: extend `itinerary items` with an optional
`event_occurrence_id` (the existing anchor-clash check uses its `starts_at`).
Could be cut from v1.

### Privacy

Coordinates are used for the request and the rounded reverse-geocode cache key
only, respecting `privacy.store_location_history = false`.

## Mobile

1. **Chip.** Replace `{ minutes: 120, label: '2 hours' }` in `TIME_WINDOWS` with
   an `Explore my city` action chip after `Date night` (icon `location`/`compass`).
   The API still accepts a 120-minute window, so a 2-hour gap tapped in "Your day"
   (`AnchorStrip`) works as before — same note as the 30-minute removal.
2. **Paywall.** The chip is shown to everyone. On tap, read `useEntitlement()`
   first: no paid month → open the paywall, as Date night does, and stop there
   (no location prompt). The server's `subscribed` check is still the one that
   counts; the client check only decides what to show.
3. **Permission.** On tap, if there is no grant, show a short explainer then
   call `requestForegroundPermissionsAsync()`. Declined → screen offers "Choose a
   city instead" (destination picker). Stale `coords` (> 10 min) → refresh first.
4. **Screen** `app/explore-city.tsx`: header "Exploring {city}" + weather line;
   `What's on` rail (Today / This weekend / This week segmented control);
   then sections of `ExperienceCardView`s; map toggle optional.
   Import in progress → reuse the destination-import progress state; show events
   meanwhile.
5. **Hooks** `useExploreCity(when)` in `src/api/hooks.ts`, using
   `discoveryPayload()` so location rides along like the other surfaces.
6. **Analytics.** Record surface `explore_city` (add to `SURFACE_LABEL`), event
   opens and ticket-link taps.

## Tests

- API feature tests: city resolution (match, importing, unknown → import queued),
  events provider faked via `Http::fake`, provider down → events empty but 200,
  validation (missing coords → 422), unsubscribed user refused by `subscribed`
  with no geocoder or provider call made.
- Unit: `EventFinder` ranking and de-dupe; reverse-geocode → destination matcher.
- Mobile: Playwright flow — chip visible in place of `2 hours`, free user sees
  the paywall (and no location prompt), permission declined path, screen renders
  events + sections.

## Decided

- **Paywall:** fully paywalled, like Date night (2026-11-05).
- **Events provider:** Ticketmaster Discovery API, key in `TICKETMASTER_API_KEY`
  (2026-11-05).
- **Travelling vs at home:** a one-off view; Explore never changes the app's
  chosen destination (2026-11-05).

## Built differently from the plan (v1)

- **No `event_occurrences` table.** With saving events to a trip left out of v1,
  a table had nothing to do that the cache does not. Provider results are cached
  for 30 min per ~1 km grid square, range and day (`EventFinder`). Add the table
  when saving events is built.
- **Save event to trip** is not in v1. A tap opens the Ticketmaster page and
  records `event_ticket_opened`.
- **Uncovered city:** the response carries a `candidate` (same shape and token as
  a typed destination search) and the app calls the existing
  `destinations/activate`, so it goes through the same activation gate.
  Explore does not queue an import by itself.
- **City still preparing:** returned as `data.preparing` (the 409 body from
  `EnsureDestinationReadyForDiscovery`) inside a 200, alongside the events; the
  app polls until the sections arrive.
- **Rows never repeat a place.** Hidden gems, Free and the neighbourhood rows skip
  anything an earlier row already shows.
- **Ranking of events** is by start time after de-duping repeat listings (VIP,
  parking, price tiers); no profile fit yet.
- **Declined location** shows a "Back to Discover" path rather than the
  destination picker, since that picker changes the trip's city.

## Order of work

1. `EventProvider` + Ticketmaster adapter + `event_occurrences` + `EventFinder` (with tests).
2. City resolver (reverse geocode → destination / import).
3. `explore-city` endpoint and presenter.
4. Mobile chip swap + paywall check + permission flow.
5. Explore screen + hook.
6. Save event to trip (optional for v1).
