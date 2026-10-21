# Interlude — Subscription JEM

**Status:** Proposed implementation specification
**Scope:** Laravel billing domain, Expo iOS and Android, App Store Connect, Google Play Console, legal pages
**Objective:** Make WAYOA usable only while a paid £1.99 month is in place, and let that subscription be paused without cutting the month already paid for short.

**Related docs**

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — domains and the guest-session model this gate sits on
- [`API.md`](API.md) — current route surface; billing routes below are new
- [`RELEASE.md`](RELEASE.md) — privacy URL, store listings, and the release sequence
- [`PRODUCTION_READINESS_JEM.md`](PRODUCTION_READINESS_JEM.md) — store release (P6) must include this paywall before a public subscription build

The customer-facing name is WAYOA. Store identifiers stay `uk.co.synteric.experience`.

---

## 1. Executive summary

WAYOA is free to open and free to set up. It is not free to use. Use means discovery, the guide, itinerary generation, destination activation, and booking. Those stay closed until a £1.99 month has been paid and that period still covers now.

Pause is part of the product, because travel is episodic. A pause never refunds or shortens the month already bought. It takes effect when that month ends. After that, the app does not work until another paid month is active.

The stores are the merchant of record. Laravel is the authority for “can this account use WAYOA right now?”. The phone starts the purchase and renders the paywall. It does not decide access.

```text
sign in → buy £1.99 month → store confirms payment
  → Laravel stores the paid period → product APIs open
pause or cancel → current month keeps working until period end
  → access closes → resume only after the next paid month is confirmed
```

Google Play has a real pause. The App Store does not. The same access rule applies on both; the button that gets the traveller there is platform-specific. Section 10 is the list of actions that have to happen in Apple, Google, the bank, and the legal pages before the code can be proven.

---

## 2. Problem statement

Nothing in the API or the app checks payment. A guest session can call discovery, activation, the guide, and bookings, and those calls spend Google Places, Viator, and Anthropic money.

A subscription added only in the client will be bypassed, and a subscription billed outside the stores will be rejected. A pause that cuts off a month already paid for will also be rejected, and on iPhone it cannot be implemented as an automatic resume.

If the paid month, the pause, and the account are not the same record, restore-on-a-new-phone and account deletion both break.

---

## 3. Product principles

1. **The month is the entitlement.** A subscription row can be “set to pause” and still be useless. Access is a paid period that includes now.
2. **The smallest duration is one month.** No week, no day pass, no free trial, no introductory offer.
3. **Pause starts at the period boundary.** The paid month keeps working. The next charge does not happen. Use stops when the period ends.
4. **Pending is not paid.** The gate opens after the store reports a successful charge, not when the sheet is presented.
5. **A failed renewal does not extend use.** Billing retry and grace do not count as a month in place.
6. **The server decides.** Product routes refuse when the paid period does not cover now. The client gate is there so the traveller sees a paywall instead of an error.
7. **One store subscription, one WAYOA account.** Restore attaches the receipt to the signed-in user. It does not clone access onto a second account.
8. **The stores take the money.** Apple and Google charge, refund, and remit. WAYOA stores transaction ids and period dates, never card numbers.
9. **Say what the platform will actually do.** Android pause resumes by itself. iPhone “pause” stops the next renewal, and coming back is a new purchase.

---

## 4. Goals

### User goals

- Pay £1.99 and have WAYOA work for that month.
- Keep using the app until the end of a month already paid for, even after asking to pause or cancel.
- Be unable to use the product once that month has ended, until another paid month is active.
- On Android, pause for a chosen length and have Play try to charge again when the pause ends.
- On iPhone, stop the next renewal from inside the subscription screen, via Apple’s own page.
- Restore the same month on a new install by signing in.

### Product goals

- One product id, one monthly price, both stores.
- Entitlement written only from verified store notifications, or from a server-side refresh of a receipt the store still vouches for.
- Discovery, guide, itinerary generation, destination activation, and bookings return a stable refusal when no paid month covers now.
- Account deletion unlinks the entitlement and tells the traveller the store subscription continues until they stop it there.

### Initial success targets

| Check | Target |
|---|---|
| Access before a confirmed charge | 0 |
| Access during an in-effect pause, or after period end | 0 |
| Access during a paid period, including one with pause or cancel scheduled | 100% |
| Same original transaction on two live accounts | 0 |
| Sandbox month confirmed to API open | Both stores |

---

## 5. Non-goals

- Stripe, or any web checkout. The Expo web build shows the paywall and tells the traveller to subscribe in the iOS or Android app.
- A billing vendor as the access authority. RevenueCat or similar may forward notifications later. “Can this person use WAYOA?” stays in Postgres.
- Family Sharing. One £1.99 month is one account.
- Billing grace as usable time.
- Prorated refunds issued by WAYOA. Refunds stay in Apple and Google.
- Weekly, yearly, or lifetime products in this pass.
- Gating onboarding itself. Setting destination, purpose, interests, and stay length remains free. The product behind that setup does not.

---

## 6. Access rule

Customer price **£1.99**, billing period **1 month**, auto-renewing, currency **GBP**. UK storefront price is the one that matters for launch. Other storefronts are off until explicitly priced. No trial. No offer. Family Sharing off. Apple billing grace off.

Product ids, used by both the consoles and the code:

| Store | Id |
|---|---|
| Apple subscription group | `wayoa` |
| Apple product | `wayoa.monthly` |
| Google subscription | `wayoa_monthly` |
| Google base plan | `monthly` |

`can_use` is true only when all of these hold:

- the signed-in user owns the entitlement;
- the last **successful** charge’s period includes now (`paid_until > now`);
- state is `active` or `active_until_period_end`;
- the charge was for this product at the monthly price (sandbox prices count; a trial would not, and there is no trial).

| Situation | State | App works |
|---|---|---|
| Charge confirmed, renewal on, period includes now | `active` | Yes |
| Pause or cancel requested, period still includes now | `active_until_period_end` | Yes, until `paid_until` |
| Google pause has taken effect | `paused` | No |
| Period ended, or renewal failed | `expired` | No |
| Purchase not yet paid | `pending` | No |
| Refunded or revoked | `revoked` | No |
| No entitlement | `inactive` | No |

`paid_until` comes from the last successful transaction. A grace-extended store expiry is ignored.

---

## 7. Personas and user stories

### Traveller

- As a traveller, I want to finish setup and then see exactly what a month costs before any recommendation is generated.
- As a subscriber, I want the month I paid for to keep working after I ask to pause.
- As someone between trips, I want the app to stay off, and not charge me, until I start another paid month.
- As a returning traveller, I want a reinstall to pick up the month I already paid for.

### Android subscriber

- As an Android subscriber, I want to pick how long to pause, and have Play resume billing when that pause ends.

### iPhone subscriber

- As an iPhone subscriber, I want to stop the next renewal in Apple’s subscription page, and I want the app to tell me that WAYOA will not turn itself back on.

### Store reviewer

- As a reviewer, I want the price, the month, the auto-renewal, and the way to cancel visible before purchase.
- As a reviewer, I want privacy and terms links that open, and account deletion that still works when the paywall is up.

---

## 8. Journey experience map

| Stage | Traveller action | WAYOA response | System work | Failure recovery |
|---|---|---|---|---|
| Set up | Onboarding | Journey saved, no recommendations yet | Existing profile and journey APIs, still open | Draft kept locally |
| Arrive at the product | Finish onboarding, or open the app later | Paywall if `can_use` is false | `GET /billing/entitlement` | Cached denial; retry on foreground |
| Identify | Sign in with Apple, Google, or email | Account ready to own a receipt | Existing `/auth/*` | Validation errors |
| Buy | Subscribe | Store sheet for £1.99 / month | StoreKit 2 or Play Billing; receipt posted to Laravel | Pending stays on the paywall |
| Confirm | Wait for the charge | Tabs open | Notification or receipt refresh sets `paid_until` | “Still confirming” with restore |
| Use | Discover, guide, plan, book, activate a city | Normal product | Middleware allows the request | 402 sends them back to the paywall |
| Pause | Ask to pause | Date they keep access until | Android: Play pause. iOS: Apple subscription management | If they back out, state unchanged |
| Lapse | Open the app after `paid_until` | Paywall | Entitlement state `paused` or `expired` | Restore if the store still has a paid period |
| Resume | Subscribe again, or let Play resume | Closed until the new charge succeeds | New `paid_until` | Pending stays closed |
| Leave | Delete account | Profile erased; store sub still exists until they stop it | Unlink entitlement; deep link to the store | Copy on the delete screen |

Root redirect in [`mobile/app/index.tsx`](../mobile/app/index.tsx): onboarded and `can_use` goes to `/(tabs)`. Onboarded and not `can_use` goes to the paywall. Not onboarded still goes to onboarding.

---

## 9. Current baseline

| Layer | Today |
|---|---|
| Billing domain | None |
| Access check | None. Guest middleware on [`api/routes/api.php`](../api/routes/api.php) opens the product to anyone with a guest token |
| Identity | Guest sessions, email, Google, and Apple sign-in already exist |
| Account deletion | `DELETE /auth/account` exists and must keep working from the paywall |
| Privacy page | Served at `/legal/privacy`. No terms page. No subscription section |
| Mobile purchase | None. Expo Go cannot host in-app purchase; a dev-client or EAS build is required |
| Store products | None |

---

## 10. Actions outside the code

These are yours. Each one names the phase it unblocks. Engineering can build the domain and the paywall against the product ids in section 6 before the consoles exist. Sandbox proof cannot start until the matching row here is done.

Do them in this order. Banking blocks product creation. Product creation blocks notifications. Notifications block a trustworthy entitlement. A new binary blocks review.

### A. Business and legal — unblocks P0

- [ ] **Paid Applications agreement.** In App Store Connect, under Business, accept the Paid Apps agreement. Add the UK bank account and tax forms Apple asks for. Until this is accepted, no subscription can be created or tested.
- [ ] **Google merchant account.** In Play Console, finish the payments profile and merchant setup so a subscription product can be priced in GBP.
- [ ] **Small Business Program.** If trailing revenue is under Apple’s threshold, enrol. This changes the commission on £1.99. It is not required to ship.
- [ ] **Terms of use.** After the code adds `/legal/terms`, publish that page on `experience.synteric.co.uk` the same way the privacy page is published (`php artisan experience:export-legal`, then the hosting step in [`RELEASE.md`](RELEASE.md)). The terms have to state: £1.99 per month, auto-renews until the traveller stops it, the paid month keeps working until it ends, Android pause resumes by itself, iPhone does not auto-resume, WAYOA does not store card numbers, deleting the WAYOA account does not cancel the store subscription, refunds are requested from Apple or Google.
- [ ] **Privacy update.** Add a short subscriptions section: we store store, product id, original transaction id, period start and end, and subscription state, in order to decide access. We do not receive the card. Bump `EXPERIENCE_PRIVACY_UPDATED` when the copy changes, and re-publish the HTML.
- [ ] **Store listing URLs.** Put the privacy URL and the terms URL in App Store Connect (the subscription’s EULA field, or a custom EULA link) and in the Play Console listing. Apple’s subscription rule requires both links in the listing and in the app. The in-app links are a code task; the listing fields are this task.

### B. App Store Connect — unblocks P1 and P3 for iOS

- [ ] **Subscription group** named WAYOA, reference name `wayoa`.
- [ ] **Auto-renewable subscription** product id `wayoa.monthly`, duration 1 month, UK price £1.99. Leave every other country unpriced for this pass.
- [ ] **No introductory offer and no free trial.**
- [ ] **Family Sharing off** for the product.
- [ ] **Billing grace period off** for the app’s subscriptions.
- [ ] **Localisation:** display name and a description that says it is one month of WAYOA and that it renews until cancelled.
- [ ] **Review information** for the subscription: a screenshot of the paywall, and the review notes in section 16.
- [ ] **In-App Purchase key.** Users and Access → Integrations → In-App Purchase. Create a key. Download the `.p8` once. Record the key id and the issuer id. This file is a secret. It goes in the server env, never in git.
- [ ] **App Store Server Notifications V2.** Production URL `https://experience.synteric.co.uk/api/billing/apple/notifications`. Sandbox URL can be the same host only if that host is the one under test; otherwise point sandbox at the staging host. Version 2 only.
- [ ] **Sandbox testers.** Create at least two Sandbox Apple Accounts under Users and Access → Sandbox. They are not real Apple IDs. Use them on a device signed out of the real Media & Purchases account, or via the Sandbox Account setting on iOS.
- [ ] **Attach the subscription to the version.** The first time this product is reviewed, it has to be submitted with a new binary. Creating it in the console is not enough.

### C. Google Play Console — unblocks P1 and P3 for Android

- [ ] **Subscription** id `wayoa_monthly`.
- [ ] **Base plan** id `monthly`, auto-renewing, billing period 1 month, price £1.99, UK. No other regions in this pass.
- [ ] **No offers.** No free trial, no introductory price.
- [ ] **Pause enabled** on that base plan. Leave the allowed range at Play’s own bounds (one week to three months). Access still ends only when the current month ends; the range only chooses how long billing stays stopped after that.
- [ ] **Real-time developer notifications.** In a Google Cloud project linked to the Play app, create a Pub/Sub topic and a push subscription whose endpoint is `https://experience.synteric.co.uk/api/billing/google/notifications`. Grant `google-play-developer-notifications@system.gserviceaccount.com` the Pub/Sub Publisher role on the topic. Paste the topic name into Play Console → Monetise → Monetisation setup.
- [ ] **Play Developer API service account.** Create a service account in that Cloud project, invite it in Play Console → Users and permissions with permission to view financial data and manage orders and subscriptions. Download the JSON key. Secret. Server env only.
- [ ] **License testers.** Add the Gmail accounts that will install the internal-testing build. Internal testing is the track that can exercise billing before production.
- [ ] **Internal testing release.** Upload the billing-enabled AAB to the internal track. A sideloaded debug build from a machine that is not on that track will not complete a Play purchase.

### D. Secrets and deploy — unblocks P3

Put these in the production (and staging) environment. Do not commit them. After they are set, deploy the billing routes before pointing the consoles at those URLs, or the first notification will 404.

| Name | Where it comes from |
|---|---|
| `APPLE_ISSUER_ID` | App Store Connect, issuer id |
| `APPLE_KEY_ID` | In-App Purchase key id |
| `APPLE_PRIVATE_KEY` | Contents of the `.p8` |
| `APPLE_BUNDLE_ID` | `uk.co.synteric.experience` |
| `APPLE_APP_APPLE_ID` | Numeric app id from App Store Connect |
| `GOOGLE_PLAY_PACKAGE_NAME` | `uk.co.synteric.experience` |
| `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` | Play Developer API key JSON |
| `GOOGLE_PUBSUB_AUDIENCE` | Push-subscription audience used to verify the Pub/Sub token |

- [ ] Set the variables above on the host that serves `experience.synteric.co.uk`.
- [ ] Confirm `GET /api/health` still answers after deploy.
- [ ] Send Apple’s notification test from App Store Connect and confirm the server records it.
- [ ] Publish a test message to the Pub/Sub topic and confirm the Google endpoint accepts a signed push and rejects an unsigned one.

### E. Builds and review — unblocks P5 and P6

- [ ] **EAS build.** In-app purchase does not run in Expo Go. Produce an iOS build for TestFlight and an Android AAB for the Play internal track after the purchase plugin is in `app.json`.
- [ ] **Install via TestFlight and the internal track,** signed in as the sandbox or license tester, not as a casual production install.
- [ ] **Walk section 16** on both platforms and keep the notes.
- [ ] **App Review notes.** Paste the notes from section 16 into App Store Connect. Include the sandbox account only if Apple asks for a demo login to the WAYOA account. The purchase itself uses the reviewer’s sandbox, not a real card.
- [ ] **Submit the iOS version with `wayoa.monthly` attached,** and promote the Android release past internal testing only after the sandbox checklist is green.

---

## 11. Phased implementation

Phases are sequential for go/no-go. Code for a later phase may be written early. A phase is not done until its exit criteria pass, including the outside-code items that phase depends on.

```text
P0 Legal shell → P1 Entitlement → P2 Server gate
  → P3 Store notifications → P4 Paywall and purchase
  → P5 Pause → P6 Sandbox proof and submit
```

### P0 — Legal shell

**Objective.** Have somewhere honest to point the stores and the paywall.

**Depends on you.** Section 10.A, after the pages exist.

#### Code

- Blade terms view at `/legal/terms`, plus export alongside the privacy HTML.
- Privacy view gains the subscriptions section. `EXPERIENCE_PRIVACY_UPDATED` moves with the copy.
- Paywall and delete-account screens link to both URLs. The delete screen states that deleting WAYOA does not cancel the Apple or Google subscription, and links to the platform’s subscription management.

**Done when.** Both URLs return 200 on `experience.synteric.co.uk` over HTTPS with no redirect, and the listing fields in the consoles point at them.

### P1 — Entitlement

**Objective.** Persist the access rule in Postgres.

**Depends on you.** Product ids from section 6 created in the consoles (10.B and 10.C) before sandbox, not before the migration.

#### Code

New domain `api/app/Domains/Billing/`.

`subscriptions` table, one current row per user plus history of store events:

| Column | Role |
|---|---|
| `user_id` | Owner. Unique among non-revoked rows together with the original transaction |
| `store` | `apple` or `google` |
| `product_id` | `wayoa.monthly` or `wayoa_monthly` |
| `original_transaction_id` | Stable id from the store. Unique |
| `state` | `pending`, `active`, `active_until_period_end`, `paused`, `expired`, `revoked` |
| `paid_until` | End of the last successful charge. This is what `can_use` reads |
| `auto_renew` | Whether the next charge is still scheduled |
| `pause_resumes_at` | Google only, nullable |
| `environment` | `sandbox` or `production` |

`subscription_events` stores the raw notification id and payload hash so a redelivered notification is a no-op.

`GET /billing/entitlement` for the signed-in user returns `can_use`, `state`, `paid_until`, `auto_renew`, `pause_resumes_at`, and `store`. Guests receive `can_use: false` and `state: inactive`. This route is not behind the product gate.

**Done when.** A feature test can set a paid period and read `can_use` true, then move `paid_until` into the past and read `can_use` false, including when `auto_renew` is still true.

### P2 — Server gate

**Objective.** Product routes refuse when no paid month covers now.

#### Code

Middleware on the product routes in [`api/routes/api.php`](../api/routes/api.php):

- discovery, all of them
- experience show, availability, offers, save, complete
- itinerary generate and replan
- bookings
- destination activate and import retry
- assistant / guide

Left open: auth, entitlement, privacy export, account deletion, legal pages, health, and the onboarding writes (traveller profile, journey create and update).

Refusal: HTTP 402, body code `subscription_required`, no provider call made before the check.

**Done when.** A guest and a signed-in user without `paid_until` in the future receive 402 on `POST /discovery/now` and do not create a recommendation set. A user inside a paid period receives the current response.

### P3 — Store notifications

**Objective.** The entitlement moves only when Apple or Google says so.

**Depends on you.** Section 10.B key and notification URL, 10.C Pub/Sub and service account, 10.D secrets and deploy.

#### Code

- `POST /api/billing/apple/notifications` verifies the JWS against Apple, maps notification types onto the states in section 6, and ignores duplicates.
- `POST /api/billing/google/notifications` verifies the Pub/Sub push token, pulls the subscription from the Play Developer API, and maps `SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED` to `active_until_period_end`, `SUBSCRIPTION_PAUSED` to `paused`, and a recovered renewal to `active` with a new `paid_until`.
- `POST /api/billing/apple/refresh` and `POST /api/billing/google/refresh`, authenticated, accept the transaction or purchase token from the device and re-read it from the store. Used at purchase time and on restore. The notification remains the source that corrects drift.
- Mapping rule for both stores: a successful charge sets `paid_until` to that transaction’s expiry and state `active`. A scheduled cancel or pause keeps `paid_until` and sets `active_until_period_end`. Pause-in-effect, expiry, refund, and revoke clear `can_use` immediately.

**Done when.** A fixture of each notification type updates state exactly once when delivered twice. A grace-style extension without a successful charge does not move `paid_until`.

### P4 — Paywall and purchase

**Objective.** The traveller can buy the month and get in only after the server agrees.

**Depends on you.** Section 10.E builds. Purchase will not succeed in Expo Go.

#### Code

- Add an Expo config plugin for StoreKit 2 and Play Billing (`expo-iap`, confirmed against the Expo SDK 57 docs before it is added). Register it in [`mobile/app.json`](../mobile/app.json).
- Paywall screen: price, “1 month”, “renews until you pause or cancel”, links to terms and privacy, Subscribe, Restore, and Sign in when there is no account.
- Purchase requires a signed-in user. The client completes the store purchase, posts the receipt to the refresh endpoint, and enters `/(tabs)` only when `can_use` is true.
- Pending purchase stays on the paywall with “Still confirming”.
- Restore posts the current store entitlement to the refresh endpoint. If that original transaction already belongs to another live user, the API returns 409 `subscription_in_use` and the screen says so.
- Foreground: refetch entitlement. A 402 from any product query redirects to the paywall.
- Web: the same screen, without a purchase button. Copy tells them to subscribe on iPhone or Android.

**Done when.** Typecheck is green, and a sandbox purchase on a dev-client or store build moves the account from the paywall to the tabs only after `paid_until` is in the future.

### P5 — Pause

**Objective.** The traveller can stop the next charge without losing the month already paid for.

**Depends on you.** Play pause enabled (10.C). Apple has nothing to enable.

#### Code

Subscription screen, reachable from You and from the paywall when a period is current:

- Shows £1.99 / month, state, and “You have WAYOA until {paid_until}”.
- If a pause or cancel is already scheduled: “After that it pauses and you won’t be charged. It stays off until a paid month is active again.”
- Android primary action opens Play subscription management, where pause lives. The app does not invent its own pause duration.
- iPhone primary action opens Apple’s subscription management (`https://apps.apple.com/account/subscriptions`) and the label is “Stop next renewal”. The body says WAYOA will not turn itself back on; using it again means buying another month.
- When a Google pause later resumes and the charge succeeds, the notification path in P3 sets a new `paid_until` and the next launch enters the app.
- When it resumes and the charge fails, state stays closed.

**Done when.** Scheduling a pause in the Play sandbox leaves `can_use` true until `paid_until`, then false. Cancelling auto-renew on iOS does the same. Neither path shortens `paid_until`.

### P6 — Sandbox proof and submit

**Objective.** Both stores demonstrate the access rule on a release build, then the listings go in.

**Depends on you.** All of section 10, then the review submission.

#### Code

No new product surface. Fix whatever the sandbox walk breaks. Review notes live in section 16 so they can be pasted as-is.

**Done when.** Section 16 is checked on TestFlight and on the Play internal track, and both submissions include the subscription.

---

## 12. Account, guest, and deletion

Onboarding may finish as a guest. The paywall asks for an account before purchase so the receipt has an owner. Existing Apple, Google, and email auth is the account. Guest data adoption on register stays as it is today.

Account deletion removes the user and unlinks the entitlement row so a later restore can attach a still-paid store subscription to a new account. The store subscription is not cancelled by deletion, because the app cannot cancel an Apple subscription, and a Play cancellation from the server would surprise someone who only meant to erase their profile. The delete screen says this and links to the store.

---

## 13. Acceptance criteria

1. `can_use` is false for a guest, a user with no row, a pending purchase, a paused subscription, an expired subscription, a revoked subscription, and a grace extension that has no successful charge.
2. `can_use` is true through `paid_until` when state is `active` or `active_until_period_end`.
3. `POST /discovery/now` returns 402 and does not call the scorer when `can_use` is false.
4. The same call succeeds when `paid_until` is in the future.
5. A repeated store notification does not move `paid_until` or insert a second event.
6. An original transaction id cannot belong to two live users.
7. The paywall shows £1.99, one month, auto-renewal, and working privacy and terms links before purchase.
8. The app enters the tabs only after the server reports `can_use`.
9. Delete account remains reachable with the paywall up, and its copy says the store subscription continues until stopped in Apple or Google.

---

## 14. Test plan

### Automated

- Feature tests for the access matrix in section 6, the 402 gate, notification idempotency, and the one-account binding.
- A grace fixture whose store expiry is later than the last successful charge, asserting `paid_until` stays on the charge.
- Mobile typecheck. Paywall links render on web without a purchase button.

### Sandbox, both stores, release build

- [ ] New account, paywall, no product data before purchase.
- [ ] Subscribe. Tabs stay closed until the charge is confirmed.
- [ ] Discover returns recommendations. Force-quit and reopen: still in.
- [ ] Schedule pause (Android) or cancel renewal (iOS). App still works. Screen shows the end date.
- [ ] Advance the sandbox renewal clock, or wait out a shortened sandbox period. App opens on the paywall. `POST /discovery/now` is 402.
- [ ] Android: let the pause elapse and the renewal succeed. App works again for the new month.
- [ ] Android: resume early. App stays closed until that charge succeeds.
- [ ] iOS: buy again. New `paid_until`. App works.
- [ ] Restore on a second install, same account: month comes back.
- [ ] Restore while that transaction is on another live account: 409, no access granted.
- [ ] Delete account from the paywall. Privacy and terms open in the browser.

---

## 15. Rollout

Ship as a store build, not a silent server switch that locks current travellers out of a binary with no paywall. Order:

1. Legal pages live.
2. Billing routes deployed with secrets set. Notifications enabled. No client gate in the wild yet.
3. TestFlight and Play internal track prove section 14.
4. The public binary and the subscription are submitted together.
5. On the day that binary is released, the API gate is already correct for old binaries too: an old build will receive 402s and look broken. Do not enable the gate in production until the store build that contains the paywall is the one users get. If a phased store rollout is in progress, keep the gate off until the paywall build is the only build, or ship the gate and the paywall in the same binary and accept that pre-paywall installs fail closed. Preferred: fail closed, and release the paywall build to 100% immediately.

Sandbox entitlements never grant production access. `environment` on the row enforces that.

---

## 16. Review notes

Paste into App Store Connect and keep the same facts in the Play review form if it asks.

```text
WAYOA is a travel guide. Use of recommendations, planning, the guide,
and booking requires a 1-month auto-renewable subscription at £1.99.

There is no free trial. Access starts only after the purchase succeeds
and continues until the end of the paid month.

Pausing or cancelling does not remove the month already paid for.
On iPhone, “Stop next renewal” opens Apple’s subscription management.
The app does not resume an Apple subscription by itself. Buying another
month starts access again.

Account deletion is available on the subscription screen and under You.
Deleting the account does not cancel the App Store subscription; the
screen links to Apple’s subscription management for that.

Privacy: <privacy url>
Terms: <terms url>
```

---

## 17. Status tracking

| Phase | Name | Status | Waiting on you |
|---|---|---|---|
| P0 | Legal shell | Not started | Publish privacy and terms; listing URLs |
| P1 | Entitlement | Not started | Console product ids before sandbox |
| P2 | Server gate | Not started | — |
| P3 | Store notifications | Not started | Apple key, Play service account, Pub/Sub, env vars |
| P4 | Paywall and purchase | Not started | EAS / TestFlight / internal track builds |
| P5 | Pause | Not started | Play pause enabled |
| P6 | Sandbox proof and submit | Not started | Section 14 walk, then submit |

Update this table as phases exit.
