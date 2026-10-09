# Interlude

*Make the most of the time between.*

A context-aware travel operating system. Given a specific traveller and a specific
journey, it answers one question better than a map, a review directory or a ticket
marketplace can: **what is the best use of the next part of this trip?**

Built from `The_Experience_Comprehensive_JEM.md` (the master product spec, written
before the product was named). This repository implements the MVP scope in §28 and
the first commercial milestone in §34.

```
api/      Laravel 13 modular monolith  ·  PostgreSQL  ·  Redis
mobile/   React Native + Expo + TypeScript  ·  Expo Router  ·  TanStack Query  ·  Zustand
docs/     Architecture notes and the V1 acceptance matrix
```

---

## What it actually does

Open the app in an unfamiliar city, say why you are there, pick a few interests, and
within a few seconds you get three to five options with a score and a plain-language
reason for each. Every one of them fits the time you have, the weather outside, your
budget and — crucially — your fixed commitments.

The differentiator is not the catalogue. It is that the same traveller gets different
answers on a conference trip than on an anniversary, and that the system will tell you
exactly why.

**A worked example.** A history enthusiast is shown a large museum first on a city
break. Give the same profile a layover and two hours, and a short food stop outranks
it — nothing about their interests changed, only the journey did. That behaviour is
pinned by a test (`tests/Unit/ExperienceScoreTest.php`).

### The things it refuses to do

- It will not state a price, opening time or availability it cannot attribute. Every
  dynamic fact carries its source and the moment it was checked, and the API separates
  `descriptive` content from `dynamic` facts so the client can show the difference.
- The AI guide answers only from tool results. A grounding guard strips any price,
  clock time or duration that did not come back from a service, and reports what it
  removed.
- The itinerary planner never schedules over a fixed commitment. It asserts this
  against the anchors again before it writes anything.
- A supplier outage degrades that supplier's capability, not the page.

---

## Running it

### Requirements

PHP 8.4, Composer, PostgreSQL 15+, Redis, Node 20+.

### Backend

```bash
cd api
cp .env.example .env            # then set DB_USERNAME / DB_PASSWORD
php artisan key:generate
createdb experience && createdb experience_test
php artisan migrate --seed
php artisan experience:backfill-imagery all --limit=80   # licensed photos for seeded cities
php artisan serve --port=8099
```

`GET /api/health` reports the engine version and which suppliers are configured.
Seeds are an editorial warm start for a few cities; new cities use activation +
import enrich. Imagery backfill only fills null photo URLs.

### Mobile

```bash
cd mobile
cp .env.example .env            # EXPO_PUBLIC_API_URL
npm install
npx expo start                  # press i for iOS, a for Android, w for web
```

The app works out where the API is on its own. If `EXPO_PUBLIC_API_URL` is
loopback and you are on a device or simulator, it borrows the host your phone
already used to download the bundle from the Expo dev server and keeps the API
port. An explicit non-loopback URL always wins, so staging and production are
unaffected.

### "Could not reach the API"

Almost always one of two things.

**The API is not running.** Start it, and bind it to all interfaces so a phone
on the same network can reach it:

```bash
cd api && php artisan serve --host=0.0.0.0 --port=8099
```

**Loopback on a physical device.** `127.0.0.1` on a phone is the phone. The
auto-resolution above normally handles this; if you have pinned
`EXPO_PUBLIC_API_URL` by hand, point it at your machine's LAN address
(`http://192.168.x.x:8099/api`) and restart Expo — `EXPO_PUBLIC_*` values are
baked in at bundle time.

The error banner names the exact URL it tried, which usually settles it in one
glance.

### Tests

```bash
cd api && php artisan test          # 246 tests, 797 assertions
cd mobile && npm run typecheck
```

---

## Where the data comes from

The catalogue is populated from live sources, not fixtures.

| Concern | Source | Key needed |
|---|---|---|
| Canonical places | OpenStreetMap via Overpass | no |
| Descriptions | Wikipedia REST | no |
| Photography | Wikimedia Commons | no |
| Geocoding | Nominatim | no |
| Walking routes | OSRM | no |
| Weather | Open-Meteo | no |
| Exchange rates | Frankfurter (ECB data) | no |
| Places with ratings | Google Places (New) | `GOOGLE_PLACES_API_KEY` |
| Tickets | Viator | `VIATOR_API_KEY` |

The `experience:sync-places` command remains available for explicit editorial
maintenance. Production deployment does not run a global sync; an uncovered
city is activated on demand and imported through the durable queue. The pipeline
ingests real places, resolves them against the canonical catalogue, and
pulls descriptions and photographs for anything with a Wikidata or Wikipedia
identity. Re-running is idempotent. Pass `--refresh-derived` after changing a
derivation heuristic to recompute the values this pipeline generated — it never
touches editorial content.

Google Places takes over as the place-data source the moment a key is present,
bringing the review volume OpenStreetMap does not carry. Nothing else changes.

### What the data is allowed to claim

Every fact carries its provenance to the client, and the app displays it.

- **Ratings.** OpenStreetMap has none, so `rating` stays null and the quality
  component scores neutral rather than poor. Google supplies real ones.
- **Opening hours.** The OSM `opening_hours` grammar is large; the parser covers
  the unambiguous subset and returns null for the rest. Null means "not
  verified" all the way through and is never rendered as open.
- **Photographs.** Commons images are freely licensed but almost never public
  domain, so the licence and photographer are fetched with the image and shown
  with it. An image that arrives without a licence is discarded.
- **Descriptions.** Wikipedia extracts are attributed to Wikipedia with a link.
- **Travel times.** OSRM returns a real street-network route. The public demo
  server answers foot requests from its car profile, so where the implied pace
  is not walking we keep the measured distance, derive the time from walking
  speed, and label the result `routed` rather than `live`.

A place with no description is created as `needs_content` and never reaches a
traveller. Around 90% of ingested records sit there — a memorial plaque is a
real place but not an afternoon — which is the honest outcome rather than a
catalogue padded with filler.

Thirty-four hand-written experiences across the four cities remain as editorial
content. Ingestion fills their gaps and never overwrites them.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — domains, the scoring engine, the
  itinerary optimiser, provider contracts, and the decisions taken where the
  environment differed from the spec.
- [`docs/ACCEPTANCE.md`](docs/ACCEPTANCE.md) — every V1 acceptance criterion from §35
  mapped to the test that proves it.
- [`docs/PRODUCTION_READINESS_JEM.md`](docs/PRODUCTION_READINESS_JEM.md) — Full V1+
  phased plan from booking close through store GA, notifications, group votes,
  admin UI, and hardening.
- [`docs/API.md`](docs/API.md) — the endpoint surface from §21.
- [`docs/DYNAMIC_DESTINATION_COVERAGE_JEM.md`](docs/DYNAMIC_DESTINATION_COVERAGE_JEM.md) — implementation JEM for discovering, activating, and safely ingesting unseeded destinations on demand.
- [`docs/DYNAMIC_DESTINATION_RUNBOOK.md`](docs/DYNAMIC_DESTINATION_RUNBOOK.md) — production rollout, queue, retry, outage, and budget operations.
- [`docs/DYNAMIC_DESTINATION_DEPLOY_CHECKLIST.md`](docs/DYNAMIC_DESTINATION_DEPLOY_CHECKLIST.md) — dark-deploy gates, smoke tests, rollout steps, and rollback triggers.
- [`docs/PROVIDER_SMOKE_RUNBOOK.md`](docs/PROVIDER_SMOKE_RUNBOOK.md) — live provider status, staging/prod smoke, key rotation, queue heartbeat.
- [`docs/DESTINATION_ACTIVATION_GA_RUNBOOK.md`](docs/DESTINATION_ACTIVATION_GA_RUNBOOK.md) — P5 controlled city proof and rollout ladder.
- [`docs/TRIP_STAY_DURATION_JEM.md`](docs/TRIP_STAY_DURATION_JEM.md) — stay length in onboarding → journey dates → multi-day itinerary.
