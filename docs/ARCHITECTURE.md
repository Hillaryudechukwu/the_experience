# Architecture

## Shape

A Laravel modular monolith. Each domain owns its models, services and HTTP surface;
controllers stay thin and delegate to an explicit service or action.

```
api/app/Domains/
  Shared/                value objects: GeoPoint, Money, Freshness, TimeWindow, Actor
  Identity/              guest sessions
  TravellerProfile/      persistent profile, interest vector, Experience DNA
  Journeys/              journey profile, anchors, day-level goals, context snapshots
  JourneyIntelligence/   mission text -> structured soft goals
  Destinations/          destinations, neighbourhoods, city essentials, signature items
  Places/                canonical places, opening hours, indexed radius search
  Experiences/           experiences, categories, the Experience Graph, API presenter
  Discovery/             context engine, candidate builder (hard filters)
  Recommendations/       the nine scoring components, the scorer, persisted sets
  Itineraries/           constrained planner and the replanner
  Trips/                 trips, itineraries, days, items, group votes
  ExternalSources/       provider contracts, adapters, health, offers
  Bookings/              state machine, idempotency guard, booking service
  Passport/              saves, completions, journal, passport, recap
  Analytics/             behavioural events
  AI/                    orchestrator, toolbox, intent parser, grounding guard
  Notifications/         outbox
```

## The Experience Score

Nine weighted components (`config/experience.php`), each a class implementing
`ScoreComponent` that returns a 0–1 value plus the reasons behind it.

| Component | Weight | What it reads |
|---|---|---|
| `personal_interest_fit` | 20% | traveller interest vector × experience affinity |
| `journey_purpose_fit` | 20% | reason playbook, mission goals, familiarity, must-do list |
| `quality_confidence` | 15% | rating shrunk towards the mean by review volume |
| `uniqueness` | 10% | distinctiveness for the destination |
| `current_time_fit` | 10% | opening hours, remaining window, daylight, queue risk |
| `location_convenience` | 10% | travel time, and whether the next anchor is still reachable |
| `value` | 5% | price against budget and the transparent value signal |
| `weather_fit` | 5% | exposure against the forecast across the visit |
| `companion_fit` | 5% | child ages, accessibility, party composition |

Three design decisions worth calling out:

**Weights are configuration, not code.** The service provider asserts at boot that they
sum to 1.0, so an experiment cannot silently skew ranking.

**Missing data is neutral, not zero.** A component with nothing to judge on declares
itself neutral and its weight is redistributed across the rest. A museum without a
rating is not punished for the gap.

**Hard constraints are filters, not penalties.** `CandidateBuilder` removes anything
that cannot physically work — does not fit the window, would risk a fixed commitment,
fails a declared accessibility need — before scoring begins. A low score should mean
"not the best use of your time", never "impossible".

Every ranking is written to `recommendation_sets` with its component reasons and a
`journey_context_snapshot` of the inputs, so `GET /api/admin/recommendation-sets/{id}`
can explain any recommendation after the fact.

## The itinerary planner

Constrained optimisation, not text generation.

1. Anchors become immutable blocks, each widened by the traveller's buffer plus an
   engine safety margin.
2. The day is carved into the free windows between those blocks.
3. Each window is filled greedily: for every candidate the planner computes travel
   time from the current position, checks the place is open for the whole planned
   visit, scores it in that exact slot, then subtracts a travel penalty and a
   repetition penalty. Below a floor of 40 it leaves the slot empty rather than filling
   it with something mediocre.
4. Meal windows are protected when nothing has claimed them.
5. Before anything is persisted, every non-anchor item is re-checked against every
   anchor's protected window. A violation raises rather than being written.

`ItineraryReplanner` produces a *proposal* — a new version that is not made current
until accepted — and refuses to move anything covered by a confirmed booking.

## Providers

`ExperienceProvider` is the spec's interface verbatim. Capability flags describe what
each supplier can actually do, and the code branches on capability rather than on
supplier name.

| Provider | Capabilities |
|---|---|
| `sandbox` | content, search, live availability, live pricing, native booking, cancellation |
| `deeplink` | content, search, redirect booking |
| `viator` | the full set, once `VIATOR_API_KEY` is present |

`ProviderRegistry::attempt()` wraps every call with health tracking and a fallback
value, and a circuit breaker opens after repeated failures. A supplier that cannot
answer marks its own offer `degraded`; the rest of the page is unaffected.

The deep-link provider returns `Availability::unknown(...)` with a reason rather than
guessing inventory it cannot see.

## Data freshness

`Freshness` travels with every dynamic fact: source, when it was verified, its class
(static / semi-dynamic / highly dynamic), whether it is stale against that class's TTL,
and a human label. The experience payload is split into `descriptive` (cacheable, no
freshness) and `dynamic` (each fact carries its own).

## The AI guide

Two drivers over one toolbox and one guard.

- `rules` — deterministic composition from tool results. No network, always available,
  and what the regression tests run against.
- `anthropic` — a tool-calling loop against the Claude API. Facts still come only from
  the toolbox.

Either way the answer passes through `GroundingGuard`, which extracts every number the
tools returned (including minor→major currency and 24h→12h clock renderings) and
removes any sentence stating a price, clock time or duration that is not in that set.
Removals are reported to the client and stored on the message.

## Decisions taken where the environment differed from the spec

**PostGIS → cube/earthdistance.** PostGIS was not available on the target database.
Radius search uses the `cube`/`earthdistance` pair with a GiST index on
`ll_to_earth(lat, lng)`, so it is genuinely index-backed on stock PostgreSQL. All
geospatial queries go through `GeospatialRepository`; swapping the body for
`ST_DWithin` when PostGIS is present touches nothing else. `pg_trgm` indexes back the
fuzzy name matching that canonical place resolution needs.

**Routing is an estimator, labelled as one.** No routing provider is integrated, so
travel times come from great-circle distance with a street-network detour factor and a
transit model above a threshold. Every estimate is returned with
`confidence: "estimate"` and its source, so nothing downstream presents it as a live
routing answer. `RoutingProvider` is an interface; binding a real one is a one-line
change.

**Maps use Leaflet in a WebView.** Rather than a native map SDK, which needs a dev
build and an API key, the map is a Leaflet document over OpenStreetMap tiles. It runs
in Expo Go, needs no key, and the platform-split `MapCanvas` uses an iframe on web.

**No photography.** We have no licensed images for these places, so `image_url` is null
throughout and the app renders a deterministic colour wash per experience. Showing a
stock photo of somewhere else would be the dishonest option.

## Things worth knowing before extending it

- `pgsql.timezone` is pinned to `+00:00` in `config/database.php`. The host database
  ran in `Europe/London`, which silently shifted every `timestamptz` write by an hour.
  For a travel app that is not a cosmetic bug.
- Anchor times without an offset are read in the **destination's** timezone: "dinner at
  20:00" means 20:00 in Rome, wherever the phone is.
- Tokens issued by the public API carry the `traveller` ability, never Sanctum's
  default wildcard. Admin endpoints require the `admin` ability.
- `X-Guest-Token` must stay in the CORS `exposed_headers` list — guest-first browsing
  depends on the client reading it.
