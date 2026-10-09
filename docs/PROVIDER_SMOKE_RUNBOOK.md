# Provider smoke runbook (P4)

Live commercial providers for Interlude. Keys never go in git. This runbook
proves production (or staging) can use them, and knows how to fall back.

## What “configured” means

| Concern | Env / config | Healthy signal |
|---|---|---|
| Place data + ratings | `GOOGLE_PLACES_API_KEY` (or OSM default) | `destination_pipeline.place_provider` + `place_provider_configured` |
| Content enricher | Wikimedia (keyless) | `content_enricher: wikimedia` |
| Native tickets | `VIATOR_API_KEY` | `providers.viator.configured: true` |
| Redirect tickets | `DEEPLINK_AFFILIATE_ID` + `DEEPLINK_BASE_URL` | `providers.deeplink.configured: true` |
| AI guide | `ANTHROPIC_API_KEY`, `EXPERIENCE_ASSISTANT_DRIVER=anthropic` | `assistant_driver: anthropic` |
| Sandbox (always) | none | `providers.sandbox.configured: true` — store demo / CI |
| Queue | durable `QUEUE_CONNECTION=database` + cron `schedule:run` | `queue_worker_alive: true` |

Fallbacks (no key): OSM place data, sandbox tickets, `rules` assistant driver.
Circuit breakers isolate a single supplier; discovery continues.

## Local / server status (no secrets)

On the API host or any environment with `.env` loaded:

```bash
cd api
php artisan experience:provider-status
# or
php artisan experience:provider-status --json
```

Exit code is `0` when P4 gates pass. On a durable queue driver that
includes a living worker heartbeat; `sync` (CI/local) skips the heartbeat
requirement. `live_commercial_provider` is reported separately — true when
Viator/deeplink (etc.) is configured, not only sandbox.

## Production health (public)

```bash
curl -sS https://api.experience.synteric.co.uk/api/health | python3 -m json.tool
```

Expect at soft-launch / commercial readiness:

- `providers.sandbox.configured: true`
- `providers.viator.configured: true` **or** `providers.deeplink.configured: true`
- `destination_pipeline.place_provider_configured: true` (Google when keyed)
- `assistant_driver: anthropic` (or `rules` if Anthropic is intentionally off)
- `queue_driver: database`
- `queue_worker_alive: true` — if false, cron/`schedule:run` is missing or stalled

## Imagery (seeded warm catalogue)

Seeds do **not** include photos. Cards show gradients until:

```bash
php artisan experience:backfill-imagery all --limit=80
```

Deploy runs a bounded pass after seed; cron runs another daily at 03:30.
Activated (non-seeded) cities get imagery during import enrich — no per-city seed.

## Smoke checklist (staging or production)

Do these with a guest token. Prefer staging; production only for read-only
steps unless you accept creating a sandbox booking.

1. **Status**
   - `php artisan experience:provider-status` → all P4 gates `[ok]`
   - Public `/api/health` matches.

2. **Place data**
   - `GET /api/destinations?q=London` returns catalogue results.
   - Open an experience with a Google-sourced rating when Google is configured;
     `dynamic.rating` is non-null and freshness names Google.

3. **Offers**
   - `GET /api/experiences/{id}/offers` for a seeded London experience with
     products (e.g. Tower of London) lists `sandbox` and, when keyed, `viator`
     or `deeplink`. Unconfigured suppliers are absent, not broken.

4. **Booking dry-run (sandbox)**
   - `POST /api/bookings` with sandbox product + `Idempotency-Key` → `confirmed`.
   - Retry same key → `replayed: true`, one booking row.

5. **Assistant grounding**
   - `POST /api/assistant/message` with a practical city question.
   - Reply cites tools; no invented price/time when tools returned none.
   - Regression suite: `php artisan test --filter=GroundingGuardTest`.

6. **Outage isolation**
   - With Viator circuit forced open in a test env, discovery still returns
     candidates. Covered by `ProviderResilienceTest` (CI).

## Key rotation

1. Create the new key in the provider console.
2. Set the new value in the host `.env` (never in the mobile bundle).
3. `php artisan config:clear && php artisan config:cache`
4. `php artisan queue:restart`
5. Re-run `experience:provider-status` and the offers smoke.
6. Revoke the old key only after health stays green for 15 minutes.

## Queue worker stale

`status: degraded` with `queue_worker_alive: false` means the minute cron is
not running `php artisan schedule:run`, or workers cannot drain `jobs`.

```cron
* * * * * cd /absolute/path/to/api && php artisan schedule:run >> /dev/null 2>&1
```

Immediate kick (does not replace cron). Clear first so a stale heartbeat
cannot look healthy; then enqueue and drain:

```bash
cd /absolute/path/to/api
php artisan cache:forget health:queue-worker-heartbeat
php artisan schedule:run
php artisan queue:work database --stop-when-empty --max-time=50 --tries=4
```

`deploy.sh` uses the same sequence after `queue:restart`.

See also [`DYNAMIC_DESTINATION_RUNBOOK.md`](DYNAMIC_DESTINATION_RUNBOOK.md).

## P4 exit criteria (programme)

| Gate | Evidence |
|---|---|
| Google configured **or** OSM accepted | `/api/health` place_provider |
| ≥1 non-sandbox fulfilment **or** sandbox labelled for review | `viator` / `deeplink` configured; sandbox always labelled in UI |
| Assistant driver documented; grounding green | this runbook + `GroundingGuardTest` |
| Provider outage isolates discovery | `ProviderResilienceTest` |
| Queue healthy for commercial ops | `queue_worker_alive: true` |

## Current production snapshot

Captured during P4 work (re-check before calling the phase GO):

- Google Places: configured (`place_provider: google_places`)
- Viator: configured
- Deeplink: not configured (acceptable while Viator is live)
- Assistant: `anthropic`
- Sandbox: configured (demo / review path)
- Queue: `database`, `queue_worker_alive: true`, health `status: ok`
