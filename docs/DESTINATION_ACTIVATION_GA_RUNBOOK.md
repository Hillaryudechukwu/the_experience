# Destination activation GA runbook (P5)

Controlled path from dark deploy (rollout 0%) to general availability.
Does not invent a second pipeline — uses the existing activate → import →
poll flow in [`DYNAMIC_DESTINATION_RUNBOOK.md`](DYNAMIC_DESTINATION_RUNBOOK.md)
and the checklist in [`DYNAMIC_DESTINATION_DEPLOY_CHECKLIST.md`](DYNAMIC_DESTINATION_DEPLOY_CHECKLIST.md).

## Preconditions

- `GET /api/health` → `destination_pipeline.queue_worker_alive: true`,
  place provider configured, `activation_enabled: true`.
- Minute cron runs `php artisan schedule:run`.
- Google / OSM spend ceilings approved for the observation windows.
- `DESTINATION_PREWARM_ENABLED=false` until aggregate-demand retention is signed off.

## Controlled city proof (before any percentage)

1. Pick one uncovered city (e.g. Reykjavík) from search `elsewhere` results.
2. Set allowlist (name **or** candidate id):

   ```bash
   # name match (case-insensitive)
   DESTINATION_ACTIVATION_ALLOWED_CITIES=Reykjavík
   # and/or exact Nominatim candidate
   DESTINATION_ACTIVATION_ALLOWED_CANDIDATES=nominatim:relation/…
   DESTINATION_ACTIVATION_ROLLOUT_PERCENTAGE=0
   ```

3. `php artisan config:clear && php artisan config:cache`
4. Activate with a guest token → `202` + `import_id`.
5. Poll `GET /api/destination-imports/{id}` until `ready` or `limited`
   (or `failed` + retry once).
6. Confirm app reopen resumes the same `import_id` (session persistence).
7. Admin: `GET /api/admin/destination-imports`, `GET /api/admin/destination-quality`
   with a Sanctum token that has the `admin` ability.

Do **not** raise rollout until this cycle completes cleanly.

## Progressive rollout

Observe each step for ≥30 minutes. Watch activation 5xx, stuck imports,
duplicates, provider spend, and discovery latency.

| Step | `DESTINATION_ACTIVATION_ROLLOUT_PERCENTAGE` |
|---|---|
| Closed / allowlist only | `0` |
| Small cohort | `5` |
| Wider | `25` |
| GA | `100` |

After each change: config clear + cache, then record the percentage in release
notes (checklist post-deploy).

## Kill switch

```bash
DESTINATION_ACTIVATION_ENABLED=false
php artisan config:clear && php artisan config:cache
php artisan queue:restart
```

Ready / limited destinations stay usable. New activations return HTTP 503.

## Stuck import recovery

Scheduled every five minutes. Manual:

```bash
php artisan destinations:recover-stale-imports
```

Then admin or traveller retry. Exit gate: no queued/running import older than
15 minutes after recovery has run.

## P5 exit (programme)

| Gate | Evidence |
|---|---|
| One allowlisted city full cycle | this runbook § Controlled city proof |
| No import stuck > 15 minutes after recovery | recover command + admin list |
| Duplicate destination rate < 1% | admin / DB check during ladder |
| Daily provider spend within ceiling | provider consoles + activation limits |
| Rollout % in release notes | checklist post-deploy |

## Cloud spend alerts

Watch spend in Google Cloud Billing (Places) and Anthropic. For email when
remaining budget is ≤10%:

1. Cloud Console → Billing → Budgets & alerts → threshold **90%** (and optionally 80%).
2. Connect the budget to a Pub/Sub topic; push subscription to  
   `https://api.experience.synteric.co.uk/api/ops/google/budget-alerts`  
   with OIDC auth (audience = that URL, service account email in env).
3. Host env: `OPS_ALERT_EMAILS`, `GOOGLE_BUDGET_PUBSUB_AUDIENCE`,  
   `GOOGLE_BUDGET_PUBSUB_SERVICE_ACCOUNT` (or reuse `GOOGLE_PUBSUB_*`).
4. Admin readout: `GET /api/admin/budget-alerts` (Sanctum `admin` ability).

## Status

Controlled proof is GO. Production rollout is **25%** as of 2026-11-22.
Observe spend and imports before 100%.
