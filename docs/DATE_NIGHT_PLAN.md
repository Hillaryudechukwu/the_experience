# Date Night — feature plan

Branch: `feat/date-night` (cut from `main`, not from the open paid-launch branch).

## What it is

A **Date Night** chip in Discover's "What are you working with?" row, in the
slot `30 min` holds today. `30 min` is removed, so the row reads `Right now`,
`Date night`, `1 hour`, and so on. Tapping it asks **when** (tonight or a
date in the future) and returns a short evening plan for two people: a first stop,
dinner, and an optional nightcap. Each stop is timed, open at that hour, and close
to the next one.

The other chips filter the list. This one plans an evening. It works like the
"Add dates" chip: it opens a sheet and never stays selected.

## What already exists (and gets reused)

| Need | Already in the codebase |
| --- | --- |
| Score for a future moment | `ContextEngine::build()` accepts `now` (`api/app/Domains/Discovery/Services/ContextEngine.php:54`) |
| Romantic bias | `romantic` is a mood in `config/experience.php:79`; `MoodBlend` and `CompanionFit` (2 adults + romantic reason) already use it |
| Open at that hour | `CurrentTimeFit` checks `openingHours->isOpenAt($context->now)` |
| Fit inside a window | `window_minutes` → `TimeWindow`, capped by the next anchor |
| Weather | `OpenMeteoWeatherProvider::forecast()` (only 3 forecast days) |
| Save to the trip | `POST itineraries/{id}/items` takes `date` + `starts_at` and checks for anchor clashes |
| Paywall | the `subscribed` route group in `routes/api.php` |
| Chip row | `TIME_WINDOWS` + the chip `ScrollView` in `mobile/app/(tabs)/index.tsx:50,375` |

## API

### `POST /api/discovery/date-night` (inside the `subscribed` group)

Request (plus the usual `common()` fields: location, `journey_id`, `destination_id`, …):

```json
{
  "date": "2026-11-14",          // required, destination-local
  "starts_at": "19:00",          // optional, default 19:00
  "duration_minutes": 240,       // optional, 120–360, default 240
  "budget": "relaxed",           // optional: "low" | "relaxed" | "splurge" → max_price_minor
  "include_nightcap": true       // optional, default true
}
```

Validation rules, all in the **destination's** timezone:

- `date` must be today or later, and no more than 90 days ahead (or the journey's
  `ends_on`, if that comes first).
- **Today:** if `starts_at` has already passed, start at *now rounded up to the
  next 15 min*. If that leaves less than 90 minutes before 23:30, return 422
  `date_night_too_late` with "Tonight's nearly over. Plan for tomorrow?".
- **Future:** use `starts_at` as given.

Response:

```json
{
  "data": {
    "date": "2026-11-14",
    "starts_at": "…", "ends_at": "…",
    "stops": [
      { "role": "opener",   "starts_at": "…", "ends_at": "…", "travel_minutes_from_previous": null, "experience": { /* card */ } },
      { "role": "dinner",   "starts_at": "…", "ends_at": "…", "travel_minutes_from_previous": 12,   "experience": { /* card */ } },
      { "role": "nightcap", "starts_at": "…", "ends_at": "…", "travel_minutes_from_previous": 6,    "experience": { /* card */ } }
    ],
    "alternatives": { "opener": [/* 2 cards */], "dinner": [/* 2 */], "nightcap": [/* 2 */] },
    "weather": { /* snapshot, or null if more than 3 days out */ },
    "weather_note": "Forecast arrives closer to the day."
  },
  "recommendation_set_id": "…",
  "notice": null
}
```

### `DateNightPlanner` (new: `api/app/Domains/Itineraries/Services/DateNightPlanner.php`)

1. Build one base context with `ContextEngine::build()`, passing
   `now = date + start`, `window_minutes = duration`, `mood = romantic`,
   `adults = 2`, `children = 0`, and `surface = date_night`.
2. Fill slots one after another. Each slot gets its own `now`, so opening hours
   are checked for the time that stop actually starts:
   - **Opener** (45–75 min). Categories: viewpoint, gallery, park, walk, or
     experience. Sunset-facing viewpoints get a boost when the start is within
     about 45 min of sunset. Free or low-cost options break ties.
   - **Dinner** (90–120 min, required). Category: food. Uses `max_price_minor`
     from `budget`, and prefers places still open at least 30 min after the slot
     ends.
   - **Nightcap** (45–60 min, optional). Category: nightlife or a dessert-style
     food place. Must be within about 15 min of dinner.
3. Location: each slot after the first is scored with `location` set to the
   previous stop's place, so `LocationConvenience` keeps the evening compact.
4. Fallbacks: if no dinner fits, return the best opener and nightcap with notice
   "No dinner spot fits that time — try an earlier start". If nothing fits at
   all, return `data: null` with the same kind of message `surpriseMe` uses.
5. Persist a `RecommendationSet` with `surface = date_night`, as other surfaces
   do, so analytics and scoring can see the plan.

**Important:** a date-night request must **not** go through
`LiveIntentStore::recordDiscovery()`. Planning a date for next week must not tint
today's Discover with `romantic` for the rest of the day.

### Saving the plan

Phase 1: "Add to my trip" posts each stop to the existing
`POST itineraries/{id}/items` with the stop's `date` and `starts_at`. That
endpoint already rejects anchor clashes. If there's no trip or itinerary yet, the
button says "Start a trip to save this". Phase 2: a dedicated saved-plan object
(see open questions).

## Mobile

- `mobile/app/(tabs)/index.tsx`: remove `{ minutes: 30, label: '30 min' }`
  from `TIME_WINDOWS` and put a `Date night` chip in its place, right after
  `Right now`, with `tone="accent"` (and a heart icon if `Icon` has one). It
  opens a sheet and never takes the selected state. Effects of removing 30 min:
  - The API keeps accepting `window_minutes: 30`, since validation is
    `min:15`. Short gaps from `AnchorStrip` (`onPressFree`) and any
    `window_minutes` the search parser extracts still work.
  - A traveller whose remembered intent today is 30 minutes still gets it
    restored: the headline and "Clear" still show, but no chip is lit. That only
    lasts until the intent expires at the end of the day, so no migration is
    needed.
  - Drop `30` from `config/experience.php` `time_windows` to keep it matching
    the chips. Nothing reads that key today.
- `mobile/src/components/DateNightSheet.tsx` (new): **Tonight / Tomorrow / Pick
  a date** segment, a single-day calendar (reuse `DateRangeCalendar` if it
  supports a single-day mode; otherwise add a `mode="single"` prop), a start-time
  stepper in 30-min steps, budget chips, and a nightcap toggle. Dates before
  `earliestDate` and after the journey's end are disabled.
- `mobile/src/api/hooks.ts`: add `useDateNight()`, a mutation posting to
  `/discovery/date-night` with `discoveryPayload(...)`.
- `mobile/app/date-night.tsx` (new route): the plan as a timeline with time,
  stop card, and travel minutes between stops. Each stop has a **Swap** button
  that cycles through `alternatives[role]` with no extra request. Also shows the
  weather line, "Add to my trip", and "Change time" (reopens the sheet).
- Types for the response go next to `DiscoveryResponse`.
- Follow the Expo SDK 57 docs, as `mobile/AGENTS.md` requires.

## Tests

`api/tests/Feature/DateNightTest.php`:

- Tonight before 19:00 → plan starts at 19:00.
- Tonight at 21:10 → plan starts at 21:15.
- Tonight at 22:30 → 422 `date_night_too_late`.
- A past date, or a date after `ends_on`, → 422.
- A future date → stops scored at that date's time. A restaurant closed that
  weekday is never picked as dinner.
- A plan always has a dinner stop, or returns the "no dinner" notice.
- Nightcap is within the walking or short-transit limit of dinner.
- A date-night request writes no `LiveIntent`.
- Unsubscribed request → the same rejection the other `subscribed` routes give.
- More than 3 days out → `weather: null` with `weather_note`.

Unit tests for `DateNightPlanner` slot timing, plus mobile component tests if the
app has a harness for them.

## Build order

1. `DateNightPlanner` and its unit tests (no HTTP).
2. Controller action, route, feature tests, and an entry in `docs/API.md`.
3. Mobile hook, sheet, and chip.
4. Plan screen with swap and save.
5. QA on a seeded city (London seed) for tonight, next Saturday, and 10 days out.

## Open questions

1. ~~**Placement:**~~ Decided: Date Night **replaces** `30 min`.
2. **Paywall:** keep it behind `subscribed` like the rest of discovery, or offer
   it as a free taster?
3. **Saving:** is putting each stop into the trip itinerary enough, or should a
   date night be its own saved object, with a reminder notification on the day?
4. **Booking:** reserving dinner (OpenTable or Viator offers) is left out of this
   plan and would be a follow-up.
