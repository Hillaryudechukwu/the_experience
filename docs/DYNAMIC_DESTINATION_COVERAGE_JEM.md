# Interlude — Dynamic Destination Coverage JEM

**Status:** Proposed implementation specification
**Scope:** Laravel API, Expo clients, place-data ingestion, AI grounding, operations
**Objective:** Let a traveller search for and use a tourism destination that is not already seeded, without treating unverified or empty data as usable coverage.

## 1. Executive summary

Interlude currently has two disconnected capabilities:

1. it can geocode almost any city through Nominatim; and
2. it can ingest real places through Google Places or OpenStreetMap, enrich them through Wikimedia, and turn grounded records into experiences.

The missing link is destination activation. A geocoded city is returned as `elsewhere`, has no destination ID, cannot be selected by the client, and is never passed into the existing ingestion pipeline. Consequently, the seeded destination table acts as a hard product boundary even though the providers are global.

This project replaces that boundary with an on-demand coverage lifecycle:

```text
search → resolve city → activate destination → ingest places → enrich → publish usable results
```

Search stays read-only and inexpensive. Import begins only after an explicit traveller action. Work runs asynchronously, is deduplicated, and reports progress. Interlude shows a destination as ready only after it has a minimum useful set of grounded, published experiences. AI may interpret intent, rank results, and explain recommendations; it may not invent destination facts or bypass provider provenance.

## 2. Problem statement

A traveller can enter a real city such as Reykjavík and see that Interlude found it, but cannot select it or receive recommendations. This creates a false distinction between seeded and API-discovered destinations and makes a global travel product appear limited to a manually maintained list.

If this is not fixed, every new market requires a code change, deployment, database seed, and manual sync. Coverage growth remains operationally expensive, users encounter dead ends, and the value of the existing provider and AI infrastructure is underused.

## 3. Product principles

1. **Any genuine city can be requested.** A seed is a warm cache, not a whitelist.
2. **Selection, not typing, causes work.** Search keystrokes must never trigger billed ingestion.
3. **Grounding precedes AI.** Providers establish facts; AI personalises and explains them.
4. **Empty is not covered.** A destination is ready only when usable published experiences exist.
5. **One city, one import.** Concurrent requests converge on the same destination and job.
6. **Partial service beats a false promise.** Failures produce explicit limited or retry states.
7. **Provenance survives every layer.** Source, freshness, licence, and attribution reach the client.
8. **Community infrastructure is protected.** Rate limits, caching, and provider policies are product requirements.

## 4. Goals

### User goals

- A traveller can find and select a valid tourism city even when it was not preloaded.
- A first-time destination becomes useful without an application release or database seed.
- The traveller sees honest progress and can continue using the app while import runs.
- Results contain grounded places and sourced descriptions rather than AI-generated venue claims.

### Product and operational goals

- Reduce addition of a new destination from a deployment task to an API workflow.
- Reuse the existing `PlaceDataProvider`, `PlaceEnricher`, `PlaceResolver`, and `IngestPlaces` pipeline.
- Keep duplicate destinations and duplicate provider spend below measurable thresholds.
- Make every activation observable, retryable, and inspectable by operations.

### Initial success targets

- At least 90% of supported city selections return an activation response within 1 second, excluding network latency.
- At least 80% of successfully geocoded tourism cities reach `ready` within 90 seconds when Google Places is healthy.
- Fewer than 1% of activations create a duplicate destination.
- Fewer than 2% of ready destinations contain no published experience.
- No ungrounded price, opening time, rating, address, or availability is introduced by this workflow.

## 5. Non-goals

- **Import on every keystroke.** This is slow, expensive, abusive to public services, and vulnerable to automated misuse.
- **AI-generated place catalogues.** AI cannot be the source of venue existence or dynamic facts.
- **Complete editorial city guides on first import.** Neighbourhood essays, city essentials, and signature lists remain separate editorial or sourced enrichment work.
- **Guaranteed support for every settlement.** Provider coverage, licensing, safety controls, and minimum-content thresholds can leave a destination unavailable.
- **Changing the current provider contracts.** The feature orchestrates the existing place and enrichment abstractions instead of replacing them.
- **Renaming `Experience` domain models or API routes.** “Experience” remains a product concept inside Interlude.
- **Changing Android/iOS application identifiers or production domains.** Those are stable deployment identities.

## 6. Personas and user stories

### Traveller planning a trip

- As a traveller, I want to search for any city I plan to visit so that Interlude is not limited to a predetermined list.
- As a traveller, I want a precise city-and-country choice so that I do not activate the wrong city with a shared name.
- As a traveller, I want to select a newly found city so that Interlude can prepare recommendations for it.
- As a traveller, I want visible progress so that an import does not look like a frozen or broken app.
- As a traveller, I want to leave the progress screen and return later without losing the activation.
- As a traveller, I want a clear retry or alternative when a provider is unavailable.

### Traveller using a ready destination

- As a traveller, I want only usable destinations shown as covered so that I do not enter an empty discovery screen.
- As a traveller, I want recommendations to explain why they fit my journey rather than merely list popular places.
- As a traveller, I want source and freshness information so that I can distinguish verified facts from editorial guidance.

### Operations administrator

- As an operator, I want to inspect active, failed, and partial imports so that I can resolve provider or data-quality problems.
- As an operator, I want to retry a failed import safely so that retries do not duplicate places or incur uncontrolled spend.
- As an operator, I want provider usage and cost signals by destination so that coverage can be managed sustainably.

## 7. Journey experience map

| Stage | Traveller action | Interlude response | System work | Failure recovery |
|---|---|---|---|---|
| Discover | Types a city or country | Debounced local and remote results, labelled `Ready`, `Can prepare`, or `Unavailable` | Search catalogue first; geocode only when needed | Preserve catalogue results if geocoder fails |
| Disambiguate | Chooses “Paris, France” rather than another Paris | Shows country and region before confirmation | Validate result type, normalise identity, reject non-travellable entities | Ask traveller to refine the search |
| Activate | Taps “Prepare this city” | Returns immediately with destination/import IDs | Upsert destination, acquire lock, dispatch one import job | Existing import is returned instead of duplicated |
| Prepare | Waits or continues elsewhere | Progress: finding places, enriching details, preparing recommendations | Ingest, resolve, enrich, derive, publish, compute quality gate | Retry transient failures; expose honest partial state |
| Enter | Opens the destination when ready | Destination is selected and discovery becomes available | Confirm readiness and bind journey destination | Do not enter an empty discovery state |
| Explore | Requests recommendations | Ranked, contextual results with sources | Existing candidate, score, routing, weather, and AI systems run | Normal provider degradation rules apply |
| Return | Opens the app later | Previous activation resumes or opens ready city | Client refreshes import state; API remains authoritative | Expired/failed work offers retry |

## 8. Experience states and copy

### Search result states

- **Ready:** “Available now” — destination has met the readiness threshold.
- **Preparing:** “Preparing recommendations” — import is queued or running.
- **Discoverable:** “Prepare this city” — valid geocoded destination, not yet activated.
- **Limited:** “A few recommendations are available” — some grounded content exists below the normal threshold.
- **Unavailable:** “We couldn’t prepare this city yet” — terminal failure or unsupported provider coverage.

The phrase “not covered yet” should be removed from selectable results. It describes an internal catalogue state rather than the action available to the traveller.

### Import progress stages

Progress is stage-based, not a fabricated percentage:

1. `queued` — request accepted;
2. `discovering_places` — canonical place provider running;
3. `resolving_places` — duplicates and provider identities being resolved;
4. `enriching_content` — Wikimedia descriptions and licensed imagery being fetched;
5. `evaluating_readiness` — published results and quality thresholds being checked;
6. `ready`, `limited`, or `failed`.

## 9. Functional requirements

### P0 — search and resolution

#### P0.1 Unified destination search

`GET /api/destinations?q={term}` continues to return stored destinations and adds normalized remote results when the local result is absent or clearly incomplete.

Every item must have a stable discriminated shape:

```json
{
  "kind": "destination",
  "source": "catalogue",
  "coverage_status": "ready",
  "id": "uuid",
  "slug": "paris",
  "name": "Paris",
  "country": "France",
  "country_code": "FR",
  "region": "Île-de-France",
  "lat": 48.8566,
  "lng": 2.3522
}
```

```json
{
  "kind": "destination_candidate",
  "source": "nominatim",
  "coverage_status": "discoverable",
  "candidate_token": "signed-short-lived-token",
  "name": "Reykjavík",
  "country": "Iceland",
  "country_code": "IS",
  "region": "Capital Region",
  "lat": 64.1466,
  "lng": -21.9426
}
```

Acceptance criteria:

- Results never mix incompatible objects without a `kind` discriminator.
- Roads, shops, buildings, postboxes, and other non-travellable entities are excluded.
- City-country duplicates are collapsed.
- Catalogue results require no external request when they answer the query.
- Remote failure never removes catalogue results.
- Search is debounced client-side and rate-limited server-side.

#### P0.2 Candidate integrity

The client must not be trusted to post arbitrary coordinates or provider identifiers. Remote results receive a signed, short-lived `candidate_token` containing normalized provider data. Activation verifies the signature and expiry.

Acceptance criteria:

- A modified, expired, or replay-abused token is rejected with a clear 422 or 429 response.
- The token contains no provider secret or personal information.
- The API revalidates allowable place type and coordinate bounds.

### P0 — activation and import

#### P0.3 Activate a destination

Add:

```text
POST /api/destinations/activate
```

Request:

```json
{
  "candidate_token": "signed-short-lived-token"
}
```

Response, HTTP 202 for new or running work:

```json
{
  "data": {
    "destination_id": "uuid",
    "destination_slug": "reykjavik-is",
    "coverage_status": "importing",
    "import_id": "uuid",
    "stage": "queued",
    "poll_after_seconds": 3
  }
}
```

Response, HTTP 200 when already usable:

```json
{
  "data": {
    "destination_id": "uuid",
    "destination_slug": "reykjavik-is",
    "coverage_status": "ready",
    "import_id": null
  }
}
```

Acceptance criteria:

- Activation returns within one second under normal database load.
- Repeated activation returns the existing destination and active import.
- Two concurrent activations cannot create two destination rows or two active imports.
- Slugs are deterministic and collision-safe; ambiguous city names include country or region.
- Activation never waits synchronously for Google, OSM, Wikimedia, or AI.

#### P0.4 Durable import orchestration

Add a queued `ActivateDestination` workflow that calls the existing ingestion services. The job must be retryable, unique per destination while active, and protected by an atomic lock.

Default source order:

1. configured `PlaceDataProvider` (`google_places` when configured, otherwise `osm`);
2. `PlaceResolver` for canonical identity and duplicate protection;
3. `PlaceEnricher` for sourced descriptions and licensed imagery;
4. `ExperienceDraftFactory` for derived, bounded experience attributes;
5. readiness evaluation.

Acceptance criteria:

- One failed place record does not abort the entire import.
- Transient provider failures retry with exponential backoff and jitter.
- Permanent validation failures do not retry indefinitely.
- Re-running the workflow is idempotent and does not overwrite editorial records.
- Provider attribution and source timestamps are stored with imported data.

#### P0.5 Import status

Add:

```text
GET /api/destination-imports/{import}
```

Response:

```json
{
  "data": {
    "id": "uuid",
    "destination_id": "uuid",
    "status": "running",
    "stage": "enriching_content",
    "records_seen": 20,
    "places_created": 14,
    "experiences_published": 8,
    "experiences_pending_content": 6,
    "started_at": "2026-10-03T12:00:00Z",
    "finished_at": null,
    "retryable": false,
    "message": "Preparing sourced descriptions and images."
  }
}
```

Acceptance criteria:

- Travellers can read only imports they initiated or imports for public destinations.
- Internal exception messages, keys, raw provider bodies, and billing data are never exposed.
- Polling is rate-limited and returns `Retry-After` or `poll_after_seconds` guidance.
- Completed imports remain queryable long enough for clients returning from background.

### P0 — readiness and discovery

#### P0.6 Coverage state machine

Destination coverage uses explicit states:

```text
discovered → queued → importing → ready
                              ↘ limited
                              ↘ failed
failed/limited → queued       (manual or eligible automatic retry)
```

Allowed transition rules belong in one domain service and are unit tested. A `ready` destination cannot silently fall back to `discovered`; refresh failure preserves its last known usable catalogue while marking freshness separately.

#### P0.7 Readiness quality gate

Initial configurable threshold:

- at least 5 published experiences;
- at least 3 distinct experience kinds;
- every published experience has coordinates and a non-empty grounded description;
- no unresolved merge candidate is counted toward readiness;
- at least one source attribution is present per published experience.

Outcomes:

- `ready`: threshold met;
- `limited`: 1–4 published experiences, still usable with an honest notice;
- `failed`: no publishable result after retries or a terminal provider error.

The threshold must live in configuration, not be duplicated across controller, job, and client.

#### P0.8 Discovery protection

- Discovery endpoints accept destinations in `ready` or `limited` state.
- A destination still importing returns HTTP 409 with a structured `destination_preparing` code and the active import ID.
- A failed destination returns a structured error and retry eligibility.
- The client must never navigate from activation directly into an empty recommendation surface.

### P0 — mobile and web client

#### P0.9 Search interaction

- Debounce remote search by at least 350 ms.
- Do not query remotely for fewer than 3 normalized characters.
- Cancel obsolete requests as the query changes.
- Display city, region where available, and country for disambiguation.
- Visually distinguish `Available now` from `Prepare this city`.
- Require one explicit tap before activation.

#### P0.10 Preparation experience

- Route to a preparation screen or bottom sheet after HTTP 202.
- Poll using server guidance and stop when the app backgrounds.
- Resume status checks when foregrounded or reopened.
- Allow the traveller to leave; persist destination and import IDs locally.
- On `ready`, select the destination and continue onboarding.
- On `limited`, explain that fewer recommendations are currently available and allow continuation.
- On `failed`, show retry only when `retryable=true`; otherwise offer search.
- Meet screen-reader, focus-order, contrast, and reduced-motion expectations.

### P0 — grounding and AI

#### P0.11 AI boundary

AI can:

- interpret a natural-language city query when deterministic search cannot;
- summarise a traveller’s stated intent;
- rank grounded experiences against traveller and journey context;
- explain why a grounded result fits;
- suggest a refined query.

AI cannot:

- establish that a venue exists;
- create coordinates, addresses, ratings, opening hours, prices, or availability;
- mark a destination ready;
- promote `needs_content` records to published without a sourced description;
- hide or rewrite attribution.

All assistant answers continue through `GroundingGuard`.

### P0 — operations, abuse prevention, and cost

#### P0.12 Controls

- Rate-limit search per IP/actor and activation more strictly per actor and device.
- Apply a daily global activation budget and a provider-specific circuit breaker.
- Reject obvious automation patterns and repeated activation of terminally unsupported candidates.
- Cache geocoder results by normalized query and locale.
- Cache provider absence separately from transient failure.
- Record estimated billed calls without logging provider secrets.
- Permit an emergency feature flag that disables new activation while preserving ready destinations.

#### P0.13 Operations endpoints

Add admin-only views or endpoints for:

- imports filtered by status, provider, age, and destination;
- import stage, attempt count, counters, and sanitized error code;
- retry, cancel-before-start, and mark-unavailable actions;
- destinations stuck in a non-terminal state;
- provider usage and readiness conversion metrics.

Existing admin token ability rules apply.

## 10. Data model

### `destinations` additions

| Column | Type | Purpose |
|---|---|---|
| `coverage_status` | string, indexed | `discovered`, `queued`, `importing`, `ready`, `limited`, `failed` |
| `discovery_source` | nullable string | `seed`, `nominatim`, `admin`, or future source |
| `discovery_external_id` | nullable string | Provider identity used for deduplication |
| `region` | nullable string | Disambiguation and display |
| `activated_at` | nullable timestamp | First explicit activation |
| `ready_at` | nullable timestamp | First readiness threshold success |
| `last_imported_at` | nullable timestamp | Freshness and refresh scheduling |

Indexes:

- unique when available: `(discovery_source, discovery_external_id)`;
- indexed: `(country_code, normalized_name)` or the project’s existing equivalent;
- indexed: `coverage_status`.

Existing seeded destinations migrate to `ready`. Newly geocoded destinations begin as `discovered` and move to `queued` in the activation transaction.

### `destination_imports`

| Column | Type | Purpose |
|---|---|---|
| `id` | UUID | Public import identifier |
| `destination_id` | UUID FK | Destination being prepared |
| `requested_by_type/id` | nullable actor reference | Abuse control and private progress access |
| `status` | indexed string | `queued`, `running`, `succeeded`, `partial`, `failed`, `cancelled` |
| `stage` | string | Current user-visible stage |
| `provider_key` | string | Provider selected for this run |
| `attempt` | small integer | Retry count |
| `records_seen` | integer | Operational progress counter |
| `places_created/matched/needs_review/failed` | integers | Ingestion result counters |
| `experiences_published/pending_content` | integers | Readiness counters |
| `error_code` | nullable string | Sanitized stable code |
| `error_context` | nullable encrypted/private JSON | Operations-only diagnostics |
| `started_at/finished_at` | nullable timestamps | Duration and stuck-job detection |
| timestamps | timestamps | Audit trail |

Enforce at most one active import per destination using a database constraint where supported and an atomic application lock in all environments.

### Candidate tokens

Candidate tokens are signed payloads, not persisted rows in P0. Payload fields:

- version;
- provider and external identity;
- name, region, country, country code;
- latitude and longitude;
- provider place type;
- issued-at and expiry.

If token volume, revocation, or provider terms later require storage, a `destination_candidates` table is a P1 option.

## 11. Service design

New domain components should follow the modular-monolith convention:

```text
Domains/Destinations/
  Actions/ActivateDestination.php
  Actions/ResolveDestinationCandidate.php
  Jobs/ImportDestination.php
  Models/DestinationImport.php
  Services/DestinationCoverageStateMachine.php
  Services/DestinationReadinessEvaluator.php
  ValueObjects/DestinationCandidate.php
```

Responsibilities:

- `ResolveDestinationCandidate`: verifies token, normalises identity, and produces a value object.
- `ActivateDestination`: transactionally upserts destination/import and dispatches after commit.
- `ImportDestination`: orchestrates existing ingestion services and records durable progress.
- `DestinationCoverageStateMachine`: owns legal transitions.
- `DestinationReadinessEvaluator`: performs one configurable quality decision.

Controllers validate transport input and delegate. They must not contain provider orchestration or readiness logic.

## 12. Concurrency and idempotency

Activation algorithm:

1. Verify candidate token.
2. Derive stable identity from provider ID; fall back to normalized city, country, and coordinate tolerance.
3. Begin database transaction.
4. Lock matching destination row or acquire an identity-level atomic lock.
5. Upsert destination without overwriting richer editorial fields.
6. Return immediately if coverage is `ready` or `limited`.
7. Reuse any queued/running import.
8. Create one import, transition destination to `queued`, and dispatch after commit.
9. Commit and return 202.

The job obtains a destination lock before moving to `importing`. If it cannot acquire the lock, it exits successfully because another worker owns the work. Provider mappings and existing resolver rules remain the canonical place-level idempotency mechanism.

## 13. Provider policy

### Nominatim

- Used for destination search and identity only.
- Requests are cached, rate-limited, and made with the configured identifying headers.
- Autocomplete must respect the provider’s usage policy; production volume may require a commercial geocoder or self-hosted service.

### Google Places

- Preferred place source when configured because ratings and review volume improve ranking confidence.
- Called only after explicit activation or scheduled refresh.
- Per-activation result limit is configurable.
- Field masks must request only data Interlude uses.
- Usage counters and estimated spend are recorded.

### OpenStreetMap/Overpass

- Keyless fallback, not an unlimited service.
- Existing rate limits, query batching, attribution, and server-remark handling remain mandatory.
- High production volume should use managed or self-hosted infrastructure.

### Wikimedia

- Description and image enrichment remains source-attributed.
- Images without sufficient licence metadata are discarded.
- A missing article is cacheable; a transient failure is not cached as absence.

## 14. Failure model

| Failure | API/client behavior | Retry policy |
|---|---|---|
| Geocoder unavailable | Show cached/catalogue results; explain remote search is temporarily unavailable | Client may retry with backoff |
| Invalid candidate token | Ask user to search again | No automatic retry |
| Provider rate limit | Keep import queued/running with delayed retry | Exponential backoff, bounded attempts |
| Provider authentication/config error | Mark failed with operator-visible code | No automatic retry until configuration changes |
| No places found | Try configured fallback if allowed; otherwise unavailable | One bounded fallback |
| Some records fail | Continue and evaluate successful records | Per-record failure logged |
| Enrichment unavailable | Preserve `needs_content`; evaluate any sourced successes | Retry enrichment separately |
| Worker crash | Durable job resumes; stale-running monitor releases state safely | Queue retry |
| Readiness threshold missed | `limited` if usable content exists, otherwise `failed` | Manual or scheduled retry |
| Client closes | Work continues server-side | Resume by stored import ID |

## 15. Analytics and observability

### Product events

- `destination_search_submitted`
- `destination_candidate_viewed`
- `destination_activation_requested`
- `destination_import_completed`
- `destination_import_failed`
- `destination_selected_after_import`
- `destination_activation_abandoned`

Do not include raw precise user coordinates in analytics. Query text should be normalized or classified according to the existing privacy policy.

### Operational metrics

- search latency and geocoder cache-hit rate;
- activations per actor/day and globally/day;
- import queue wait and execution duration by stage;
- provider calls, rate limits, failures, and estimated spend;
- created, matched, ambiguous, failed, and published records per destination;
- readiness conversion rate;
- destinations stuck in queued/importing beyond threshold;
- duplicate destination and merge-candidate rate.

### Logging

Every import log entry includes `import_id`, `destination_id`, `provider_key`, and stage. Never log API keys, signed candidate tokens, complete provider bodies, or unnecessary traveller data.

## 16. Security and privacy

- Activation uses existing guest or authenticated actor identity.
- Candidate payloads are signed and expire quickly.
- Coordinates are destination-centre coordinates, not traveller location history.
- Exact traveller coordinates remain optional and follow current deletion/export rules.
- Public status responses expose sanitized messages only.
- Admin operations require the existing `admin` Sanctum ability.
- Rate limits operate at actor, IP, and global levels.
- Queue payloads contain destination identifiers, not secrets.
- Provider keys remain server-side and never enter Expo bundles.

## 17. API compatibility

P0 should preserve current consumers while creating a clean migration path:

- Keep the existing `data` array for stored destinations during one compatibility window.
- Keep `elsewhere` temporarily, but include `candidate_token`, `kind`, and `coverage_status` so updated clients can activate it.
- Add a new normalized `results` array if changing `data` would break released clients.
- Instrument legacy-field usage before removal.
- Document a removal version; do not silently change the response shape used by an installed mobile build.

Recommended transition response:

```json
{
  "data": [],
  "elsewhere": ["legacy-compatible candidate objects"],
  "results": ["new discriminated catalogue and candidate objects"],
  "meta": {
    "query": "Reykjavik",
    "remote_search_used": true
  }
}
```

## 18. Testing strategy

All provider tests use fakes or recorded fixtures; CI never depends on live public infrastructure.

### Feature tests

- Stored destination search does not call the geocoder.
- Unstored city search returns an activatable signed candidate.
- Non-travellable geocoder results are excluded.
- Invalid and expired tokens are rejected.
- Activation creates one destination and one import.
- Concurrent/repeated activation reuses the same destination/import.
- Already-ready activation returns HTTP 200 without dispatching work.
- Import status hides internal diagnostics from travellers.
- Discovery refuses importing destinations with a structured response.
- Limited and ready destinations can enter discovery.
- Admin retry requires admin ability.

### Integration/service tests

- Import calls the configured provider and existing ingestion action.
- Partial record failure does not abort other records.
- Provider retry/backoff behavior is bounded.
- Readiness evaluator returns ready, limited, and failed for fixed datasets.
- Editorial content is not overwritten.
- Provider mapping and rerun remain idempotent.
- Stage transitions reject illegal movement.
- Job lock prevents simultaneous imports.

### Mobile tests

- Search renders ready and discoverable results distinctly.
- Selecting a candidate calls activation once.
- Preparation UI resumes after remount/reload.
- Polling stops in background and respects server interval.
- Ready routes to destination selection.
- Limited presents an honest notice.
- Failed presents retry only when allowed.
- Screen-reader labels announce city, country, and state.

### Contract tests

- Search, activation, and status payloads match documented schemas.
- Old mobile clients continue receiving compatible `data`/`elsewhere` fields during migration.

## 19. Rollout plan

### Phase 0 — instrumentation and schema

- Add coverage/import schema and state machine.
- Backfill seeded destinations to `ready`.
- Add metrics and admin inspection without exposing activation.
- Confirm queue worker and scheduler reliability in production.

### Phase 1 — controlled activation

- Enable candidate tokens and activation behind a server feature flag.
- Allow internal/admin accounts and a small city allowlist.
- Use Google Places with strict daily budget and result limits.
- Measure readiness, duration, duplicate rate, and cost.

### Phase 2 — traveller beta

- Enable for a percentage of travellers.
- Ship Expo preparation experience and resume behavior.
- Preserve seeded cities as instant-ready warm coverage.
- Alert on error, spend, and stuck-import thresholds.

### Phase 3 — general availability

- Remove allowlist while retaining abuse controls and global kill switch.
- Add scheduled refresh based on freshness, not on every selection.
- Review whether public Nominatim/Overpass capacity is appropriate for observed volume.

### Phase 4 — optimisation

- Predictively warm high-demand destinations from aggregate searches.
- Separate enrichment retries from canonical place ingestion.
- Add operator curation and quality scoring where readiness conversion is poor.

## 20. Deployment and infrastructure requirements

- A production queue backend and continuously running worker are P0. A request-process-only deployment cannot provide durable activation.
- A scheduler must detect stale imports and dispatch eligible refresh/recovery work.
- Queue configuration must survive shared-host process restarts; if the current host cannot run a reliable worker, move import execution to a worker-capable environment before GA.
- `deploy.sh` must run migrations before enabling the feature flag.
- Health checks should expose queue/provider capability without exposing secrets.
- Deployment must not run `experience:sync-places all`; ongoing global sync is replaced by explicit activation and scheduled refresh.

## 21. Acceptance criteria

1. **Given** Reykjavík is absent from the catalogue, **when** a traveller searches for it, **then** the API returns a signed, selectable destination candidate with city and country.
2. **Given** a valid candidate, **when** the traveller activates it, **then** the API returns 202 with one destination ID and one import ID without waiting for providers.
3. **Given** two travellers activate the same city concurrently, **when** both requests finish, **then** only one destination and one active import exist.
4. **Given** an import is running, **when** its status is requested, **then** the response contains a truthful stage and sanitized counters without internal secrets.
5. **Given** the provider returns grounded places with sufficient descriptions, **when** readiness is evaluated, **then** the destination becomes ready and can be used by discovery.
6. **Given** only two grounded experiences are publishable, **when** readiness is evaluated, **then** the destination becomes limited and the client displays that limitation.
7. **Given** no grounded experience is publishable, **when** retries are exhausted, **then** the destination becomes failed and never appears as ready.
8. **Given** an imported place lacks a sourced description, **when** drafts are created, **then** it remains `needs_content` and is excluded from traveller discovery.
9. **Given** the same import runs twice, **when** both complete, **then** provider mappings, places, and experiences are not duplicated and editorial content is unchanged.
10. **Given** the geocoder is unavailable, **when** search runs, **then** existing catalogue results still return normally.
11. **Given** an installed older client, **when** it calls destination search during the compatibility window, **then** its expected `data` and `elsewhere` fields remain valid.
12. **Given** AI participates in recommendation, **when** it produces an answer, **then** every factual venue claim is grounded in tool/provider output and processed by `GroundingGuard`.
13. **Given** the client is closed during import, **when** it is reopened, **then** preparation resumes from the server-side import state.
14. **Given** activation exceeds actor or global limits, **when** another request arrives, **then** it receives 429 with retry guidance and no job is created.

## 22. Definition of done

- Database migrations, models, state machine, activation action, queue job, readiness evaluator, endpoints, and admin inspection are implemented.
- Expo search and preparation flows support all coverage states and accessibility requirements.
- Existing seeded destinations remain functional and instant-ready.
- Focused API, queue, provider, contract, and mobile tests pass.
- Full API test suite and mobile typecheck pass.
- Production queue worker, scheduler, rate limits, provider keys, feature flag, and alerts are configured.
- Privacy policy and provider attribution requirements are reviewed.
- Rollback disables new activations without removing already-ready destinations.
- Operational runbook covers stuck jobs, provider outage, budget exhaustion, and retry.

## 23. Open questions

### Blocking

1. **Infrastructure:** Can the current production host run a persistent Laravel queue worker reliably, or must ingestion move to a worker-capable service?
2. **Product/finance:** What Google Places daily spend ceiling and per-activation result limit are acceptable for beta and GA?
3. **Provider/legal:** Is public Nominatim acceptable at projected search volume, or is a managed geocoder required before beta?
4. **Product:** Should `limited` destinations be selectable with 1–4 experiences, or should the initial release require the full ready threshold?

### Non-blocking

5. **Design:** Should preparation use a full screen, bottom sheet, or background notification after the traveller leaves?
6. **Data:** Which destination attributes should receive a sourced city-level enrichment in P1?
7. **Operations:** How long should completed import records and detailed diagnostics be retained?
8. **Growth:** Should aggregate failed searches be used to pre-warm high-demand destinations, subject to privacy review?

## 24. Recommended implementation sequence

1. Add schema, models, enums/value objects, and coverage transition tests.
2. Backfill current destinations to ready and protect existing discovery behavior.
3. Add signed candidates while retaining legacy search fields.
4. Implement activation transaction, uniqueness, locks, and endpoint tests.
5. Extract the existing command orchestration into a reusable import service; keep the Artisan command as a thin caller.
6. Add queued import, durable stage reporting, retries, and readiness evaluation.
7. Add status and admin endpoints.
8. Implement Expo search result states, activation, progress, resume, and failure UI.
9. Add analytics, budgets, feature flags, alerts, and operational runbook.
10. Deploy dark, validate with controlled cities, then progressively enable.
