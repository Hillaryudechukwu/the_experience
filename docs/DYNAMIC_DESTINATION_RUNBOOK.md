# Dynamic destination operations runbook

This runbook covers on-demand city preparation for Interlude. It assumes the
database queue and Laravel scheduler are running.

## Required production processes

Run the scheduler once per minute from the host control panel or cron:

```cron
* * * * * cd /absolute/path/to/api && php artisan schedule:run >> /dev/null 2>&1
```

The application schedule drains queued work in bounded workers and checks for
stalled imports every five minutes. `GET /api/health` must report
`queue_driver: database`. A successful deploy restarts existing workers, but it
cannot create the host-level cron entry.

## Rollout and emergency stop

- `DESTINATION_ACTIVATION_ENABLED=false` is the global kill switch. Existing
  ready destinations continue to work.
- `DESTINATION_ACTIVATION_ROLLOUT_PERCENTAGE=0..100` assigns actors to a stable
  rollout bucket.
- `DESTINATION_ACTIVATION_ALLOWED_CITIES=Reykjavík,Oslo` bypasses percentage
  rollout for controlled city validation.
- Actor, IP and global daily ceilings are configured with
  `DESTINATION_ACTIVATION_DAILY_*_LIMIT`. Set a limit to `0` to disable that
  individual ceiling.

Predictive warming is separately controlled by `DESTINATION_PREWARM_ENABLED`.
It is off by default. When enabled, the 02:30 scheduler run considers only
aggregate destination candidates over `DESTINATION_PREWARM_MINIMUM_DEMAND` and
obeys both per-run and daily limits. It stores destination-centre metadata and
counts, never raw query text, traveller identity, or traveller coordinates.

After changing environment values, run `php artisan config:clear` followed by
`php artisan config:cache`.

## Triage a stuck import

1. Check `GET /api/admin/destination-imports?status=running` using an admin
   Sanctum token.
2. Confirm the scheduler is executing with `php artisan schedule:list` and
   inspect `storage/logs/laravel.log` by `import_id`.
3. Run `php artisan destinations:recover-stale-imports` if the scheduled check
   has not run. Imports running longer than 15 minutes become failed with
   `worker_stalled`.
4. Fix the worker/provider issue, then call
   `POST /api/admin/destination-imports/{id}/retry`. Retry creates or reuses one
   active import; it does not duplicate the old row's provider data.

Content enrichment has an independent daily retry queue. Run
`php artisan destinations:retry-enrichment` manually after a Wikimedia outage;
it only fills sourced gaps on existing canonical places and does not repeat
place ingestion or overwrite editorial content.

## Provider outage or authentication failure

Disable new activations first if failures are widespread. Existing catalogue
search remains available. Inspect provider capability in `/api/health` and the
sanitized `error_code` in the admin import list. Detailed error context is
encrypted at rest and never returned by public status endpoints. Re-enable a
small rollout or city allowlist after a successful controlled import.

## Budget exhaustion

HTTP 429 with `retry_after_seconds` means an actor, IP, or global daily ceiling
was reached. Do not repeatedly retry from the client. Check the day's
`destination_activation_requested` events and completed imports before raising
the global limit. Provider-side rate limits still take precedence.

## What to monitor

- queued or running imports older than 15 minutes;
- `destination_import_failed` rate and readiness conversion;
- execution time from `started_at` to `finished_at`;
- created, matched, failed and published counters per provider;
- daily activation count versus the global ceiling;
- provider rate-limit/authentication error codes.
- `/api/admin/destination-quality` scores source, image, published-content, and
  enrichment coverage and names destinations that need operator curation.

Structured import logs always include `import_id`, `destination_id`,
`provider_key`, and `stage`. They must never contain provider credentials,
candidate tokens, complete provider responses, or precise traveller location.
