# WAYOA — Paid Launch Owner Runbook

**Status:** Operational checklist  
**Audience:** Founder / operator / whoever holds App Store Connect, Play Console, bank, hosting, and support  
**Objective:** Close every Gate 1–5 item that **cannot** be done from the repository alone, in the order engineering needs them.

**Companion.** Engineering phases and acceptance live in [`PAID_LAUNCH_GATES_JEM.md`](PAID_LAUNCH_GATES_JEM.md). Subscription policy and review-note paste live in [`SUBSCRIPTION_JEM.md`](SUBSCRIPTION_JEM.md) §10 and §16. Do not treat a green test suite as a substitute for a checked box here.

**Rule.** Mark each item `done` only when you have seen the console, bank email, or device yourself. “Should be fine” is not done.

**Market.** United Kingdom only for this pass. Price **£1.99 GBP / month**. Identifiers: `uk.co.synteric.experience`. Apple product `wayoa.monthly`. Google subscription `wayoa_monthly`, base plan `monthly`.

---

## How to use this runbook

1. Work **sections A → J in order** unless a section says it can run in parallel.
2. When engineering asks for a secret or product id, give them the value out of band (password manager / host env) — **never commit it**.
3. After any production `.env` change: `php artisan config:clear && php artisan config:cache` (and `queue:restart` if workers hold config).
4. When the sandbox walk (§F) is green, you — not the coding agent — decide when to set `BILLING_ENFORCED=true` on production.

---

## A. Business and legal (unblocks products and listings)

**Gate:** 1 (merchant) + 3 (sell legally)

- [ ] **Apple Paid Applications agreement** accepted in App Store Connect → Business.
- [ ] **UK bank account and tax forms** accepted by Apple (paid apps will not sell without this).
- [ ] **Google Play payments profile / merchant account** complete so a GBP subscription can be priced.
- [ ] **Apple Small Business Program** (optional): enrol if under the threshold — changes commission, not required to ship.
- [ ] **Terms URL in both listings** — use the canonical page that returns 200:
  - `https://experience.synteric.co.uk/legal/terms`
- [ ] **Privacy URL in both listings** (and subscription EULA / custom EULA fields as Apple requires):
  - `https://experience.synteric.co.uk/privacy` or `https://experience.synteric.co.uk/legal/privacy` — pick **one** canonical URL and use it everywhere (listings + `EXPO_PUBLIC_PRIVACY_URL` + `PRIVACY_POLICY_URL`).
- [ ] Confirm both URLs yourself:

```bash
curl -sS -o /dev/null -w '%{http_code} %{url_effective}\n' -L \
  https://experience.synteric.co.uk/legal/terms \
  https://experience.synteric.co.uk/legal/privacy
```

Prefer **200 with no redirect chain** for the URL you paste into the stores.

- [ ] **Legal review (human):** terms state £1.99, monthly auto-renew, paid month runs to end, Android pause resumes itself, iPhone does not auto-resume, WAYOA holds no card, deleting the account does not cancel the store subscription, refunds via Apple/Google; UK consumer cancellation addressed as your counsel requires.
- [ ] **Privacy review (human):** subscriptions section lists store, product id, original transaction id, period start/end, state; no card data.

If copy must change: edit the Blade sources, bump `EXPERIENCE_PRIVACY_UPDATED`, run `php artisan experience:export-legal`, redeploy / publish HTML, then update listing dates if the stores show them.

---

## B. App Store Connect — subscription product (unblocks iOS sandbox)

**Gate:** 1

- [ ] Subscription group named WAYOA, reference `wayoa`.
- [ ] Auto-renewable subscription product id **`wayoa.monthly`**, duration 1 month.
- [ ] UK price **£1.99**. Leave other countries unpriced for this pass.
- [ ] No introductory offer, no free trial.
- [ ] Family Sharing **off**.
- [ ] Billing grace period **off** for the app’s subscriptions.
- [ ] Localisation: display name + description (one month of WAYOA; renews until cancelled).
- [ ] Review information: paywall screenshot + notes from [`SUBSCRIPTION_JEM.md`](SUBSCRIPTION_JEM.md) §16 (fill real privacy/terms URLs).
- [ ] **In-App Purchase key:** Users and Access → Integrations → In-App Purchase. Create key, download `.p8` **once**, record Key ID and Issuer ID. Store as secrets (section D).
- [ ] **App Store Server Notifications V2:**
  - Production: `https://api.experience.synteric.co.uk/api/billing/apple/notifications`
  - Sandbox: same host only if that host is under test; otherwise staging host with the same path. Version **2 only**.
- [ ] **Sandbox Apple Accounts:** at least two under Users and Access → Sandbox (not real Apple IDs).
- [ ] Plan to **attach `wayoa.monthly` to the submitted binary** — creating the product alone is not enough for first review.

---

## C. Google Play Console — subscription product (unblocks Android sandbox)

**Gate:** 1

- [ ] Subscription id **`wayoa_monthly`**.
- [ ] Base plan id **`monthly`**, auto-renewing, 1 month, **£1.99**, UK only for this pass.
- [ ] No offers / free trial / intro price.
- [ ] **Pause enabled** on the base plan (Play’s allowed pause lengths).
- [ ] **Real-time developer notifications:**
  1. In the linked Google Cloud project, create a Pub/Sub topic.
  2. Push subscription endpoint: `https://api.experience.synteric.co.uk/api/billing/google/notifications`
  3. Grant `google-play-developer-notifications@system.gserviceaccount.com` Pub/Sub Publisher on the topic.
  4. Paste the topic into Play Console → Monetise → Monetisation setup.
- [ ] **Play Developer API service account:**
  1. Create service account in that Cloud project.
  2. Invite it in Play Console → Users and permissions with rights to view financial data and manage orders/subscriptions.
  3. Download JSON key → secret for section D.
- [ ] **License testers:** Gmail accounts that will install the internal-testing build.
- [ ] **Internal testing track:** billing-enabled AAB must be on this track for purchases to complete (sideload from a non-licensed machine will not).

---

## D. Secrets on the API host (unblocks live notification + refresh smoke)

**Gate:** 1 + 5

Set on the host that serves `api.experience.synteric.co.uk` (and staging if you have one). Never commit.

| Variable | Source |
|---|---|
| `APPLE_BUNDLE_ID` | `uk.co.synteric.experience` |
| `APPLE_ISSUER_ID` | App Store Connect issuer id |
| `APPLE_KEY_ID` | IAP key id |
| `APPLE_PRIVATE_KEY` | Full `.p8` contents |
| `APPLE_APP_APPLE_ID` | Numeric app id (ASC) |
| `APPLE_ROOT_CERTIFICATE` | If your deploy already expects it for JWS verify — keep as configured in code |
| `GOOGLE_PLAY_PACKAGE_NAME` | `uk.co.synteric.experience` |
| `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` | Play API service account JSON |
| `GOOGLE_PUBSUB_AUDIENCE` | Audience used to verify Pub/Sub OIDC tokens |
| `GOOGLE_PUBSUB_SERVICE_ACCOUNT` | If required by current verifier config — set to the pushing service account email |
| `BILLING_TESTER_EMAILS` | Optional comma-separated accounts for device QA before public enforcement |
| `BILLING_ENFORCED` | Leave `false` or unset-with-care until §F passes; production default when unset is enforced in config — **explicitly set `false` until cutover** if you need the API open |

After setting:

- [ ] `php artisan config:clear && php artisan config:cache`
- [ ] `curl -sS https://api.experience.synteric.co.uk/api/health` still returns healthy JSON
- [ ] Apple notification **test** from App Store Connect → server accepts / records
- [ ] Pub/Sub test push → signed accepted, unsigned rejected
- [ ] Confirm engineering has deployed **refresh routes** before relying on device purchase (`PAID_LAUNCH_GATES_JEM` G1.b)

---

## E. EAS / Expo project continuity (unblocks store builds)

**Gate:** 1 + 2

- [ ] Expo project on expo.dev resolves slug **`wayoa`** for project id `588b5410-…` (do **not** create a new project — resets remote build numbers; stores reject non-increasing version codes).
- [ ] `npx eas-cli@latest login` on the release machine.
- [ ] `eas credentials -p android` backed up; SHA-256 already published in `assetlinks.json` (re-check if you rotate keys — add new fingerprint **before** removing old).
- [ ] `APP_LINKS_IOS_TEAM_ID` already live (AASA returns 200); re-verify after any team change.
- [ ] Production/preview env in `mobile/eas.json` still points at:
  - `EXPO_PUBLIC_API_URL=https://api.experience.synteric.co.uk/api`
  - `EXPO_PUBLIC_PRIVACY_URL=https://experience.synteric.co.uk/privacy`
- [ ] Build only **after** engineering merges `expo-iap` + refresh (G1). Purchase does not work in Expo Go.

```bash
cd mobile
npx eas-cli@latest build --platform android --profile production   # or preview/internal
npx eas-cli@latest build --platform ios --profile production
```

- [ ] Upload Android AAB to **internal** testing; distribute iOS via **TestFlight**.
- [ ] Install as license / sandbox tester, not as a random production user.

---

## F. Sandbox proof walk (Gate 1 exit — both stores)

**Gate:** 1  
**Prerequisite:** G1 code shipped; §B–E done; enforcement can stay off on the server while you test with tester emails **or** use sandbox against a build that hits an environment with enforcement on — agree which with engineering first.

Walk on **TestFlight** and **Play internal**, signed in as a real WAYOA account (not guest).

- [ ] New account → paywall shows **£1.99**, one month, renews until pause/cancel, **before** any store sheet.
- [ ] Terms and privacy open in the browser.
- [ ] Delete account link visible on the paywall (do not have to delete yet).
- [ ] Subscribe → tabs stay closed until charge confirms → then Discover works.
- [ ] Force-quit and reopen → still entitled.
- [ ] Android: schedule pause → app still works until period end; subscription screen shows end date.
- [ ] iOS: stop next renewal via Apple’s page → same period-boundary behaviour.
- [ ] Advance sandbox renewal / wait shortened period → paywall returns; discovery fails closed when enforcement is on.
- [ ] Android: pause elapses + renewal succeeds → access returns.
- [ ] iOS: buy again → new `paid_until`.
- [ ] Restore on second install, **same** account → month returns.
- [ ] Restore while transaction owned by another live account → **409** / clear error, no access.
- [ ] Optional: delete account from paywall; confirm store subscription still exists in Apple/Google until cancelled there.

Record: date, build numbers, Apple sandbox Apple ID used, Play license tester, pass/fail per bullet.

**Only when this section is green** move to cutover (§K).

---

## G. Store listing and review acceptance (Gate 2)

**Gate:** 2

### Both stores

- [ ] Screenshots match the **current** build (discover, detail, plan, paywall with price visible).
- [ ] Description: no promise of free unlimited use of discovery/guide/booking.
- [ ] Age rating / content declarations completed.
- [ ] Privacy and terms URLs match §A canonical choices.
- [ ] Review notes pasted from `SUBSCRIPTION_JEM` §16 with real URLs.
- [ ] Walk those notes **literally** on the build you submit (reviewer will).

### Apple

- [ ] Subscription attached to the version you submit.
- [ ] Sign in with Apple offered wherever Google sign-in is (already in app on iOS — confirm on device).
- [ ] `ITSAppUsesNonExemptEncryption` / Expo encryption flag matches reality.
- [ ] Phased release plan chosen (recommended for first paid version).

### Google

- [ ] Data safety form matches actual collection (account, purchase tokens/ids on server, location precision if used, etc.).
- [ ] Target API level current for Play policy.
- [ ] AAB on internal testing installed by a license tester **before** production promotion.
- [ ] Staged rollout plan chosen for production.

---

## H. Legal listing close-out (Gate 3)

**Gate:** 3

- [ ] Both listing privacy fields set.
- [ ] Both listing terms / EULA fields set.
- [ ] Subscription metadata does not contradict in-app price or period.
- [ ] Support / privacy contact email monitored (`CONTACT_EMAIL` / `PRIVACY_CONTACT_EMAIL` on API and in listings).

---

## I. Launch-city worth walk (Gate 4)

**Gate:** 4  
Engineering can report catalogue counts; **only you** can say you would pay.

- [ ] Name three launch cities: `____________` / `____________` / `____________`
- [ ] For each city, as a traveller (not an engineer), on production or production-like data:

| Check | City 1 | City 2 | City 3 |
|---|---|---|---|
| Enough published experiences for a day | | | |
| Real photographs (not empty washes) | | | |
| Opening hours / prices plausible where shown | | | |
| Discovery sensible ~09:00 / 14:00 / 21:00 local | | | |
| Generated itinerary looks human-followable | | | |

Known limits to weigh (do not hide): itinerary day cap; uneven imagery; live Anthropic cost; no group votes; no offline mode.

- [ ] Written verdict: **GO** or **HOLD** for Gate 4, with date and name.
- [ ] If HOLD: list the minimum catalogue fixes before §K.

Optional helper (if engineering added it): `php artisan experience:launch-city-report {slug}`. Otherwise use admin destination-quality / DB counts.

---

## J. Keep it running (Gate 5)

**Gate:** 5

### Hosting and queue

- [ ] Cron every minute on the API host:

```cron
* * * * * cd /home/tripuwtd/api.experience.synteric.co.uk/api && /path/to/php artisan schedule:run >> /dev/null 2>&1
```

(Adjust user/path/php binary to the live host; `deploy.sh` cannot create this for you.)

- [ ] `GET /api/health` → `destination_pipeline.queue_worker_alive: true` when checked morning and evening for a day.
- [ ] If false: fix cron/workers before paid launch; see [`PROVIDER_SMOKE_RUNBOOK.md`](PROVIDER_SMOKE_RUNBOOK.md) and [`DYNAMIC_DESTINATION_RUNBOOK.md`](DYNAMIC_DESTINATION_RUNBOOK.md).

### Backups

- [ ] Automated database backups exist.
- [ ] **Restore tested once** to a scratch database (date + who + outcome). A backup that has never been restored is unverified.

### Spend and billing alerts

- [ ] Google Cloud budget alerts for Places (and the billing account generally).
- [ ] Anthropic usage/budget alert or hard monthly cap you will notice.
- [ ] Viator / Tiqets commercial spend awareness (even if invoice-based).
- [ ] Apple / Play financial email recipients correct for the entity.

### Activation ladder (do not skip)

Follow [`DESTINATION_ACTIVATION_GA_RUNBOOK.md`](DESTINATION_ACTIVATION_GA_RUNBOOK.md). Current production baseline was **5%** with prewarm off.

- [ ] Observe 5% window (spend, stuck imports, 5xx).
- [ ] Raise to 25% only after observation.
- [ ] Raise to 100% only after 25% healthy.
- [ ] Keep `DESTINATION_PREWARM_ENABLED=false` until aggregate-demand retention is explicitly approved.

### On-call and support (also Gate 6 — minimum for paid week one)

- [ ] Named on-call for launch week.
- [ ] Support inbox monitored; same address on both listings.
- [ ] Refund stance decided: Apple/Google issue refunds; internal FAQ one-pager for “how do I cancel?”.
- [ ] Someone can look up a subscription state (DB / admin) and explain `paid_until` to a traveller.

---

## K. Cutover and undo (after Gates 1–5 evidence)

**Do not run this section early.**

### Enable paid access

- [ ] Paywall + IAP binary is what TestFlight / internal testers use successfully (§F).
- [ ] Prefer store rollout at 100% for the paywall build **or** accept fail-closed for old binaries (`SUBSCRIPTION_JEM` §15).
- [ ] On API host set:

```bash
BILLING_ENFORCED=true
php artisan config:clear && php artisan config:cache
```

(If the variable was explicitly `false`, flipping to `true` is required. If unset, config already defaults to enforced — still verify with a non-tester account that discovery returns 402.)

- [ ] Smoke: unpaid account → paywall / 402; tester or paid sandbox → product open.
- [ ] Watch health, failed_jobs, provider spend for the first 24–48 hours.

### Emergency undo

```bash
# Open the product without payment (incident only)
BILLING_ENFORCED=false
php artisan config:clear && php artisan config:cache

# Stop new destination import spend
DESTINATION_ACTIVATION_ENABLED=false
php artisan config:clear && php artisan config:cache
php artisan queue:restart
```

- [ ] Play: halt staged rollout / halt production release as needed.
- [ ] Apple: pause phased release if used.
- [ ] Who is allowed to flip these switches: `________________`

---

## L. Evidence log (fill as you go)

| Date | Section | Evidence (console screenshot id / curl / build #) | Owner |
|---|---|---|---|
| | A | | |
| | B | | |
| | C | | |
| | D | | |
| | E | | |
| | F | | |
| | G | | |
| | H | | |
| | I | GO / HOLD: | |
| | J | | |
| | K | | |

---

## M. What engineering already shipped (do not rebuild)

Tracked in [`PAID_LAUNCH_GATES_JEM.md`](PAID_LAUNCH_GATES_JEM.md) — implemented on
`feat/paid-launch-gates-1-5`:

- Play Developer API subscription period read (`GooglePlaySubscriptionReader`)
- `POST /billing/apple/refresh` and `POST /billing/google/refresh`
- `expo-iap` purchase + restore on the paywall (`NativeSubscriptionActions`)
- [`STORE_REVIEW_WALK.md`](STORE_REVIEW_WALK.md)
- Privacy/export/delete regressions with billing enforced
- `php artisan experience:launch-city-report {slug}`
- In-repo rollback commands in the JEM / §K above

Your remaining work is still §§A–K (consoles, secrets, sandbox walk, cutover).

---

## N. Related runbooks

| Doc | Use when |
|---|---|
| [`SUBSCRIPTION_JEM.md`](SUBSCRIPTION_JEM.md) §10 / §16 | Product ids, secrets table, review notes paste |
| [`RELEASE.md`](RELEASE.md) | Privacy export, deep links, first AAB |
| [`PROVIDER_SMOKE_RUNBOOK.md`](PROVIDER_SMOKE_RUNBOOK.md) | Provider keys, queue heartbeat |
| [`DESTINATION_ACTIVATION_GA_RUNBOOK.md`](DESTINATION_ACTIVATION_GA_RUNBOOK.md) | Activation % ladder |
| [`DYNAMIC_DESTINATION_RUNBOOK.md`](DYNAMIC_DESTINATION_RUNBOOK.md) | Stuck imports, kill switch |
| [`qa/WAYOA_MARKET_READINESS_AGENT.md`](qa/WAYOA_MARKET_READINESS_AGENT.md) | Re-audit Gates 1–7 for LAUNCH verdict |
