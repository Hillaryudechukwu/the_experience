# Deploy Checklist: Interlude dynamic destination coverage

**Date:** 2026-10-03 | **Deployer:** Unassigned

## Current decision

**Dark deployment completed; NO-GO for general availability.** Production is
healthy with rollout at 0%, prewarming disabled, and the scheduler installed.
Provider budgets and a controlled allowlisted import remain required before
enabling any traveller cohort. Operator ladder:
[`DESTINATION_ACTIVATION_GA_RUNBOOK.md`](DESTINATION_ACTIVATION_GA_RUNBOOK.md).
Stale recovery covers both queued (`queue_stalled`) and running (`worker_stalled`)
imports older than 15 minutes.

## Pre-deploy

- [x] Local API suite passes.
- [x] Mobile TypeScript typecheck passes.
- [x] Deployment script passes shell syntax validation.
- [x] Database migrations are covered by refresh-database tests.
- [x] Activation has a global kill switch, stable rollout percentage, city
  allowlist, and actor/IP/global daily limits.
- [x] Rollback preserves ready destinations and disables only new activation.
- [x] Operational recovery and retry procedures are documented.
- [ ] CI run is green for the exact release commit.
- [ ] Code review is approved.
- [ ] Production backup/restore point is confirmed.
- [x] Hosting cron is configured to run `php artisan schedule:run` every minute.
- [ ] Google Places per-activation result limit and daily spend ceiling are approved.
- [ ] Nominatim/Overpass production capacity and usage terms are approved.
- [ ] On-call owner is assigned and notified.

## Dark deploy

- [x] Deploy API and run migrations while activation is disabled.
- [x] Confirm `deploy.sh` finishes with rollout at 0% and prewarming disabled.
- [x] Confirm `GET /api/health` reports `queue_driver: database` and expected providers.
- [x] Confirm API document root is `api/public` and exposes no directory listing.
- [x] Confirm web bundle calls `https://api.experience.synteric.co.uk/api`.
- [x] Confirm scheduler launches bounded queue workers and stale-import recovery.
- [x] Search an uncovered controlled city and verify a signed candidate response.
- [ ] Add that city to `DESTINATION_ACTIVATION_ALLOWED_CITIES`.
- [ ] Activate it and verify one import moves queued → running → ready/limited.
- [ ] Verify app restart resumes the same import.
- [ ] Verify ready/limited discovery and structured rejection while importing.
- [ ] Verify `/api/admin/destination-quality` and import inspection with an admin token.
- [ ] Verify `/.well-known/assetlinks.json` after configuring the Android signing fingerprint. Privacy policy is verified.

## Progressive rollout

- [ ] Observe an allowlisted city for at least one complete import cycle.
- [ ] Raise rollout to 5%; monitor for 30 minutes.
- [ ] Raise rollout to 25%; monitor provider spend, failures, duplicates, and latency.
- [ ] Raise rollout to 100% only after the daily budget and worker capacity remain healthy.
- [ ] Keep `DESTINATION_PREWARM_ENABLED=false` until aggregate-demand retention is approved.

## Post-deploy

- [ ] Confirm import failure and readiness-conversion metrics are nominal.
- [ ] Confirm no queued/running import is older than 15 minutes.
- [ ] Confirm API error rate and latency remain within baseline.
- [ ] Update release notes with the enabled rollout percentage.
- [ ] Notify stakeholders and assign follow-up ownership for limited destinations.

## Rollback triggers

Immediately set `DESTINATION_ACTIVATION_ENABLED=false`, clear/cache config, and
restart queue workers if any of these occur:

- activation or status endpoints exceed a 2% 5xx rate for five minutes;
- any import remains queued/running for more than 15 minutes after recovery runs;
- provider authentication failures affect two consecutive controlled imports;
- provider daily spend reaches 80% of the approved ceiling unexpectedly;
- duplicate destination/import creation is observed;
- ready seeded destinations or ordinary discovery regress;
- API p95 latency doubles from the pre-release baseline for 15 minutes.

Do not roll back the schema merely to stop activation. The kill switch prevents
new work while retaining already-ready destinations and completed import history.
