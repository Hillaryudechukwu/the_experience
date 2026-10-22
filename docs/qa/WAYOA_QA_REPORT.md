# WAYOA QA REPORT

## Executive Summary

WAYOA's selected-city weather fix, destination transition, subscription access
semantics, subscription screen, branding/deep-link configuration and surrounding
regressions were reviewed at `main` commit `2328b07`. The inspected change range
is `6eb88bc..2328b07`, covering merged PRs #44, #46 and #47, plus the QA changes
listed below.

The city selected by the traveller now controls weather and discovery context,
and changing city clears only the device's active journey/trip selection. The
server and client now agree that `BILLING_ENFORCED=false` leaves the product
open, while an inactive subscription still renders as inactive. Mutation runs
proved all three regression guards fail against the old behaviour.

No open P0 or P1 defect was found. Release remains conditional because the npm
production graph reports 27 advisories (1 critical, 20 high, 6 moderate), the
browser suite is still absent from CI and native/store behaviour was not
exercised.

## Release Verdict

**CONDITIONAL GO**

Do not cut a native store build until the critical npm advisory is removed or
formally accepted with a documented reachability assessment. The API/web
candidate may proceed after the QA changes are committed and CI is green.

## Repository / Environment Tested

- Repository: `/Users/hillaryudechukwu/projects/Experince`
- Branch: `main`, tracking `origin/main`
- HEAD: `2328b07` (`Merge pull request #47`)
- Review baseline: `6eb88bc`
- Local OS: macOS 15.7.7, Europe/London
- PHP: 8.4.15
- Node: 24.19.0
- Local database: PostgreSQL test database
- CI databases: PostgreSQL 16 and MariaDB 11.4.13
- Browser: Playwright Chromium at iPhone 13 and iPhone SE viewports
- Billing mode exercised end to end: `BILLING_ENFORCED=false`
- Resolved Expo slug: `wayoa`.

The workspace also contained pre-existing Laravel Boost/tooling files and a
duplicate untracked `api/mobile/` directory. They were preserved and were not
treated as QA-authored product changes.

## Test Matrix

| Layer | Scope | Result |
|---|---|---|
| API focused | Context/weather, routing, subscription entitlement | Pass |
| API full, local | 383 tests, PostgreSQL | Pass |
| API full, CI | 383 tests / 1,287 assertions, PostgreSQL 16 | Pass |
| API full, CI | 383 tests / 1,287 assertions, MariaDB 11.4.13 | Pass |
| Mobile static | TypeScript `tsc --noEmit` | Pass |
| Mobile web export | Production API/privacy URLs | Pass |
| Browser full | 130 tests, two phone viewports | Pass |
| Browser focused | 2 subscription tests, two phone viewports | Pass |
| PHP dependency audit | Composer locked graph | Pass, 0 advisories |
| npm production audit | `npm audit --omit=dev` | Fail, 27 advisories |
| Native iOS/Android | Device/store build | Not run |

## Commands Executed

Representative final commands:

```text
php artisan test --compact
php artisan test --compact tests/Feature/ExternalSources/RoutingAndCurrencyTest.php tests/Feature/ExternalSources/RoutingDegradationTest.php tests/Feature/ContextualGuidanceTest.php
vendor/bin/pint --test --format agent <changed PHP files>
php -l <changed PHP files>
composer validate --no-check-publish
composer audit --locked
tsc --noEmit
expo config --type public
EXPO_PUBLIC_API_URL=https://api.experience.synteric.co.uk/api EXPO_PUBLIC_PRIVACY_URL=https://experience.synteric.co.uk/privacy expo export --platform web --output-dir dist --clear
playwright test
npm audit --omit=dev --audit-level=moderate
git diff --check
gh pr checks 46
gh pr checks 47
```

The first full browser attempt was invalidated because the exported bundle and
fixture used different API ports. It was rerun with both set to port 8100. A
later API run was stopped after live OSRM traffic was observed; `phpunit.xml`
was made hermetic and the complete suite was rerun.

## Existing Tests

- 71 PHPUnit test files cover domain, API, security, billing, booking,
  providers, recommendations, itineraries, jobs and data portability.
- Four pre-existing Playwright spec files cover all declared routes, layout,
  interactive nesting, direct-entry navigation, search, destination import,
  location/weather context and destination transitions.
- CI runs the complete API suite on PostgreSQL and MariaDB and runs the mobile
  TypeScript checker.

## Tests Added

- `ContextualGuidanceTest`: an explicit Tokyo destination overrides a stale
  London journey for weather and clears conflicting journey context.
- `SubscriptionEntitlementTest`: disabled billing enforcement reports access
  open and permits discovery without a subscription.
- `location-weather.spec.ts`: changing city clears stale journey/trip IDs and
  sends no stale coordinates to any discovery surface.
- `subscription.spec.ts`: open global access does not make an inactive
  subscription appear paid or manageable.

The new subscription browser test was observed failing against the old
`can_use` condition, then passing after restoration. The weather and session
tests were likewise mutation-tested against their prior implementations.

## Tests Passed

- Final local PostgreSQL suite: 383 tests, 1,287 assertions.
- Exact-HEAD PR #47 CI: 383 tests / 1,287 assertions on PostgreSQL and the same
  on MariaDB 11.4.13; mobile typecheck passed.
- Full Playwright regression: 130 tests across both phone sizes.
- Added subscription browser regression: 2 tests across both phone sizes.
- Focused routing/context regression after the hermetic-test fix: 17 tests,
  62 assertions.
- Focused changed-code suites and all syntax/format/type checks passed.

## Tests Failed

No final product regression failed. Expected mutation failures were:

- Old destination precedence returned London temperature for Tokyo.
- Old client transition retained London journey/trip IDs on both viewports.
- Old subscription condition rendered price/management controls for an
  inactive traveller while enforcement was disabled.

The npm production audit remains non-zero and is tracked as an open P2 release
risk.

## Static Analysis

- PHP syntax: pass for all changed PHP source/tests.
- Pint: pass for all changed PHP source/tests.
- TypeScript: pass.
- Composer validation: pass.
- `git diff --check`: pass.
- Expo public config resolved successfully.
- Production web export completed with a cleared Metro cache and compiled the
  requested HTTPS API URL as the active endpoint. `deploy.sh` already uses
  `--clear` and verifies that URL before upload.

## Backend/API

The API accepts explicit destination context as authoritative, removes a
conflicting journey from that request, and sources weather from the selected
destination. Entitlement presentation now reports the same effective access
decision enforced by middleware. Full API regressions pass.

## Frontend

The web export builds successfully. Search, city selection, real API discovery,
failure/retry states, route rendering and account deletion passed browser
coverage. The bundle was built with the production API and privacy URLs.

## Mobile

Session state clears stale destination-bound IDs when the traveller changes
city. Both `wayoa` and legacy `interlude` schemes appear in resolved Expo
configuration. Native Yoga layout, deep-link dispatch, push notifications,
permissions and store-installed behaviour were not tested on devices.

## E2E

The full browser suite passed 130 tests on iPhone 13 and iPhone SE viewports.
It covers declared screens, real local API search, city changes and weather
context. The added subscription case passed twice. This is React Native Web
coverage, not a substitute for native device E2E.

## Security

Composer is clean after upgrading `league/commonmark` from 2.10.1 to 2.10.4.
The npm production graph is not clean: 27 transitive advisories remain. Forced
automatic remediation proposes breaking framework changes and was not applied.
No secrets were printed or added.

## Authentication

Existing full-suite coverage for guest identity, registration/login, Google ID
token verification, guest adoption and account deletion passed. Live Google
sign-in was not exercised.

## Authorisation

Existing ownership and cross-user boundary tests passed in the complete API
suite. No cross-user data access was observed.

## Subscription and Entitlement

The server gate and client entitlement now agree when enforcement is disabled.
An inactive subscription remains visibly inactive even though the server allows
access. Regression tests cover both API and rendered UI. Enforcement-on receipt
flows remain covered by existing API tests only.

## Payments

Store purchase is intentionally incomplete: `expo-iap`, receipt refresh
endpoints and the Google Play API read are not implemented. Existing billing
notification validation tests passed. No Apple/Google sandbox purchase ran.

## Booking

Existing price integrity, availability, provider confirmation, cancellation
and booking ownership tests passed. No live provider purchase was attempted.

## Provider Integrations

Mocked/contract provider suites passed. The public OSRM router was removed from
the default PHPUnit path by selecting the estimator in `phpunit.xml`; concrete
OSRM contract tests still opt into the provider with faked HTTP.

## Recommendation Engine

Discovery, scoring, availability, weather suitability, location precision,
fallback and search suites passed. Explicit destination context is now
protected by a dedicated regression.

## Experience Score

Existing scorer/component regressions passed. No production catalogue-quality
or statistical ranking evaluation was performed.

## Journey Anchors

Existing anchor ownership, locking, feasibility and replan protections passed.
Changing destination no longer submits a stale journey ID from the client.

## Itinerary Engine

Existing planning, timing, conflict and replan tests passed. Known defects F1,
F2 and F3 remain in `docs/BUG_LOG.md`.

## AI Grounding

Rules and mocked model-driven assistant tests passed, including tool use and
provider-grounded behaviour. No live model request or adversarial production
prompt run was performed.

## Accessibility

Both phone viewports passed checks for crushed text and nested interactive
controls across all declared routes. This is targeted regression coverage, not
a complete WCAG audit or native screen-reader session.

## Performance

No load, soak, cold-start or mobile performance benchmark was run. The local
test suite's accidental live OSRM dependency was identified and removed; its
runtime is now deterministic with respect to routing availability.

## Offline / Reliability

Failure/retry UI, provider degradation and queue idempotency tests passed.
Offline ticket/data mode is not implemented and was not tested.

## Database (both engines)

PR #47 CI passed 383 tests and 1,287 assertions on PostgreSQL 16 and MariaDB
11.4.13. The local final run used PostgreSQL. MariaDB was not available locally,
so the CI result is the authoritative second-engine evidence.

## Queues

Queue/job and idempotency tests passed with the synchronous test driver. A live
long-running production worker and worker heartbeat were not exercised.

## Observability

Existing health, logging and operational-status tests passed. No production
alert delivery, log aggregation or live dashboard was tested.

## P0 Findings

None open or fixed during this run.

## P1 Findings

- **WAYOA-QA-001 — fixed:** with billing enforcement disabled, the API was
  open but the client was told `can_use=false`, locking every onboarded
  traveller behind a non-purchasable paywall. Server presentation, client
  rendering and regression tests now agree.

## P2 Findings

- **WAYOA-QA-002 — fixed:** stale journey and coordinate state could make a
  newly selected city display the previous city's weather/context.
- **WAYOA-QA-003 — fixed:** `league/commonmark` 2.10.1 had two published
  advisories; the lockfile now resolves 2.10.4 and Composer audit is clean.
- **WAYOA-QA-004 — open:** 27 production npm advisories remain, including one
  critical `shell-quote` advisory. Reachability in shipped runtime code was not
  established; remediation currently crosses Expo/React Native compatibility
  boundaries. Logged as S1.
- **WAYOA-QA-005 — open, known V1:** Playwright is not run in CI.
- **WAYOA-QA-006 — fixed:** the working tree temporarily changed the Expo slug
  back to `interlude`. It now matches committed HEAD and resolves as `wayoa`.
- **WAYOA-QA-007 — fixed:** the default API test configuration called the live
  public OSRM router. Tests now default to the deterministic estimator; OSRM
  contract tests remain mocked.

## P3 Findings

- The live bug log still listed `/understanding` as untested and recently
  changed screens as never rendered. Both entries were removed after route
  inclusion and the successful full browser run were verified.

## Coverage Gaps

- Browser tests are not part of CI.
- Mobile has no unit-test runner; hook/state edge cases rely on browser/API
  coverage.
- Native iOS/Android UI, deep links and permissions were not exercised.
- In-app purchase and receipt-refresh flows do not exist yet.
- Full WCAG, localisation, performance, soak and offline testing were not run.
- Known F1-F5 limitations remain documented in `docs/BUG_LOG.md`.

## Risks Not Testable Locally

- expo.dev project rename state and remote build-number continuity.
- Apple/Google store credentials, purchase, renewal, pause and cancellation.
- Production provider quotas, live inventory, rate limits and confirmations.
- Production queue worker lifetime, cron/scheduler and external monitoring.
- Real-device layout, native linking and push-delivery behaviour.

## Recommended Follow-Up

1. Resolve S1: upgrade compatible Expo/React Native transitive dependencies or
   document a time-bounded security acceptance for each still-reachable item.
2. Confirm expo.dev resolves the existing project as `wayoa`, then run
   `eas project:info` and a signed internal Android build.
3. Add the Playwright suite to CI with the same deterministic API fixture.
4. Run native smoke tests on one Android and one iOS device, including city
   change, temperature, search, deep links and entitlement states.
5. Implement and sandbox-test the remaining store purchase/receipt flow before
   enabling billing enforcement.

## Release Checklist

- [x] No unresolved P0/P1 defect
- [x] Changed code reviewed and mutation-tested
- [x] Full API suite passed on PostgreSQL and MariaDB
- [x] TypeScript, PHP syntax, Pint and Composer validation passed
- [x] Production web export passed with real HTTPS API/privacy URLs
- [x] Browser regression passed on two phone viewports
- [x] Composer advisory count is zero
- [ ] Critical npm advisory removed or formally accepted
- [x] Expo project slug resolves as `wayoa`
- [ ] QA-authored changes committed and CI rerun
- [ ] Native/store smoke test completed
- [ ] Playwright added to CI

## Final Verdict

**CONDITIONAL GO**

There is no unresolved release-blocking functional defect in the tested API/web
candidate. Native release remains conditional on npm security disposition, a
clean commit with green CI, and a signed-device smoke test.
