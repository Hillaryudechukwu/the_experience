# Interlude — Production Readiness JEM (Full V1+)

**Status:** Proposed implementation specification  
**Scope:** Laravel API, Expo clients, commercial providers, store release, operations  
**Objective:** Take Interlude from a proven MVP brain (~86% end-to-end functional) to a store-submittable, commercially useful, Full V1+ product — every remaining gap closed in ordered phases with exit criteria.

**Related docs**

- [`ACCEPTANCE.md`](ACCEPTANCE.md) — V1 acceptance matrix (§35) and beyond-checklist behaviour
- [`API.md`](API.md) — endpoint surface
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — domains, scoring, providers
- [`RELEASE.md`](RELEASE.md) — App Store / Play Store release sequence
- [`DYNAMIC_DESTINATION_COVERAGE_JEM.md`](DYNAMIC_DESTINATION_COVERAGE_JEM.md) — on-demand city activation design
- [`DYNAMIC_DESTINATION_RUNBOOK.md`](DYNAMIC_DESTINATION_RUNBOOK.md) — activation operations
- [`DYNAMIC_DESTINATION_DEPLOY_CHECKLIST.md`](DYNAMIC_DESTINATION_DEPLOY_CHECKLIST.md) — dark deploy and progressive rollout
- [`BROWSER_TESTS.md`](BROWSER_TESTS.md) — Playwright layout/accessibility suite

---

## 1. Executive summary

Interlude already answers its core product question: given a traveller and a journey, what is the best use of the next part of this trip? The API implements the V1 acceptance criteria in [`ACCEPTANCE.md`](ACCEPTANCE.md) with a green suite (253 tests, 828 assertions at last full run). Discovery, scoring, itinerary planning, provenance, guest sessions, privacy erasure, and AI grounding are real.

What remains is the last mile between “engine works” and “a stranger can install the app, book something, keep an account across devices, and we can operate it in production”:

1. **Commercial close** — offer CTAs in the mobile app do not create bookings; they show an alert. The booking state machine exists and is tested.
2. **Account portability** — register/login APIs exist; the Expo app has no screens for them.
3. **Memory and privacy client completeness** — journal write, data export, and share are API-complete or trivial but unwired.
4. **Live commercial providers** — Google Places, Viator, and Anthropic need production keys and smoke verification; sandbox/rules cover CI.
5. **Destination activation GA** — dark-deployed at 0% rollout; allowlisted proof and progressive rollout remain.
6. **Store release** — privacy URL, deep-link association files, EAS production env, first AAB/IPA.
7. **Full V1+ surface** — notifications outbox dispatch, group trip votes, thin admin UI, hardening/SLOs.

This JEM sequences that work so each phase makes the product more useful before the next operational risk is taken.

```text
infra gates → book → accounts → memory/share → live providers
  → destination GA → store release → notifications → group votes
  → admin UI → hardening / SLOs
```

---

## 2. Problem statement

A traveller can onboard, get explainable recommendations, build a day around anchors, talk to a grounded guide, and save experiences — then hit a wall at the offer button, cannot create an account to keep that work across devices, and cannot complete a store-compliant commercial journey.

Operationally, destination activation and provider spend are gated correctly but not proven under traveller load. Store reviewers will reject builds that point at `REPLACE-ME` or loopback, or that bury account deletion and privacy policy.

If these gaps are not closed in order, engineering effort scatters across polish while the revenue and retention loops stay broken.

---

## 3. Product principles

1. **Wire before invent.** Prefer connecting existing API surfaces (`/bookings`, `/auth/*`, `/passport/journal/*`, `/privacy/export`, `/api/admin/*`) over new backends.
2. **Honest provenance.** Never show a price, opening time, or availability the system cannot attribute. Dynamic facts stay separated from descriptive content.
3. **Guest-first, then accounts.** Browsing, saving, planning, and booking work as a guest; registration adopts guest data.
4. **Degrade, do not invent.** Provider outages shrink capability; they do not fabricate catalogue content or AI facts.
5. **Kill switches over rollbacks.** Feature flags and activation kill switches stop new work without destroying ready data.
6. **Idempotent money paths.** Booking create requires an `Idempotency-Key`; retries must not double-book.
7. **Store compliance is product.** Privacy policy URL, account deletion, and deep-link association files are release blockers, not docs chores.
8. **Community infrastructure is protected.** Rate limits, identifying user agents, and budget ceilings for Overpass/Nominatim/Google are requirements.

---

## 4. Goals

### User goals

- A traveller can discover, plan, and **complete a booking or compliant redirect** without leaving a dead-end alert.
- A traveller can create an account and continue the same journey on another device.
- A traveller can journal, export their data, share an experience link, and delete location history or the account.
- A traveller can prepare an unseeded city and use it when ready (activation GA).
- Companions can vote on experiences for a shared trip (Full V1+).
- Travellers receive timely notices when a booking confirms, a city is ready, or a replan awaits (Full V1+).

### Product and operational goals

- Close V1 acceptance criterion **58** for travellers (API already proves it).
- Ship App Store and Play Store builds against a public HTTPS API.
- Run destination activation under progressive rollout with approved budgets.
- Give operators a thin UI over existing admin endpoints.
- Hold error budgets and provider spend within agreed ceilings.

### Initial success targets

| Metric | Target |
|---|---|
| End-to-end traveller journey functional % | ≥ 95% (from ~86%) |
| Criterion 58 exercised from mobile | 100% of offer CTAs either book or redirect |
| Booking create success (sandbox/staging) | ≥ 99% of valid idempotent retries return same booking |
| Account adoption of guest data | 100% of saves/journeys/bookings attached after register |
| Destination activation readiness (healthy Google) | ≥ 80% of activated tourism cities ready/limited within 90s |
| Store review blockers | Zero: privacy URL, delete account, real API URL |
| API suite | Green on release commit |
| Mobile typecheck + browser layout suite | Green on release commit |

---

## 5. Non-goals

- Rewriting the Experience Score or renaming the Experience domain.
- Changing Android/iOS identifiers (`uk.co.synteric.experience`) or production domains after first store upload.
- AI-generated place catalogues or inventing live facts in the guide.
- Building a second ticketing platform or CRM.
- Migrating geospatial search to PostGIS (cube/earthdistance remains; see [`ARCHITECTURE.md`](ARCHITECTURE.md)).
- Full social network features (follows, public feeds, chat).
- Replacing Leaflet/OSM maps with a paid native map SDK in this programme.
- Global `experience:sync-places all` as a production deploy step (activation + refresh only).

---

## 6. Personas and user stories

### Traveller (primary)

- As a traveller, I want to book or be handed to a supplier from an offer so that recommendations become a real plan.
- As a traveller, I want to register so my saves, trip, and bookings survive a new phone.
- As a guest, I want booking and planning to work before I create an account.
- As a traveller, I want to cancel a booking I no longer need, when the supplier allows it.
- As a traveller, I want to write a private journal note and export my data.
- As a traveller, I want to share an experience link that opens the app when installed.
- As a traveller, I want to prepare a city that was not preloaded and use it when ready.

### Companion traveller

- As a companion, I want to join a trip and vote must-do / interested / skip so the plan reflects the group.

### Operations administrator

- As an operator, I want to inspect failed imports, remaps, and recommendation explanations without curling the API.
- As an operator, I want kill switches for activation and booking providers when spend or error rates spike.

### Store reviewer

- As a reviewer, I want a working privacy policy URL, reachable account deletion, and an app that talks to a real HTTPS API.

---

## 7. Journey experience map

| Stage | Traveller action | Interlude response | System work | Failure recovery |
|---|---|---|---|---|
| Arrive | Install / open | Onboarding or Discover | Guest session mint (`X-Guest-Token`) | Retry network; show exact API URL on failure |
| Orient | Set destination, purpose, interests | Journey + trip created | Profile + journey APIs | Keep draft locally; retry submit |
| Discover | Browse now / time-boxed / mood / search | Ranked cards with reasons | CandidateBuilder + scorer | Loosen notice; provider degrade |
| Decide | Open experience | Descriptive vs dynamic facts | Experience presenter + offers | Degraded offer badge |
| Book | Tap offer CTA | Native confirm or supplier handoff | `POST /bookings` + idempotency | Replay same key; surface failure_reason |
| Plan | Generate / replan day | Timeline around anchors | Itinerary planner / replanner | Proposal until accept; never move confirmed bookings |
| Remember | Save, complete, journal | Passport updates | Passport + analytics | Offline queue events best-effort |
| Persist | Register / login | Guest data adopted | Auth + adoption | Clear validation errors |
| Notify | Leave app | Push/email for ready/booked/replan | Outbox dispatcher | Retry scheduled rows |
| Operate | (admin) inspect issues | Admin UI over `/api/admin/*` | Existing admin controllers | Kill switch / retry import |

---

## 8. Current baseline (evidence)

| Layer | Score | Evidence |
|---|---|---|
| API V1 acceptance (§35) | 100% | Every criterion mapped in [`ACCEPTANCE.md`](ACCEPTANCE.md); suite green |
| API domains (MVP core) | ~94% | 18 domains under `api/app/Domains/`; Notifications/group votes thin |
| Mobile traveller UI | ~78% | Screens for discover/trip/guide/passport; booking create stubbed |
| End-to-end traveller journey | ~86% | Functional autopsy Oct 2026 |
| Commercial booking path | ~55% | API complete; mobile Alert in `mobile/app/experience/[id].tsx` |
| Store / production readiness | ~70% | Dark deploy done; store env and activation GA incomplete |

**Known stub (P1 target):** offer button shows  
`Native checkout is wired end to end in the API` / supplier handoff copy via `Alert.alert` — no `POST /bookings`, no `Linking.openURL` for new redirect creates.

**Auth storage already prepared:** `mobile/src/api/client.ts` persists `experience.auth_token` and guest token; screens are missing.

**Schema already prepared:** `trip_members`, `trip_votes`, `notifications_outbox` tables exist; services/UI do not.

---

## 9. Phased implementation

Phases are sequential for go/no-go. Engineering may spike ahead in a branch, but a phase is not “done” until its exit criteria pass. Soft-launch usefulness is achieved at **P2 exit**; commercial GA at **P6 exit**; Full V1+ at **P10 exit**.

```text
P0 InfraGates → P1 BookingClose → P2 AuthAccounts → P3 MemorySharePrivacy
  → P4 LiveProviders → P5 DestinationGA → P6 StoreRelease
  → P7 Notifications → P8 GroupVotes → P9 AdminOpsUI → P10 HardeningSLOs
```

---

### P0 — Production infrastructure gates

**Objective.** Confirm the production host can run the product safely before any new traveller-facing capability is enabled.

**Done when.** Public HTTPS API answers `/api/health` with durable `queue_driver`, living queue workers, scheduler, secrets, backup restore point, and documented kill switches.

#### Work

- Verify `deploy.sh` document root is `api/public`; no directory listing.
- Confirm `GET /api/health` reports `engine_version`, providers, `queue_driver`, destination pipeline heartbeat fields (see [`api/routes/api.php`](../api/routes/api.php)).
- Confirm cron runs `php artisan schedule:run` every minute; workers drain `jobs` / `failed_jobs`.
- Confirm secrets: `APP_KEY`, DB, Redis if used, Sanctum, provider keys vaulted — not in the mobile bundle except `EXPO_PUBLIC_*`.
- Confirm backup/restore point before later phase deploys.
- Document kill switches: `DESTINATION_ACTIVATION_ENABLED`, provider config absence (Viator/Google inert without keys), assistant driver fallback to `rules`.

#### Tests / checks

- Manual health smoke against production URL.
- Deploy checklist pre-deploy items in [`DYNAMIC_DESTINATION_DEPLOY_CHECKLIST.md`](DYNAMIC_DESTINATION_DEPLOY_CHECKLIST.md).
- API suite green on the release commit.

#### Exit criteria / go-no-go

| Gate | Required |
|---|---|
| Health `queue_worker_alive` true under normal load | GO |
| `queue_driver` is durable (not `sync` in production) | GO |
| Backup restore point confirmed | GO |
| On-call owner assigned | GO |

**Rollback.** N/A (gates only). Do not proceed to P1 production traffic without GO.

---

### P1 — Close booking (highest product leverage)

**Objective.** A traveller can create a native or redirect booking from an experience offer and manage it in the Bookings tab.

**Done when.** Criterion **58** is true for mobile users; offer CTAs never dead-end on an informational alert.

#### User stories

- Tap “Book here” → confirm quantity/start → booking appears under Bookings.
- Tap “Continue to supplier” → booking created in redirect/awaiting_payment (or compliant handoff) → browser/supplier opens → resume via Bookings.
- Cancel an eligible booking from Bookings.

#### Technical work

| Area | Detail |
|---|---|
| Hooks | Add `useCreateBooking`, `useCancelBooking`, optional `useAvailability` in [`mobile/src/api/hooks.ts`](../mobile/src/api/hooks.ts) |
| Create | `POST /bookings` with header `Idempotency-Key` (UUID per user intent); body matches [`BookingController::store`](../api/app/Http/Controllers/Api/BookingController.php): `provider`, `provider_product_id`, `quantity`, optional `starts_at`, `journey_id`, `trip_id`, travellers/contact as required by provider |
| Offer UI | Replace Alert stub in [`mobile/app/experience/[id].tsx`](../mobile/app/experience/[id].tsx) `OfferCard` |
| Native path | Prefetch `GET /experiences/{id}/availability` when fulfilment is `native`; block confirm if sold out / unknown with honest copy |
| Redirect path | Create booking then `Linking.openURL` on `redirect_url` when present; Bookings tab already resumes `awaiting_payment` |
| Cancel | Wire `POST /bookings/{id}/cancel` from [`mobile/app/(tabs)/bookings.tsx`](../mobile/app/(tabs)/bookings.tsx) |
| Analytics | `recordEvents` for booking_started / booking_confirmed / booking_failed |

#### API (existing — do not reinvent)

```text
GET    /experiences/{experience}/availability
GET    /experiences/{experience}/offers
POST   /bookings                          Idempotency-Key required
GET    /bookings
GET    /bookings/{booking}
POST   /bookings/{booking}/cancel
```

#### Tests

- Extend or add Feature coverage only if client contract changes; existing [`BookingFlowTest`](../api/tests/Feature/BookingFlowTest.php), [`BookingResilienceTest`](../api/tests/Feature/BookingResilienceTest.php), [`BookingAvailabilityHonestyTest`](../api/tests/Feature/BookingAvailabilityHonestyTest.php) remain green.
- Mobile: unit/hook test or browser path that offer primary action does not show the legacy Alert copy.
- Manual: sandbox native book → confirmed; deeplink/redirect → awaiting_payment + open URL; cancel path.

#### Dependencies / kill switches

- Sandbox provider always available for CI and review builds.
- Viator/deeplink inert until P4 keys; UI must still work with sandbox products from [`ProviderProductSeeder`](../api/database/seeders/ProviderProductSeeder.php).

#### Exit criteria / go-no-go

| Gate | Required |
|---|---|
| Native create + list + detail works on device/simulator against staging | GO |
| Redirect create opens supplier URL | GO |
| Idempotent retry does not duplicate booking | GO |
| Cancel of cancellable booking succeeds | GO |
| No Alert stub remains on offer CTAs | GO |

**Rollback.** Feature-flag mobile booking CTAs to “offers unavailable” if provider 5xx > 2% for 5 minutes; API state machine unchanged.

---

### P2 — Auth and cross-device continuity

**Objective.** Travellers can register, log in, log out; guest work is adopted; account deletion remains reachable (already implemented).

**Done when.** A guest who saves and books can register and see the same data after reinstall + login.

#### Technical work

| Area | Detail |
|---|---|
| Screens | Add `mobile/app/account/register.tsx`, `login.tsx` (or sheet flow from You) |
| Entry | Guest note on [`mobile/app/(tabs)/you.tsx`](../mobile/app/(tabs)/you.tsx) links to register; login link for returning users |
| Client | Use existing `setAuthToken` / `clearIdentity` in [`mobile/src/api/client.ts`](../mobile/src/api/client.ts); send Bearer token on requests (already supported if wired) |
| API | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout` — [`AuthController`](../api/app/Http/Controllers/Api/AuthController.php) already adopts guest data |
| Logout | Clear auth token; mint fresh guest session on next call |
| Delete | Keep [`mobile/app/account/delete.tsx`](../mobile/app/account/delete.tsx) as store-required path |

#### Tests

- Feature: register adopts guest saves (existing guest adoption tests must stay green).
- Mobile: register → kill app → login → saved list non-empty.
- Typecheck clean.

#### Exit criteria / go-no-go

| Gate | Required |
|---|---|
| Register + login + logout work on device | GO |
| Guest saves/journeys/bookings visible after register | GO |
| Delete account still reachable from You | GO |
| Soft-launch usefulness bar met (discover → plan → book → account) | GO for soft launch |

**Rollback.** Hide register/login entry points; guest mode remains default.

---

### P3 — Memory, share, privacy completeness

**Objective.** Close remaining client gaps for passport journal, privacy export, and share.

**Done when.** Traveller can journal privately, export data, and share an experience deep link.

#### Technical work

| Feature | API | Mobile |
|---|---|---|
| Journal | `POST /passport/journal/{experience}` ([`PassportController`](../api/app/Http/Controllers/Api/PassportController.php)) | Compose UI from experience detail or passport; privacy default private (see [`AnalyticsAndPassportTest`](../api/tests/Feature/AnalyticsAndPassportTest.php)) |
| Recap | `GET /passport/recap/{journey}` | Surface on passport or trip end state if not already shown |
| Export | `GET /privacy/export` ([`PrivacyController`](../api/app/Http/Controllers/Api/PrivacyController.php); tested in [`PrivacyTest`](../api/tests/Feature/PrivacyTest.php)) | You → “Download my data”; share sheet / save file |
| Share | Deep link `https://experience.synteric.co.uk/experience/{id}` per [`RELEASE.md`](RELEASE.md) | Replace share Alert in experience hero with `Share.share` / Expo sharing |
| Location delete | Already wired | Keep |

#### Tests

- Feature journal privacy default remains.
- Mobile: journal create visible on passport; export returns JSON/file; share sheet opens.
- Browser suite: new routes added to [`mobile/tests/browser/routes.ts`](../mobile/tests/browser/routes.ts) if screens added.

#### Exit criteria / go-no-go

| Gate | Required |
|---|---|
| Journal create + read path works | GO |
| Privacy export reachable in-app | GO |
| Share uses production experience URL pattern | GO |
| Share Alert stub removed | GO |

**Rollback.** Hide journal/export entry points; share can fall back to copying URL.

---

### P4 — Live commercial providers

**Objective.** Production uses real place ratings and bookable inventory where keys exist; AI guide can use Anthropic with grounding still mandatory.

**Done when.** Staging smoke proves Google Places, at least one commercial experience provider (Viator and/or deeplink), and optional Anthropic driver; failures degrade cleanly.

#### Technical work

| Provider | Config | Behaviour |
|---|---|---|
| Google Places | `GOOGLE_PLACES_API_KEY` in [`config/experience.php`](../api/config/experience.php) | Becomes place-data source when configured ([`ARCHITECTURE.md`](ARCHITECTURE.md)) |
| Viator | `VIATOR_API_KEY` | Ticketing; contract tests in ExternalSources |
| Deeplink affiliate | `experience.providers.deeplink.*` | Compliant redirect fulfilment |
| Anthropic | `ANTHROPIC_API_KEY` + assistant driver | Tool-calling loop; `GroundingGuard` still strips unsourced facts |
| Fallbacks | No key → OSM / sandbox / `rules` driver | CI unchanged |

#### Ops

- Approve daily spend ceilings (Google per-activation limit already referenced in deploy checklist).
- Staging/prod smoke: [`PROVIDER_SMOKE_RUNBOOK.md`](PROVIDER_SMOKE_RUNBOOK.md) — health, `experience:provider-status`, offers, sandbox booking dry-run, grounded guide question.
- Never commit keys; rotation steps are in that runbook.
- Queue: durable `database` driver + minute cron `schedule:run` so `queue_worker_alive` stays true.

#### Tests

- Existing provider feature tests with fixtures stay green.
- [`ProviderStatusCommandTest`](../api/tests/Feature/ProviderStatusCommandTest.php) covers no-secrets status + queue gate.
- Staging smoke checklist signed off via runbook (not CI-live).

#### Exit criteria / go-no-go

| Gate | Required |
|---|---|
| Google configured **or** explicit accept OSM-only ratings gap | GO |
| At least one non-sandbox fulfilment path configured for prod **or** sandbox clearly labelled for review | GO |
| Assistant driver documented; grounding tests green | GO |
| Provider outage still isolates discovery ([`ProviderResilienceTest`](../api/tests/Feature/ProviderResilienceTest.php)) | GO |
| Queue healthy (`queue_worker_alive` on durable driver) | GO |

**Rollback.** Remove/blank keys; registry falls back. Keep sandbox for store demo accounts if needed.

**Status (2026-10-08).** Production keys live: Google Places, Viator, Anthropic. Deeplink optional while Viator covers commercial fulfilment. Runbook + `experience:provider-status` shipped. Prod health `ok` with `queue_worker_alive: true`. `deploy.sh` kicks `schedule:run` after `queue:restart` so the post-deploy health gate is not flaky.

---

### P5 — Destination activation GA

**Objective.** Complete the programme in [`DYNAMIC_DESTINATION_DEPLOY_CHECKLIST.md`](DYNAMIC_DESTINATION_DEPLOY_CHECKLIST.md) and [`DYNAMIC_DESTINATION_RUNBOOK.md`](DYNAMIC_DESTINATION_RUNBOOK.md). Do not build a second pipeline.

**Done when.** Allowlisted city proven end-to-end; progressive rollout to 100% under budget; kill switch verified.

#### Work (execute checklist — summary)

1. Add controlled city to `DESTINATION_ACTIVATION_ALLOWED_CITIES`.
2. Activate → import queued → running → ready/limited; app resume polls same import.
3. Admin: `/api/admin/destination-imports`, destination-quality, retry.
4. Rollout 5% → 25% → 100% with 30+ minute observation windows.
5. Keep `DESTINATION_PREWARM_ENABLED=false` until aggregate-demand retention is approved.
6. Confirm scheduler recovery for stale imports.

#### Exit criteria / go-no-go

| Gate | Required |
|---|---|
| One allowlisted city full cycle | GO |
| No import stuck > 15 minutes after recovery | GO |
| Duplicate destination rate below JEM target (<1%) | GO |
| Daily provider spend within ceiling | GO |
| Rollout percentage recorded in release notes | GO |

**Rollback.** `DESTINATION_ACTIVATION_ENABLED=false`; retain ready destinations (see checklist rollback triggers).

---

### P6 — Store release

**Objective.** Ship production Android/iOS builds that reviewers and travellers can use.

**Done when.** AAB/IPA uploaded with real API + privacy URLs; deep-link association files verify; listing complies with account deletion and privacy.

#### Work (from [`RELEASE.md`](RELEASE.md))

1. Publish privacy HTML from `php artisan experience:export-legal` to owned domain; set `PRIVACY_POLICY_URL` and `EXPO_PUBLIC_PRIVACY_URL`.
2. Set `EXPO_PUBLIC_API_URL` in EAS production profile to `https://…/api` (never `REPLACE-ME` or loopback).
3. First EAS Android build → capture SHA-256 → `APP_LINKS_ANDROID_SHA256`.
4. Set `APP_LINKS_IOS_TEAM_ID`; verify `/.well-known/assetlinks.json` and `apple-app-site-association` over HTTPS, no redirect.
5. Claim only `/experience/*` paths that have screens.
6. Store listing: screenshots, description, privacy URL, data safety / privacy nutrition labels aligned with actual collection.
7. Review account: sandbox booking path + delete account demo.

#### Tests / checks

```bash
curl -sI https://experience.synteric.co.uk/.well-known/assetlinks.json
cd mobile && npm run typecheck && npm run test:browser   # API up
cd api && php artisan test --compact
```

#### Exit criteria / go-no-go

| Gate | Required |
|---|---|
| Production build hits public API successfully on device | GO |
| Privacy policy opens in-app and in browser | GO |
| Account deletion reachable | GO |
| Deep link association `verified` (Android) / valid AASA (iOS) | GO |
| Commercial GA bar met (P1–P6) | GO for commercial GA |

**Rollback.** Pause store rollout; point app update at previous API if needed; keep activation kill switch independent.

---

### P7 — Notifications outbox

**Objective.** Turn `notifications_outbox` into a real dispatcher for high-value events.

**Done when.** At least three event kinds are enqueued and delivered (or safely retried): booking confirmed, destination import ready/limited, replan proposal available.

#### Technical work

| Area | Detail |
|---|---|
| Model | Existing [`NotificationOutbox`](../api/app/Domains/Notifications/Models/NotificationOutbox.php) |
| Service | `NotificationDispatcher` (or similar): enqueue → claim due rows → send → mark `sent` / `failed` |
| Channels | Start with **email** for registered users and **Expo push** when a push token is registered; guests may receive in-app-only until push token exists |
| Triggers | Booking state → confirmed; destination import → ready/limited; trip replan proposal created |
| Client | Register Expo push token endpoint (new thin route) + permission prompt post-booking or post-onboarding |
| Scheduler | `schedule` command to flush outbox every minute |

#### Non-goals for P7

- Marketing campaigns, digests, or SMS.
- Guaranteed delivery to guests without push permission.

#### Tests

- Unit: enqueue idempotent per `(kind, aggregate_id)`.
- Feature: booking confirm creates outbox row; worker marks sent with fake mailer/push.
- Failure: provider exception leaves row retryable with backoff.

#### Exit criteria / go-no-go

| Gate | Required |
|---|---|
| Booking confirmed notification path works in staging | GO |
| Import ready notification path works | GO |
| Failed send retries without duplicate user spam (idempotency key) | GO |

**Rollback.** Stop scheduler flush; rows remain `scheduled`. Product remains usable without push.

---

### P8 — Group trip votes

**Objective.** Companions can join a trip and vote on experiences using existing schema.

**Done when.** Owner invites companion → companion votes `must_do` / `interested` / `skip` → votes visible on trip; planner may prefer must-do (soft signal, not hard constraint unless already supported).

#### Schema (existing)

From [`2026_01_01_000800_create_trip_and_itinerary_tables.php`](../api/database/migrations/2026_01_01_000800_create_trip_and_itinerary_tables.php):

- `trip_members`: `trip_id`, `user_id`, `display_name`, `role`
- `trip_votes`: unique `(trip_member_id, experience_id)`, vote enum `must_do|interested|skip`

#### Technical work

| Area | Detail |
|---|---|
| API | `POST /trips/{trip}/members` (invite by code or email), `GET /trips/{trip}/members`, `PUT /trips/{trip}/votes` |
| Auth | Members must be registered users (votes need stable identity); guests can view if owner shares read-only code (optional) |
| Service | Domain service under `api/app/Domains/Trips/` using [`TripMember`](../api/app/Domains/Trips/Models/TripMember.php) / [`TripVote`](../api/app/Domains/Trips/Models/TripVote.php) |
| Mobile | Trip screen: invite code + vote chips on candidates/saved |
| Scoring (optional stretch) | Soft boost must_do in journey_purpose or companion component — only if weights remain summing to 1.0 |

#### Tests

- Feature: member upsert, vote upsert, unauthorized outsider rejected.
- Planner: confirmed bookings still immovable; votes never schedule over anchors.

#### Exit criteria / go-no-go

| Gate | Required |
|---|---|
| Two users can vote on same trip | GO |
| Votes persist and display | GO |
| No PII leaked via invite code beyond trip title/destination | GO |

**Rollback.** Disable member/vote routes via flag; solo trip UX unchanged.

---

### P9 — Admin / ops UI

**Objective.** Operators use a thin authenticated UI over existing admin API — not curl.

**Done when.** An admin can inspect destination imports, retry failures, view sync failures, merge candidates, remap provider entities, and explain a recommendation set.

#### Technical work

| Area | Detail |
|---|---|
| Surface | Prefer a small **web** admin (Blade/Inertia or simple authenticated Expo web route) separate from traveller tabs |
| Auth | Sanctum user with `admin` ability (already enforced on `/api/admin/*`) |
| Endpoints | As in [`API.md`](API.md): providers, destination-imports, destination-quality, sync-failures, merge-candidates, external-entities remap, recommendation-sets explain |
| UX | Tables + detail drawers; no redesign of traveller app |

#### Tests

- Existing [`AdminOperationsTest`](../api/tests/Feature/AdminOperationsTest.php) remains source of truth for API.
- Smoke: admin login → each page loads against staging.

#### Exit criteria / go-no-go

| Gate | Required |
|---|---|
| Admin can retry a failed import from UI | GO |
| Admin can explain a recommendation set | GO |
| Traveller tokens still cannot access admin | GO |

**Rollback.** Disable admin UI deploy; API admin remains.

---

### P10 — Hardening and SLOs

**Objective.** Production stays within error budgets; Full V1+ signed off against acceptance.

**Done when.** SLOs monitored, load/chaos notes filed, final acceptance matrix checked, functional % ≥ 95% re-measured.

#### Work

- Define SLOs: API availability, discovery p95, booking create success, activation success, queue lag.
- Alerts: 5xx rate, queue lag, failed_jobs growth, provider circuit open, activation stuck imports (align with destination checklist triggers).
- Load test: discovery now + time-boxed; booking create idempotency under concurrency.
- Chaos: kill Viator/Google in staging; confirm discovery still serves; offers degrade.
- Mobile offline/empty-state audit on primary tabs.
- Re-run acceptance mapping in [`ACCEPTANCE.md`](ACCEPTANCE.md); add any new Feature tests for P7–P9.
- Update README test counts and functional baseline note.

#### Exit criteria / go-no-go

| Gate | Required |
|---|---|
| SLO dashboards live with on-call runbooks | GO |
| Full API suite + mobile typecheck + browser suite green | GO |
| Acceptance §35 still proven; 58 true on mobile | GO |
| Functional autopsy re-score ≥ 95% E2E | GO |
| Full V1+ programme complete | GO |

**Rollback.** Per-subsystem kill switches from earlier phases; no single big-bang rollback.

---

## 10. Cross-cutting requirements

### Security

- Public tokens carry `traveller` ability only; admin requires `admin` ability ([`ARCHITECTURE.md`](ARCHITECTURE.md)).
- `Idempotency-Key` mandatory on booking create.
- Candidate tokens for destination activation remain signed and short-lived.
- No provider secrets in mobile binaries.
- Rate limits: `throttle:api` plus stricter activation limits.

### Privacy

- Location precision: precise / approximate / off (client already).
- Journal private by default.
- Export and delete account reachable in-app (store requirement).
- Privacy policy URL on owned domain outlives API host changes ([`RELEASE.md`](RELEASE.md)).

### Observability

- `/api/health` for liveness and capability.
- Structured logs for booking transitions, activation stage changes, outbox send failures.
- Admin recommendation explain for after-the-fact audit (`GET /api/admin/recommendation-sets/{set}`).

### Provider budgets

- Google Places: per-activation result limit + daily ceiling before P5 GA.
- Nominatim/Overpass: respect [`OutboundHttp`](../api/app/Domains/ExternalSources) rate limits; refuse rather than queue abuse.
- Viator: monitor book failures separately from discovery.

---

## 11. Rollout and go/no-go matrix

| Milestone | Minimum phases complete | May ship to |
|---|---|---|
| Internal dogfood | P0–P1 | Team devices → staging |
| Soft launch (useful) | P0–P2 | Closed testers; optional TestFlight/Internal testing |
| Memory complete | P0–P3 | Same cohort |
| Commercial providers | P0–P4 | Staging proof; prod keys live |
| Coverage GA | P0–P5 | Progressive traveller % |
| Store GA | P0–P6 | App Store / Play production |
| Full V1+ | P0–P10 | General availability with ops UI + notifications + votes |

**Global no-go (any milestone):** API suite red on release commit; production API URL wrong in binary; privacy policy unreachable; booking create double-charges; activation duplicate storm; queue workers dead.

---

## 12. Testing strategy

| Layer | When | Command / artefact |
|---|---|---|
| API Feature/Unit | Every phase | `cd api && php artisan test --compact` |
| Narrow booking/auth | P1–P2 | `--filter=Booking` / auth + guest adoption |
| Mobile types | Every mobile phase | `cd mobile && npm run typecheck` |
| Browser layout | Before store (P6) and when routes added | `npm run test:browser` ([`BROWSER_TESTS.md`](BROWSER_TESTS.md)) |
| Staging smoke | P4–P6 | Runbook: health, activate city, book sandbox, guide, deep link |
| Load/chaos | P10 | Discovery + booking; provider kill |

CI never depends on live Overpass/Nominatim/Viator; use fixtures as today.

---

## 13. Risk register

| Risk | Impact | Mitigation |
|---|---|---|
| Mobile booking wired incorrectly → double booking | High | Idempotency-Key per intent; Feature resilience tests; disable CTA flag |
| Store build with REPLACE-ME API URL | High | EAS env checklist; smoke on physical device before submit |
| Provider spend runaway on activation GA | High | Allowlist → % rollout; daily ceiling; kill switch |
| Anthropic invents facts | High | GroundingGuard mandatory; prefer `rules` driver if unsure |
| Notification spam | Medium | Idempotent outbox keys; quiet hours later |
| Group invite abuse | Medium | Auth required; rate-limit invites; opaque codes |
| Admin UI expands into second product | Medium | Thin wrapper over existing admin API only |
| Scope creep before P1 | High | Do not start P7–P9 until soft-launch (P2) exit |

---

## 14. Rollback summary

| Phase | Fast rollback |
|---|---|
| P1 | Hide booking CTAs / flag |
| P2 | Hide auth entry; guest continues |
| P3 | Hide journal/export/share |
| P4 | Blank provider keys |
| P5 | `DESTINATION_ACTIVATION_ENABLED=false` |
| P6 | Halt store rollout; previous binary |
| P7 | Stop outbox scheduler |
| P8 | Flag off member/vote routes |
| P9 | Undeploy admin UI |
| P10 | Tune alerts; revert risky flags |

Schema rollbacks are last resort; prefer flags.

---

## 15. Acceptance criteria (programme-level)

1. **Given** an experience with a native sandbox offer, **when** the traveller confirms in the app, **then** a booking is created and listed under Bookings without an informational-only Alert.
2. **Given** a redirect offer, **when** the traveller continues, **then** they are handed to the supplier (or receive `redirect_url`) and can resume from Bookings.
3. **Given** a guest with saves and a booking, **when** they register, **then** those records remain available after login on a fresh install.
4. **Given** a completed experience, **when** the traveller journals, **then** the entry is private by default and visible in passport.
5. **Given** the privacy export action, **when** invoked, **then** the traveller receives their data export payload.
6. **Given** share on an experience, **when** invoked, **then** the system shares a production `/experience/{id}` link.
7. **Given** production provider keys, **when** a supplier fails, **then** discovery continues and offers for that supplier degrade.
8. **Given** an unseeded allowlisted city, **when** activated, **then** import reaches ready/limited and discovery works.
9. **Given** a production app binary, **when** installed, **then** it calls the public HTTPS API and opens the privacy policy URL.
10. **Given** a confirmed booking, **when** outbox runs, **then** a notification row is sent or retried without duplicate spam.
11. **Given** two trip members, **when** they vote, **then** votes persist and display on the trip.
12. **Given** an admin user, **when** they use the admin UI, **then** they can retry imports and explain a recommendation set.
13. **Given** the release commit, **when** CI runs, **then** API tests, mobile typecheck, and browser suite pass.
14. **Given** Full V1+ complete, **when** functional % is re-measured, **then** end-to-end traveller journey scores ≥ 95%.

---

## 16. Definition of done (Full V1+)

- P0–P10 exit criteria all GO.
- [`ACCEPTANCE.md`](ACCEPTANCE.md) criteria 51–65 remain proven; 58 proven from mobile.
- Store listings live or approved for production release.
- Destination activation at intended rollout % with budgets and kill switch documented.
- Notifications, group votes, and admin UI shipped at the scope above (not marketing-grade social).
- Runbooks updated: booking incidents, activation, provider outage, store credential backup (`eas credentials`).
- README and this JEM status updated to **Implemented** with date.

---

## 17. Recommended implementation sequence (engineering order)

1. **P0** confirm prod health, queue, backups, owners.
2. **P1** mobile booking hooks + OfferCard + cancel + analytics events.
3. **P2** register/login/logout screens on existing auth token storage.
4. **P3** journal, export, share sheet.
5. **P4** configure keys + staging smoke; keep fixtures for CI.
6. **P5** execute destination deploy checklist progressive rollout.
7. **P6** privacy publish, EAS prod env, signing fingerprints, store submit.
8. **P7** outbox dispatcher + three event kinds + push token registration.
9. **P8** trip members/votes API + minimal Trip UI.
10. **P9** thin admin web over `/api/admin/*`.
11. **P10** SLOs, load/chaos, acceptance re-sign, functional re-score.

---

## 18. Open questions

### Blocking before P4/P5

1. **Finance:** Approved Google Places daily ceiling and per-activation result limit?
2. **Commercial:** Viator vs deeplink-first for first store cohort?
3. **Infrastructure:** Is the current host’s queue worker reliability sufficient for activation GA, or must workers move?

### Blocking before P6

4. **Legal:** Privacy policy final copy and `EXPERIENCE_PRIVACY_UPDATED` date?
5. **Store:** Apple/Google account access and listing copy owner?

### Non-blocking

6. **P7 channel:** Email-first vs Expo push-first for MVP notifications?
7. **P8 invites:** Invite code vs email magic link?
8. **P9 stack:** Blade admin vs separate small React admin on the API host?
9. **P10:** Where are SLO dashboards hosted (existing APM vs logs-only)?

### Defaults if unanswered

| Question | Default |
|---|---|
| 2 | Sandbox + deeplink affiliate for review; Viator when key ready |
| 6 | Expo push for import-ready; email for booking confirmed when user has email |
| 7 | Opaque invite code, 7-day expiry |
| 8 | Blade/Inertia on API host behind admin ability |
| 9 | Log-based alerts from health + failed_jobs first; APM later |

---

## 19. Status tracking

| Phase | Name | Status |
|---|---|---|
| P0 | Production infrastructure gates | GO (health ok, durable queue, workers alive; 2026-10-08) |
| P1 | Close booking | GO — mobile create/cancel/availability wired; deployed 2026-10-08 |
| P2 | Auth and cross-device continuity | GO — register/login/logout wired; deployed 2026-10-08 |
| P3 | Memory, share, privacy | GO — journal/export/share/privacy; merged + deployed 2026-10-08 |
| P4 | Live commercial providers | GO — keys live; runbook + provider-status; queue heartbeat green (2026-10-08) |
| P5 | Destination activation GA | Not started (dark deploy done separately) |
| P6 | Store release | Not started |
| P7 | Notifications outbox | Not started |
| P8 | Group trip votes | Not started |
| P9 | Admin / ops UI | Not started |
| P10 | Hardening and SLOs | Not started |

Update this table as phases exit GO.
