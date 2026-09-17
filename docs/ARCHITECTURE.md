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

## External sources

Two separate concerns, deliberately not one interface. Knowing that the Tower of
London exists is a different problem from selling a ticket to it, with different
reliability, licensing and refresh characteristics.

**`PlaceDataProvider`** — canonical place data.

| Adapter | Gives us | Notably does not |
|---|---|---|
| `osm` (Overpass) | names, coordinates, addresses, websites, phones, opening hours, wheelchair tags, Wikidata links | ratings |
| `google_places` | all of the above plus real ratings and review counts | Wikidata links |

OpenStreetMap is the default because it is real, global and keyless. Google
takes over automatically when a key is configured.

**`PlaceEnricher`** — `wikimedia` pulls descriptions from Wikipedia and
photographs from Commons, resolving the article through Wikidata sitelinks when
OSM tagged an entity but no article.

**`ExperienceProvider`** — ticketing, unchanged: `sandbox`, `deeplink`, `viator`.

**Supporting adapters** — `NominatimGeocoder`, `OsrmRoutingProvider`,
`FrankfurterCurrencyProvider`, `OpenMeteoWeatherProvider`.

### Being a good citizen of free infrastructure

Overpass, Nominatim and the public OSRM instance are community-run, and their
usage policies are a condition of access rather than a suggestion. Every
outbound call goes through `OutboundHttp`, which sets an identifying agent
string and enforces a per-provider rate limit *before* the request — it refuses
rather than queues, because a refused sync beats a ban.

Two quirks are worth knowing, both discovered the hard way:

- The public Overpass front end rejects any `User-Agent` containing parentheses
  with a 406, so the conventional `App/1.0 (+url)` form is unusable. Contact
  details go in the `From` header instead, which is the header actually meant
  for them.
- Overpass reports server-side timeouts as HTTP 200 with a `remark` and an empty
  element list. Read naively that says "this city has no museums". The adapter
  treats a remark as the failure it is, queries in small batches, and uses a
  bounding box over nodes and ways rather than `around()` over `nwr` — the
  difference between a query that answers and one that times out.

### Canonical resolution

`PlaceResolver` decides whether an ingested record is something we already hold.
In order: an existing provider mapping, a shared Wikidata identity, then
proximity plus trigram name similarity.

The asymmetry drives the thresholds. A duplicate is untidy; a wrong merge sends
someone to the wrong address. So only high-confidence matches merge
automatically and everything ambiguous becomes a `place_merge_candidate` for a
human. In practice that catches exactly the right cases — four near-identical
Italian altars 16–49m apart, a seaport *district* against a seaport *museum*.

### Derived values, and their ceiling

`ExperienceDraftFactory` turns an ingested place into a draft: duration,
exposure and interest affinity follow from the kind of place, prominence from
whether the wider web considers it notable.

Prominence is capped per kind, and that cap exists because of a real regression.
After the first full ingestion a bronze statue of Captain Cook became the top
recommendation for a first-time visitor to London, above the British Museum,
because it was four minutes closer and had a Wikipedia article. Having an
article means a thing is documented; it says nothing about whether it deserves
an afternoon. `DerivedProminenceTest` keeps it that way — ingesting more data
must not make the recommendations worse.

A draft with no description is created as `needs_content` and never surfaces.

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

**Routing has three confidence levels, not two.** OSRM returns a real
street-network route, but the public demo server answers `/foot/` from its car
profile — 1796m in six minutes is 18 km/h. The distance is still far better than
a straight line with a detour factor, so where the implied pace is not walking
we keep the measured distance, derive the time from our walking speed, and
return `confidence: "routed"`. A self-hosted foot profile returns a plausible
pace and is trusted as `"live"`. Unreachable falls back to the estimator and
`"estimate"`. The distinction survives every failure path.

**Maps use Leaflet in a WebView.** Rather than a native map SDK, which needs a dev
build and an API key, the map is a Leaflet document over OpenStreetMap tiles. It runs
in Expo Go, needs no key, and the platform-split `MapCanvas` uses an iframe on web.

**Photography is licensed and credited.** Images come from Wikimedia Commons,
which is freely licensed but almost never public domain. The licence and
photographer are fetched with the image and rendered by the `Photo` component
itself, so a screen cannot display one without its credit. Where no licensed
image exists the deterministic colour wash is still used, because a stock photo
of somewhere else would be worse than no photo.

## Things worth knowing before extending it

- Ingestion runs each record in its own transaction (a savepoint when nested).
  Without it, one failed insert aborts the surrounding PostgreSQL transaction and
  every later statement fails too — including writing down what went wrong.
- The enricher caches plain arrays, never DTOs, and caches only definitive
  answers. "Wikipedia has nothing on this" is worth remembering for a week; "we
  were rate limited just then" would blank the description until it expired.
- `pgsql.timezone` is pinned to `+00:00` in `config/database.php`. The host database
  ran in `Europe/London`, which silently shifted every `timestamptz` write by an hour.
  For a travel app that is not a cosmetic bug.
- Anchor times without an offset are read in the **destination's** timezone: "dinner at
  20:00" means 20:00 in Rome, wherever the phone is.
- Tokens issued by the public API carry the `traveller` ability, never Sanctum's
  default wildcard. Admin endpoints require the `admin` ability.
- `X-Guest-Token` must stay in the CORS `exposed_headers` list — guest-first browsing
  depends on the client reading it.
