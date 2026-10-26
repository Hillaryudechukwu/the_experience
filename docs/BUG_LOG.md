# Bug log

Known defects, limits and unverified work. One row per thing, newest section
first. An entry leaves this file when it is fixed and the fix has been *seen*
working — not when the code is merged.

Opened 2026-10-18 from an audit of the WAYOA rename, the trip-dates work, the
Android imagery fix and the journey-ownership fix.

**Status key.** `open` — confirmed, unfixed. `mitigated` — worked around, cause
remains. `unverified` — believed fixed, never observed working. `by design` —
recorded so nobody rediscovers it as a bug.

| ID | What | Severity | Status |
|---|---|---|---|
| [S1](#s1) | Production dependency graph has accepted transitive npm advisories | high | pending merge |
| [V1](#v1) | The browser suite is not in CI | high | pending merge |
| [V3](#v3) | No unit runner for mobile; `usePlanForDates` untested | medium | open |
| [F1](#f1) | Itineraries capped at 14 days; three-week trips could not be planned | medium | pending merge |
| [F2](#f2) | `build()` posted to `/trips/null/generate-itinerary` | medium | pending merge |
| [F3](#f3) | Onboarding creates a trip and never builds a plan | medium | open |
| [F4](#f4) | Four journey reasons exist on the API, unreachable in the app | low | open |
| [F5](#f5) | `dayLabel` computes tomorrow as +24h | low | open |
| [F7](#f7) | A failed image stays failed for that URL | low | by design |
| [F8](#f8) | Discovery cannot answer a date range | — | by design |

---

## Verification

### S1
**Resolution pending merge:** the critical `shell-quote` advisory is removed,
and the compatible SDK 57 patch set also removes the `source-map-js` and
`uuid` roots. The remaining production report is 21 affected packages (18
high, 3 moderate) resolving to three upstream advisories. Those risks are
time-bounded and documented in `docs/SECURITY_ACCEPTANCES.md`.

`npm run audit:production` now fails CI on a critical or any advisory outside
that exact baseline, while `npx expo install --check` prevents the compatible
Expo packages drifting behind again. The gate also fails once the acceptance's
review date passes, so the baseline cannot outlive its review. Do not run `npm audit fix --force`: npm's
proposal crosses the supported Expo/React Native compatibility boundary.

### V1
**Resolution pending merge:** `.github/workflows/ci.yml` now has a blocking
`browser` job. It provisions PostgreSQL and PHP, migrates and seeds the API,
starts it with deterministic providers, exports the Expo web bundle, installs
Chromium and runs the complete Playwright suite in both phone viewports.

This matters more than it sounds. `layout.spec.ts` detects text crushed below a
legible height, and its own comment records why it exists: *"the five tab bar
labels at 0px, and a Creative Commons photo credit at 2px"*. That credit was in
`DestinationHero` — the component whose height model changed in #27, which had
to be diagnosed by hand against Yoga because nothing was watching.

*Known limit:* it catches crushed text, not every overflow. It would not have
caught the search pill sliding under the Quick Actions card.

### V3
**No unit runner for mobile.** `package.json` scripts are `typecheck` and
`test:browser`. `usePlanForDates` branches three ways — plan, no plan, and
*unknown* — where the unknown case exists specifically to avoid silently
destroying an itinerary while a query is in flight. Nothing tests any of it.

## Functional

### F1
**Resolution pending merge:** the limit is now 31 days
(`experience.itinerary.max_planned_days`), enforced in one place per layer:
journey and `generate-itinerary` validation reject longer ranges, the planner
clamps journeys stored before the limit existed, and the range picker stops at
the same 31 days. Planning still runs inside the request, which is why the span
stays bounded. Feature regressions cover a 21-day plan and the rejections.

### F2
**Resolution pending merge:** the trip screen now keeps the newly created trip
ID in the build handler and passes it to `useBuildItinerary` at call time. A
browser regression records both requests and rejects any `/trips/null/...`
request.

### F3
**Onboarding creates a trip and never builds a plan.**
`mobile/app/onboarding/style.tsx:89` calls `createTrip` with no generate, so
every new traveller lands on an empty Trip tab. #32 softens the symptom by
building when dates are set; the asymmetry at the source remains.

### F4
**Four journey reasons are unreachable.** `education`, `medical`, `pilgrimage`
and `relocation` are valid `experience.playbooks` keys but absent from
`JOURNEY_REASONS` / `MORE_REASONS`. A journey created with one (via the API)
shows no selection in the edit grid.

### F5
**`dayLabel` computes tomorrow as `today.getTime() + 86_400_000`**
(`mobile/src/lib/format.ts`). That is not a calendar day across a DST boundary,
so "Tomorrow" is wrong twice a year. Pre-existing.

### F7
**A failed image stays failed for that URL.** `DestinationHero` records the URL
that failed, so a different city is tried afresh — but a transient failure on
the current one holds the fallback until the URL changes or the screen
remounts. Pull-to-refresh will not retry it. Fixing properly means a retry
policy.

### F8
**Discovery cannot answer a date range.** `window_minutes` is validated
`min:15, max:900` and scoring runs on present context — open now, travel time,
weather. The multi-day artefact is the itinerary. Recorded because the Home
dates chip sits in a row of filters and looks like it should filter.

---

## Pending verification

S1, V1, F1 and F2 can leave this log after this branch is merged and its CI run
passes. The local reproduction passed the production audit baseline, 384 API
tests (1,293 assertions), and 134 browser tests across both configured phone
viewports.
