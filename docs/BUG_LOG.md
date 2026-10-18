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
| [V1](#v1) | The browser suite is not in CI | high | open |
| [V2](#v2) | `/understanding` is missing from the route list | high | open |
| [V3](#v3) | No unit runner for mobile; `usePlanForDates` untested | medium | open |
| [U1](#u1) | 19 mobile surfaces changed, none rendered | high | open |
| [F1](#f1) | Itineraries cap at 14 days | medium | mitigated |
| [F2](#f2) | `build()` posts to `/trips/null/generate-itinerary` | medium | open |
| [F3](#f3) | Onboarding creates a trip and never builds a plan | medium | open |
| [F4](#f4) | Four journey reasons exist on the API, unreachable in the app | low | open |
| [F5](#f5) | `dayLabel` computes tomorrow as +24h | low | open |
| [F6](#f6) | Android image headers unconfirmed on a device | high | unverified |
| [F7](#f7) | A failed image stays failed for that URL | low | by design |
| [F8](#f8) | Discovery cannot answer a date range | — | by design |

---

## Verification

### V1
**The browser suite is not in CI.** `mobile/playwright.config.ts` and
`mobile/tests/browser/` exist and `npm run test:browser` runs them, but
`.github/workflows/ci.yml` has only `api`, `api-mariadb` and `mobile` — and
`mobile` is `tsc --noEmit`. Nothing looks at a rendered screen on any push.

This matters more than it sounds. `layout.spec.ts` detects text crushed below a
legible height, and its own comment records why it exists: *"the five tab bar
labels at 0px, and a Creative Commons photo credit at 2px"*. That credit was in
`DestinationHero` — the component whose height model changed in #27, which had
to be diagnosed by hand against Yoga because nothing was watching.

Not a one-liner: the job needs PHP, a database, migrations and an Expo web
export. The `api` job already does the first three.

*Known limit:* it catches crushed text, not every overflow. It would not have
caught the search pill sliding under the Quick Actions card.

### V2
**`/understanding` is missing from `mobile/tests/browser/routes.ts`.** The
file's own comment: *"A screen missing from this list is a screen nothing looks
at."* One line, and worth adding before V1 so the new screen is covered the
moment the suite runs.

### V3
**No unit runner for mobile.** `package.json` scripts are `typecheck` and
`test:browser`. `usePlanForDates` branches three ways — plan, no plan, and
*unknown* — where the unknown case exists specifically to avoid silently
destroying an itinerary while a query is in flight. Nothing tests any of it.

## Unrendered work

### U1
**Nineteen mobile surfaces changed in the 2026-10-16/18 run of work, and none
has been seen rendering.** Typecheck and reading only. Highest risk first:

- `DateRangeCalendar` — new, hand-written, seven columns at `14.28%` against
  36pt day circles. Inside Home's sheet the content padding is applied twice
  (the sheet's `ScrollView` already pads, and the sheet content pads again), so
  on a ~320pt screen the columns compute near 34pt. Unresolved without a screen.
- `DestinationHero` — fixed height became `minHeight`, the inner column's flex
  basis changed, the wordmark replaced a caption, and an `Artwork` fallback
  arrived. Four interacting changes to the first thing anyone sees.
- Home's dates chip and sheet — a chip that deliberately never looks selected,
  sitting in a row where every neighbour does.
- `understanding.tsx` — a whole screen, and see V2.

## Functional

### F1
**Itineraries cap at 14 days.** `ItineraryPlanner::dateRange` stops at
`count($dates) < 14`. The range picker enforces the same limit so a journey
never claims days the planner silently drops — a mitigation, not a fix. A
three-week trip cannot be planned. This is the only entry here a traveller
meets as the product refusing them.

### F2
**`build()` posts to `/trips/null/generate-itinerary`.**
`mobile/app/(tabs)/trip.tsx:85-89` creates a trip and then calls `generate`,
which `useGenerateItinerary(session.tripId)` bound at render — when there was no
trip, that id is null. Confirmed on `main`. `useBuildItinerary` (added in #32)
takes the id at call time and is the ready-made fix; this call site was left
alone rather than widening that PR.

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

### F6
**Android image headers are unconfirmed on a device.** Wikimedia answers 403 to
`okhttp/4.x` and 200 to an identifying agent — measured against the live host.
RN only forwards `source.headers` for an *array* source (`Image.android.js`),
and react-native-web cannot read an array at all, so `remoteImage()` returns an
array on Android only. Both halves are read from `node_modules`; neither has
been observed working in a build. The first attempt at this fix shipped inert.

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

## Suggested order

1. **V2** then **V1** — the cheapest line, then the job that makes it matter.
   This converts "nothing is watching" into something that watches every push,
   and retroactively covers U1.
2. **F1** — the only gap a traveller experiences as a refusal.
3. **F2** — small, and closes a confirmed null-id request.
