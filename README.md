# The Experience

*Don't just visit. Experience it.*

A context-aware travel operating system. Given a specific traveller and a specific
journey, it answers one question better than a map, a review directory or a ticket
marketplace can: **what is the best use of the next part of this trip?**

Built from `The_Experience_Comprehensive_JEM.md` (the master product spec). This
repository implements the MVP scope in §28 and the first commercial milestone in §34.

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
php artisan serve --port=8099
```

`GET /api/health` reports the engine version and which suppliers are configured.

### Mobile

```bash
cd mobile
cp .env.example .env            # EXPO_PUBLIC_API_URL
npm install
npx expo start                  # press i for iOS, a for Android, w for web
```

On a physical device, set `EXPO_PUBLIC_API_URL` to your machine's LAN address
(`http://192.168.x.x:8099/api`) — `127.0.0.1` resolves to the phone itself.

### Tests

```bash
cd api && php artisan test          # 84 tests, 274 assertions
cd mobile && npm run typecheck
```

---

## About the seeded content

The catalogue ships with 34 hand-written experiences across London, Rome, New York and
Tokyo, plus a neighbourhood model, sourced city essentials and 77 Experience Graph
edges. **This is demonstration content, not a live provider feed.** Every row is
written with `data_source = "seed_demo"`, that provenance travels all the way to the
API response, and the app displays it. Swap in a real place-data adapter (§9.2) and
nothing else has to change, because every caller reads provenance rather than assuming
it.

Two suppliers are wired: `sandbox`, a complete reference implementation of the provider
contract with deterministic inventory so the native booking path can be exercised end
to end, and `deeplink`, a generic affiliate redirect. A Viator adapter is written
against the real API shape and activates when `VIATOR_API_KEY` is set. Weather is live
from Open-Meteo, with a deterministic offline provider for tests.

Experiences carry no photography because we do not have licensed images for these
places; the app renders a stable colour wash per experience rather than showing a stock
photo of somewhere else.

---

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — domains, the scoring engine, the
  itinerary optimiser, provider contracts, and the decisions taken where the
  environment differed from the spec.
- [`docs/ACCEPTANCE.md`](docs/ACCEPTANCE.md) — every V1 acceptance criterion from §35
  mapped to the test that proves it.
- [`docs/API.md`](docs/API.md) — the endpoint surface from §21.
