# WAYOA — Paid Launch Gates JEM (Gates 1–5)

**Status:** Proposed implementation specification  
**Scope:** Engineering work that closes market-readiness Gates 1–5 for a UK paid store launch  
**Objective:** Take WAYOA from “API and web are live, billing stack inert” to a state where a sandbox purchase grants a real month, stores can accept the binary, legal pages remain honest, launch cities earn £1.99, and production stays observable under paid traffic.

**Out of scope for this JEM.** Console agreements, bank/tax forms, product creation in App Store Connect / Play Console, spend ceilings in vendor dashboards, backup restore drills, support staffing, and store submission. Those live in [`PAID_LAUNCH_OWNER_RUNBOOK.md`](PAID_LAUNCH_OWNER_RUNBOOK.md). Do not invent them in code.

**Related docs**

- [`qa/WAYOA_MARKET_READINESS_AGENT.md`](qa/WAYOA_MARKET_READINESS_AGENT.md) — the seven gates this programme implements for 1–5
- [`SUBSCRIPTION_JEM.md`](SUBSCRIPTION_JEM.md) — entitlement rule, product ids, review notes, prior billing phases
- [`RELEASE.md`](RELEASE.md) — privacy export, deep links, EAS production env
- [`PRODUCTION_READINESS_JEM.md`](PRODUCTION_READINESS_JEM.md) — broader Full V1+ programme (P6 store release aligns here)
- [`PROVIDER_SMOKE_RUNBOOK.md`](PROVIDER_SMOKE_RUNBOOK.md) — provider and queue health
- [`DESTINATION_ACTIVATION_GA_RUNBOOK.md`](DESTINATION_ACTIVATION_GA_RUNBOOK.md) — activation rollout ladder
- [`PAID_LAUNCH_OWNER_RUNBOOK.md`](PAID_LAUNCH_OWNER_RUNBOOK.md) — human-only checklist this JEM depends on

The customer-facing name is WAYOA. Store identifiers stay `uk.co.synteric.experience`. Launch market is **United Kingdom only**; price is **£1.99 / month GBP**.

---

## 1. Executive summary

Gates 1–5 answer five launch questions in order:

```text
1 Can anyone pay?
2 Will the stores accept it?
3 Is it legal to sell?
4 Is it worth £1.99?
5 Can we keep it running?
```

Most of the subscription **policy** is already on `main`: entitlement model, server gate (enforcement off by default in local/CI via `BILLING_ENFORCED=false`; production config defaults to enforced when unset), Apple/Google notification verification, paywall UI, pause/subscription screens, Sign in with Apple on iOS, delete-account from the paywall, live privacy and terms URLs.

What still blocks Gate 1 (and therefore paid GA) is the **money path**:

1. No `expo-iap` — Subscribe shows “Not available in this build”.
2. No `POST /billing/{apple,google}/refresh` — a device receipt never creates entitlement.
3. No Play Developer API subscription read — Google renewals cannot move `paid_until` (fail closed today).
4. Owner runbook items (products, merchant agreements, secrets, sandbox walks) are incomplete until a human finishes them.

This JEM sequences **engineering phases G0–G5** so each gate can exit with evidence. Soft-launch usefulness without charging remains possible with enforcement off; **commercial GA requires G1 exit + owner sandbox proof + `BILLING_ENFORCED=true` on production**.

```text
G0 Baseline freeze
  → G1 Money path (Gate 1 code)
  → G2 Store-facing completeness (Gate 2 code)
  → G3 Legal honesty checks (Gate 3 code)
  → G4 Launch-city product proof tooling (Gate 4 support)
  → G5 Operability hooks (Gate 5 code)
```

Owner runbook phases run in parallel where marked **Depends on owner**. A code phase is not “done” until its exit criteria pass; owner-blocked criteria stay `blocked`, not `pass`.

---

## 2. Problem statement

A traveller can onboard, discover, book against sandbox/live providers, register, and see a paywall that correctly describes £1.99 — then cannot buy. Until refresh and IAP exist, enabling `BILLING_ENFORCED` in production would lock everyone behind a door with no key.

Meanwhile every guest can still spend Google Places, Viator/Tiqets, and Anthropic money because enforcement is off on the live host until deliberately turned on. Gate 5 therefore includes cost controls as a launch risk, not a later ops chore.

If Gates 2–4 are treated as “listing copy” while Gate 1 is unfinished, engineering scatters into polish and the revenue loop stays broken.

---

## 3. Principles

1. **Server decides access.** The client starts purchase and renders the paywall; `can_use` comes only from Laravel.
2. **Pending is not paid.** Tabs open only after a verified charge produces `paid_until > now`.
3. **Fail closed on unread periods.** A Google notification without a Play API expiry must not invent a month.
4. **Wire before invent.** Extend `App\Domains\Billing` and the existing paywall; do not add a second billing vendor as authority.
5. **Owner work is a dependency, not a stub.** Phases that need console secrets cite the owner runbook by section id.
6. **UK GBP only for this pass.** Do not price other storefronts in code or copy.
7. **Same binary as the gate.** Do not flip production enforcement until the paywall+IAP build is what travellers install (see `SUBSCRIPTION_JEM` §15).
8. **Evidence over assertion.** Exit criteria name the test, curl, or device walk that proves the gate.

---

## 4. Current baseline (evidence, 1 November 2026)

| Area | State |
|---|---|
| Public API / web | Live; `/api/health` reports durable `queue_driver: database` |
| Providers | Google Places, Viator, Tiqets, Anthropic configured; deeplink/GYG not |
| Activation | Enabled, rollout **5%**, prewarm off |
| Legal pages | `https://experience.synteric.co.uk/legal/terms` and `/legal/privacy` return 200 |
| Deep links | `assetlinks.json` and AASA return 200 with fingerprint / team id |
| Billing routes | `POST billing/apple/notifications`, `POST billing/google/notifications`, `GET billing/entitlement` |
| Missing routes | `POST billing/apple/refresh`, `POST billing/google/refresh` |
| Mobile IAP | `expo-iap` **not** in `mobile/package.json`; Subscribe alerts stub |
| Gate middleware | `RequireSubscription` → HTTP 402 `subscription_required` |
| Auth | Email + Google + Apple (iOS) screens; delete from paywall and You |
| Notifications (product) | Journey-aware push recently shipped; not a Gate 1–5 blocker |

---

## 5. Phase map

| Phase | Gate | Name | Primary owner |
|---|---|---|---|
| G0 | — | Baseline freeze and kill-switch inventory | Engineering |
| G1 | 1 | Money path: Play read, refresh, expo-iap, restore | Engineering + owner secrets |
| G2 | 2 | Store-facing completeness and review walk harness | Engineering + owner listings |
| G3 | 3 | Legal honesty and erasure/export regression | Engineering + owner listing URLs |
| G4 | 4 | Launch-city catalogue readiness support | Engineering + owner traveller walk |
| G5 | 5 | Operability: queue, spend signals, rollback docs in-repo | Engineering + owner infra |

Gates 6–7 (support buyer, undo) remain owner/runbook-led; only flags and docs touch this JEM at G5.

---

## 6. Phased implementation

### G0 — Baseline freeze

**Objective.** Lock identifiers, env contract, and rollback switches before writing purchase code.

**Depends on owner.** None to start; owner confirms production `.env` inventory against the owner runbook §Secrets later.

#### Work

- Confirm product ids remain `wayoa.monthly` (Apple) and `wayoa_monthly` / base plan `monthly` (Google).
- Confirm EAS `production` / `preview` env already set:
  - `EXPO_PUBLIC_API_URL=https://api.experience.synteric.co.uk/api`
  - `EXPO_PUBLIC_PRIVACY_URL=https://experience.synteric.co.uk/privacy`
- Document kill switches in this repo (single table in exit evidence):

| Switch | Effect |
|---|---|
| `BILLING_ENFORCED=false` | Product APIs open without a paid month |
| `DESTINATION_ACTIVATION_ENABLED=false` | No new city imports |
| Blank provider keys | Capability degrades; discovery continues |
| `EXPERIENCE_ASSISTANT_DRIVER=rules` | Stop Anthropic spend |

- Do **not** enable production billing enforcement in this phase.

#### Tests / checks

- `php artisan test --compact` green on the release commit candidate.
- `cd mobile && npm run typecheck`.
- `curl -sS https://api.experience.synteric.co.uk/api/health` → `queue_worker_alive: true` (re-check; this has flapped).

#### Exit criteria

| Gate | Required |
|---|---|
| Identifiers and EAS URLs unchanged from `RELEASE.md` | GO |
| Kill-switch table committed in this JEM (above) | GO |
| Production enforcement left untouched by engineering | GO |

**Rollback.** N/A.

---

### G1 — Money path (Gate 1)

**Objective.** A signed-in traveller on a store build can buy or restore a month and receive `can_use: true` only after the server verifies the charge.

**Depends on owner.** [`PAID_LAUNCH_OWNER_RUNBOOK.md`](PAID_LAUNCH_OWNER_RUNBOOK.md) §§A–E (merchant, products, IAP key, Play service account, Pub/Sub, env vars, sandbox accounts). Engineering may implement against fakes first; **device sandbox proof is owner + engineering together**.

#### G1.a — Play Developer API subscription read

**Why.** `GoogleNotificationMapper` already accepts `subscriptionDetail` with `expiryTimeMillis`. Without a client that fetches it, renewals stay active without moving `paid_until` (fail closed).

##### Work

| Area | Detail |
|---|---|
| Service | New `GooglePlaySubscriptionReader` (or equivalent) under `api/app/Domains/Billing/Services/` |
| Auth | Use `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` + `GOOGLE_PLAY_PACKAGE_NAME` |
| Call | Play Developer API subscription purchase (v2 preferred if available in the locked Google client; otherwise document the v1 endpoint used) |
| Integration | `BillingNotificationController@google` fetches detail by `purchaseToken` before `GoogleNotificationMapper::map` |
| Failure | Missing credentials or API error → map without expiry (no invented `paid_until`); log sanitized error; do not 500 the notification endpoint if Google asks for 200 ack semantics — follow existing controller behaviour |

##### Tests

- Feature: fixture notification + mocked Play HTTP → `paid_until` set from `expiryTimeMillis`.
- Feature: mocked Play failure → state may update, `paid_until` unchanged when expiry absent.
- No live Play calls in CI.

##### Exit (G1.a)

| Gate | Required |
|---|---|
| Reader unit/feature covered with HTTP fake | GO |
| Controller wires reader when credentials present | GO |
| Absent credentials fail closed (no fake month) | GO |

#### G1.b — Refresh endpoints

**Why.** Purchase and restore need a synchronous path; notifications alone are too slow and too easy to miss at buy time.

##### Work

| Route | Behaviour |
|---|---|
| `POST /api/billing/apple/refresh` | Auth required. Body: Apple transaction id / JWS per chosen StoreKit 2 shape. Verify with existing Apple verifier patterns. Upsert subscription via `SubscriptionWriter`. Return entitlement presentation. |
| `POST /api/billing/google/refresh` | Auth required. Body: `purchase_token` (+ product id if required). Read via Play reader. Upsert. Return entitlement. |
| Binding | Original transaction already on another live user → **409** `subscription_in_use` |
| Environment | Sandbox receipts never grant when `APP_ENV=production` expects production environment on the row (`SUBSCRIPTION_JEM` §15) |

Register routes next to existing billing routes in `api/routes/api.php`. Keep `GET /billing/entitlement` ungated.

##### Tests

- Feature matrix: create/extend period; duplicate delivery idempotent; cross-user 409; unauthenticated 401.
- Grace-style expiry without successful charge does not extend `paid_until` (align with notification tests).

##### Exit (G1.b)

| Gate | Required |
|---|---|
| Both refresh routes exist and are tested | GO |
| Entitlement JSON shape matches paywall expectations | GO |
| 409 path covered | GO |

#### G1.c — Mobile purchase (`expo-iap`)

**Why.** Paywall Subscribe is a stub alert today.

##### Work

| Area | Detail |
|---|---|
| Dependency | Add `expo-iap` compatible with Expo SDK 57; confirm against current Expo docs before pinning |
| Config | Register the config plugin in `mobile/app.json`; product ids from env or constants matching §6 of `SUBSCRIPTION_JEM` |
| Flow | Signed-in only → request subscription → on success `POST` refresh → refetch entitlement → `router.replace('/(tabs)')` only if `can_use` |
| Pending | Keep existing pending UI when state is `pending` |
| Restore | Replace bare `refetch()` with store restore + refresh POST; handle 409 copy |
| Web | Unchanged: no purchase button; copy tells traveller to use iOS/Android |
| Builds | Purchase **will not** work in Expo Go; document EAS developmentClient / preview / production |

##### Tests

- Typecheck clean.
- Browser: paywall still has no dead purchase on web; terms/privacy/delete remain.
- Optional: hook-level unit test if a mobile unit runner exists by then; otherwise document device sandbox as the proof (owner runbook §F).

##### Exit (G1.c)

| Gate | Required |
|---|---|
| Stub alert removed on native Subscribe | GO |
| Restore calls refresh, not only entitlement GET | GO |
| Web behaviour unchanged | GO |

#### G1.d — Enforcement rollout (code readiness only)

##### Work

- Ensure production `.env.example` / deploy docs state: leave `BILLING_ENFORCED` unset or `true` in production; local/CI use `false`.
- Add a short deploy note in this JEM and owner runbook: flip enforcement only after paywall+IAP build is live (prefer fail-closed cutover per `SUBSCRIPTION_JEM` §15).
- Engineering does **not** set production `BILLING_ENFORCED=true` without owner sign-off recorded in the owner runbook.

##### Exit (G1 overall — Gate 1)

| Gate | Required | Who |
|---|---|---|
| G1.a–c code complete, API suite green | GO | Engineering |
| Sandbox purchase on TestFlight / Play internal moves account paywall → tabs | GO | Owner + engineering |
| Cancel/pause leaves month until `paid_until` | GO | Owner + engineering |
| Production `BILLING_ENFORCED=true` only after above | GO | Owner |

**Rollback.** Keep enforcement off; disable IAP button behind a remote flag only if one already exists — otherwise ship a build that reverts Subscribe to unavailable rather than charging incorrectly.

---

### G2 — Store-facing completeness (Gate 2)

**Objective.** The binary a reviewer installs matches what review notes promise; no placeholder purchase paths; deep links and account deletion remain reachable.

**Depends on owner.** Listings, screenshots upload, age/data safety forms, attaching `wayoa.monthly` to the binary, pasting review notes (`SUBSCRIPTION_JEM` §16) — owner runbook §G.

#### Work (engineering)

| Item | Detail |
|---|---|
| Review walk script | Add `docs/STORE_REVIEW_WALK.md` **or** a section in the owner runbook that engineering can execute on a device: open paywall → see price/period/renewal **before** sheet → terms/privacy → delete account → restore → Sign in with Apple on iOS beside Google |
| Screenshot set | Document required frames (discover, experience, itinerary, paywall with price visible, delete account). Prefer capturing from the preview build; do not invent marketing claims |
| Copy audit | Grep mobile for `REPLACE-ME`, `lorem`, `TODO`, stub Alert strings on purchase paths; remove any remaining |
| Apple encryption | Confirm `ITSAppUsesNonExemptEncryption` / Expo `usesNonExemptEncryption` matches reality |
| Deep links | Re-verify association files still 200 after deploy; no new claimed paths without screens |
| Internal distribution | Ensure `eas.json` submit tracks (`internal` Android, ASC app id) remain correct |

Already present (do not rebuild): Sign in with Apple on iOS (`SocialSignIn`), delete from paywall, terms/privacy links, production API URLs in EAS.

#### Tests / checks

```bash
curl -sI https://experience.synteric.co.uk/.well-known/assetlinks.json
curl -sI https://experience.synteric.co.uk/.well-known/apple-app-site-association
cd mobile && npm run typecheck
# browser suite if CI job present; else local Playwright
```

#### Exit criteria

| Gate | Required | Who |
|---|---|---|
| No stub purchase alert on native release build | GO | Engineering |
| Device walk of §16 notes succeeds | GO | Owner + engineering |
| Deep link files 200, JSON valid | GO | Engineering |
| Listing fields + subscription attached to binary | GO | Owner |

**Rollback.** Pause store submission; keep internal track only.

---

### G3 — Legal honesty (Gate 3)

**Objective.** Terms and privacy stay live, accurate for subscriptions, and account erasure/export still work under the gated product.

**Depends on owner.** Put both URLs in both store listings; confirm UK consumer-cancellation wording with whoever owns legal (owner runbook §H). Engineering does not practise law; it keeps pages and product behaviour aligned with committed copy.

#### Work (engineering)

| Item | Detail |
|---|---|
| Export | `php artisan experience:export-legal` remains the publish path; deploy continues to publish privacy HTML |
| Terms | Confirm exported/hosted terms include: £1.99, monthly auto-renew, paid month runs to end, Android pause vs iPhone non-resume, no card storage, delete ≠ cancel subscription, refunds via Apple/Google |
| Privacy | Confirm subscriptions section: store, product id, original transaction id, period bounds, state; no card data |
| Product | Regression: delete account reachable with paywall up; privacy export still authenticated; journal/export not broken by 402 gate (export/auth/legal routes must remain ungated — verify middleware list) |
| Canonical URLs | Prefer listing URLs that return **200 without redirect**. If `/terms` redirects to `/legal/terms`, listings should use the final URL |

#### Tests

- Existing `LegalPagesTest`, `PrivacyTest`, account deletion features stay green.
- Feature: with enforcement on, `GET /privacy/export` and delete still succeed for a signed-in user (add if missing).
- Curl check in CI or deploy smoke for both legal URLs.

#### Exit criteria

| Gate | Required | Who |
|---|---|---|
| Terms + privacy 200 on owned domain | GO | Engineering (verify) |
| Subscription-related copy present in views | GO | Engineering |
| Erasure/export work with billing enforced in tests | GO | Engineering |
| Listing URLs updated | GO | Owner |

**Rollback.** Unpublish store listing changes; keep prior policy HTML if a bad export ships.

---

### G4 — Launch-city product proof (Gate 4)

**Objective.** Give operators and founders the evidence to answer “would you pay £1.99?” for three named cities — without pretending the answer is a unit test.

**Depends on owner.** Choose the three cities and perform the traveller walk (owner runbook §I). Engineering supplies catalogue health and fixes only agreed gaps.

#### Work (engineering)

| Item | Detail |
|---|---|
| City pack | Document the three launch cities in the owner runbook once chosen; seed/activation must leave them `ready` with enough published experiences |
| Imagery | `php artisan experience:backfill-imagery` for those destination ids/slugs; do not invent photos |
| Quality signal | Use existing admin destination-quality / published counts; optionally add an artisan report `experience:launch-city-report {slug}` that prints published count, kinds, % with photo, % with hours — **only if** it saves repeated SQL; otherwise script the queries in the owner runbook |
| Known limits | Surface honestly in the report notes: itinerary max days (31 after pending merges / 14 if not yet shipped — read `experience.itinerary.max_planned_days` at implementation time), no group votes, no offline mode |
| Cost note | Log or document approximate Anthropic cost risk per guide session for owner acceptance |

#### Tests

- No flaky live-provider tests in CI.
- If a report command is added: feature test with seeded destination.

#### Exit criteria

| Gate | Required | Who |
|---|---|---|
| Three cities named and catalogue report available | GO | Both |
| Imagery/hours gaps filed or fixed for those cities | GO | Engineering |
| Written GO/HOLD from traveller walk | GO | Owner |

**Rollback.** Do not enable billing if owner issues HOLD on Gate 4 — product gap, not a store bug.

---

### G5 — Operability (Gate 5)

**Objective.** Paid traffic does not depend on a silent queue or unbounded provider spend; engineers can see failure modes the health endpoint already exposes.

**Depends on owner.** Host cron, backup restore drill, Cloud/Apple spend alerts, on-call roster (owner runbook §J).

#### Work (engineering)

| Item | Detail |
|---|---|
| Health | Keep `queue_worker_alive` honest (deploy already clears heartbeat and drains once). Add runbook steps if flap recurs: verify cPanel cron `* * * * * php artisan schedule:run` |
| Provider status | `php artisan experience:provider-status` remains the no-secret local check; link from owner runbook |
| Failed jobs | Document `php artisan queue:failed` / retry; ensure activation stale recovery still scheduled |
| Billing observability | Log subscription refresh outcomes with store + result code (no tokens in logs). Optional: admin-only count of active subscriptions — only if needed for support Gate 6 later |
| Rollback doc | In-repo short section (below) for turning billing off and lowering activation % without a code rollback |
| Alerts hooks | If no APM exists, define the minimum human poll: health JSON + failed_jobs + provider-status after deploy |

#### In-repo rollback (engineering-owned instructions)

```bash
# Stop charging access requirement immediately
# On API host .env:
BILLING_ENFORCED=false
php artisan config:clear && php artisan config:cache

# Stop new destination spend
DESTINATION_ACTIVATION_ENABLED=false
php artisan config:clear && php artisan config:cache
php artisan queue:restart
```

Store binary rollback remains owner-runbook (Play staged / Apple phased).

#### Exit criteria

| Gate | Required | Who |
|---|---|---|
| Health shows `queue_worker_alive: true` under normal load for 24h sample | GO | Both |
| Cron `schedule:run` confirmed on host | GO | Owner |
| Backup restore tested once | GO | Owner |
| Spend ceilings / budget alerts exist for Places + Anthropic | GO | Owner |
| Rollback commands verified on staging or prod with attestation | GO | Both |

**Rollback.** Use switches above; do not drop billing tables.

---

## 7. Cross-cutting requirements

### Security

- Refresh and notification endpoints never trust the client for `paid_until`.
- No IAP secrets in the mobile bundle; only public product ids.
- Play/Apple private keys only on the API host.
- Rate-limit refresh routes (throttle) to limit token stuffing.

### Privacy

- Delete account unlinks entitlement; does not cancel store subscription (existing copy).
- Export and legal routes stay reachable when product routes return 402.

### Compatibility

- Expo SDK 57 pin for `expo-iap`; run `npx expo install --check` after adding.
- Do not run `npm audit fix --force` (see `SECURITY_ACCEPTANCES.md`).

### Market scope

- Currency copy £ / GBP only for this pass.
- Destinations outside the UK remain usable for UK travellers abroad.

---

## 8. Test plan summary

| Layer | Gate focus |
|---|---|
| PHPUnit billing features | G1 refresh, Play reader fake, 409, enforcement matrix |
| PHPUnit legal/privacy/delete | G3 with enforcement on |
| Mobile typecheck | G1–G2 |
| Playwright paywall/web | G1 web non-purchase, G2 links, G3 delete entry |
| Device sandbox | G1 proof (owner runbook §F) |
| Production curls | G0/G5 health; G2/G3 well-known + legal |

---

## 9. Rollout sequence (combined with owner)

```text
1. G0 freeze
2. Owner §§A–D (merchant, products, secrets) while G1.a–b are coded
3. Deploy API with refresh + Play reader; notification URLs already pointed (owner)
4. G1.c + EAS preview/internal builds
5. Owner §F sandbox walk — both stores
6. G2–G3 listing and legal URL confirmation
7. G4 city walk GO
8. G5 cron/backup/alerts attested
9. Submit paywall binary; on release day set BILLING_ENFORCED=true (or leave default true if unset)
10. Staged Play / phased Apple; watch health + spend
```

Preferred enforcement cutover: fail closed when the paywall build reaches 100% (see `SUBSCRIPTION_JEM` §15).

---

## 10. Non-goals

- Stripe or web checkout.
- RevenueCat as access authority.
- Pricing outside UK storefronts.
- Gates 6–7 full programme (support playbooks beyond pointers; undo is covered by switches above).
- Group votes, admin UI rebuild, offline mode.
- Enabling `DESTINATION_PREWARM` or jumping activation to 100% without the destination GA ladder.

---

## 11. Status tracking

Update as phases exit. Owner-blocked rows stay explicit.

| Phase | Name | Code status | Owner dependency |
|---|---|---|---|
| G0 | Baseline freeze | Done (docs + kill-switch table) | Confirm prod env inventory |
| G1.a | Play subscription read | Done — `GooglePlaySubscriptionReader` | Play service account JSON for live smoke |
| G1.b | Refresh endpoints | Done — `POST /billing/{apple,google}/refresh` | Apple key + Play account for live smoke |
| G1.c | expo-iap purchase/restore | Done — `NativeSubscriptionActions` + plugin | EAS + sandbox testers |
| G1.d | Enforcement cutover | Documented; not flipped in prod | Sign-off after owner §F walk |
| G2 | Store-facing | Done — `STORE_REVIEW_WALK.md` + stub removed | Listings + attach subscription |
| G3 | Legal honesty | Done — enforcement privacy/export tests | Listing URLs + legal review |
| G4 | Launch cities | Done — `experience:launch-city-report` | Name cities + traveller GO/HOLD |
| G5 | Operability | Done — rollback docs in JEM/owner runbook | Cron, backup restore, spend alerts |

**Two code gaps still block the sandbox walk itself:** purchase (`expo-iap`) and receipt refresh (+ Play period read). Everything else in Gates 2–5 is incomplete evidence or owner work, not a missing product concept.

---

## 12. Acceptance (Gates 1–5)

1. A sandbox purchase produces a subscription row with future `paid_until` and opens product APIs for that user.
2. Before that charge, discovery returns 402 when enforcement is on.
3. Restore on a second install same account recovers the month; other live account gets 409.
4. Paywall shows price, period, auto-renewal, terms, privacy, and delete **before** the store sheet.
5. Terms and privacy return 200 on the owned domain and describe the subscription facts in §G3.
6. Owner records GO on three launch cities or issues HOLD (Gate 4).
7. Production health shows living queue workers; billing and activation kill switches are documented and attested.

When 1–7 hold, Gates 1–5 are ready for a market-readiness re-audit. Gates 6–7 still required for full **LAUNCH** verdict per the market readiness agent.
