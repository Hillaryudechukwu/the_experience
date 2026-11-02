# Store review walk (Gate 2)

Walk this on a **TestFlight** or **Play internal** build that includes `expo-iap`.
Paste the review notes from [`SUBSCRIPTION_JEM.md`](SUBSCRIPTION_JEM.md) §16 into
the consoles, then prove every sentence on the device.

## Before the store sheet

- [ ] Open the app as a new account (or after signing out).
- [ ] Finish free setup (destination / purpose) without being charged.
- [ ] Hit a gated surface → paywall.
- [ ] Paywall shows **£1.99**, **one month**, **renews until pause/cancel** before any purchase sheet.
- [ ] Terms of use and Privacy policy open in the browser.
- [ ] **Delete your account** is visible on the paywall.

## Identity

- [ ] Sign in with email works.
- [ ] Google sign-in works where enabled.
- [ ] On iPhone, **Continue with Apple** appears alongside Google.

## Purchase and restore

- [ ] Subscribe completes → tabs open only after the server reports `can_use`.
- [ ] Restore on a second install with the same account recovers the month.
- [ ] Restore while the transaction is on another account shows a clear conflict (409).

## Deep links and legal

- [ ] `https://experience.synteric.co.uk/privacy` opens.
- [ ] `https://experience.synteric.co.uk/legal/terms` opens.
- [ ] Experience deep link association files return 200 (see [`RELEASE.md`](RELEASE.md)).

## Owner checklist

Console listing, screenshots, and attaching `wayoa.monthly` to the binary remain
in [`PAID_LAUNCH_OWNER_RUNBOOK.md`](PAID_LAUNCH_OWNER_RUNBOOK.md) §G.
