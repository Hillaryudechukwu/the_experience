# Interlude — API

Laravel 13 modular monolith on PostgreSQL. See [`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md)
for the domain map and the reasoning behind the engine.

## Setup

```bash
cp .env.example .env            # set DB_USERNAME / DB_PASSWORD
php artisan key:generate
createdb experience && createdb experience_test
php artisan migrate --seed
php artisan serve --port=8099
```

The migration that creates the geospatial indexes installs `cube`, `earthdistance`
and `pg_trgm`, so the database user needs permission to create extensions.

## Environment

| Variable | Default | Purpose |
|---|---|---|
| `EXPERIENCE_WEATHER_DRIVER` | `open_meteo` | `open_meteo` (live, keyless) or `seeded` (deterministic, offline) |
| `EXPERIENCE_ROUTING_DRIVER` | `estimator` | travel-time model; results are always labelled `estimate` |
| `EXPERIENCE_ASSISTANT_DRIVER` | `rules` | `rules` (deterministic) or `anthropic` (needs `ANTHROPIC_API_KEY`) |
| `EXPERIENCE_TICKET_PROVIDER` | `deeplink` | preferred ticket supplier |
| `VIATOR_API_KEY` | — | activates the Viator adapter when present |
| `DEEPLINK_AFFILIATE_ID` | `demo-partner` | partner id in generated redirect URLs |
| `CORS_ALLOWED_ORIGINS` | `*` | comma-separated; tighten before production |

## Tests

```bash
php artisan test
```

Tests run against `experience_test` with the seeded weather provider, so they never
touch the network and never depend on the live forecast.

## Operations

`GET /api/health` reports the engine version, which suppliers are configured and which
drivers are active. The `/api/admin/*` endpoints need a Sanctum token with the `admin`
ability:

```bash
php artisan tinker
>>> App\Models\User::find(1)->createToken('ops', ['admin'])->plainTextToken
```
