# API surface

All endpoints are under `/api` and work for a guest. The first request mints a guest
session and returns it in the `X-Guest-Token` response header; send it back on
subsequent requests. Registering later adopts everything the guest already did.

## Identity

```
POST   /auth/register
POST   /auth/login
POST   /auth/logout                       (auth:sanctum)
```

## Traveller

```
GET    /traveller/profile
PATCH  /traveller/profile
GET    /traveller/experience-dna          §2.3
```

## Journeys

```
POST   /journeys
GET    /journeys/{journey}
PATCH  /journeys/{journey}
POST   /journeys/{journey}/mission        §3.3 — returns the interpreted soft goals
POST   /journeys/{journey}/anchors        §3.4 — naive times are destination-local
DELETE /journeys/{journey}/anchors/{anchor}
```

## Destinations

```
GET    /destinations?q=&lat=&lng=
POST   /destinations/activate             signed candidate; returns 202 + import id
GET    /destination-imports/{import}      resumable, sanitized import progress
POST   /destination-imports/{import}/retry  traveller retry of a failed/retryable import
GET    /destinations/{slug}               neighbourhoods, city essentials, "don't leave without"
```

Destination search keeps the legacy `data` and `elsewhere` fields and also
returns a discriminated `results` list. An uncovered city carries a short-lived
`candidate_token`; clients submit that token to `destinations/activate` and poll
the returned import. Activation is controlled by a kill switch, deterministic
rollout percentage, optional city allowlist / candidate allowlist
(`DESTINATION_ACTIVATION_ALLOWED_CANDIDATES` as `provider:externalId`), and
actor/IP/global daily limits.

## Discovery

```
POST   /discovery/now                     §4.1  "I'm here now"
POST   /discovery/time-boxed              §4.2  requires window_minutes
POST   /discovery/mood                    §4.3
POST   /discovery/search                  §5.6  natural language; echoes how it was read
POST   /discovery/surprise-me             §14.6 constrained roulette
```

Every discovery response carries `context` (local time, location precision, weather,
next anchor, engine version), `recommendation_set_id`, `candidates_considered`, and a
`notice` when a constraint had to be loosened to find anything.

## Experiences

```
GET    /experiences/saved
GET    /experiences/{experience}          §7 — split into descriptive / dynamic,
                                         with a `sources` block naming every
                                         licence and source on the page
GET    /experiences/{experience}/availability
GET    /experiences/{experience}/offers
POST   /experiences/{experience}/save
DELETE /experiences/{experience}/save
POST   /experiences/{experience}/complete
```

## Trips and itineraries

```
POST   /trips
GET    /trips/{trip}
POST   /trips/{trip}/generate-itinerary   §8
POST   /trips/{trip}/replan               §8.3 — returns a proposal, not a change
POST   /trips/{trip}/itineraries/{itinerary}/accept
POST   /itineraries/{itinerary}/items
PATCH  /itinerary-items/{item}
DELETE /itinerary-items/{item}
```

## Bookings

```
GET    /bookings
POST   /bookings                          requires an Idempotency-Key header
GET    /bookings/{booking}                includes the full state history
POST   /bookings/{booking}/cancel
```

## Memory

```
GET    /passport
GET    /passport/journal/{experience}     owner read, includes private_note
POST   /passport/journal/{experience}
GET    /passport/recap/{journey}
```

## Assistant

```
POST   /assistant/message                 §15
GET    /assistant/conversations/{conversation}
```

## Analytics and privacy

```
POST   /events                            §20 — batch, up to 50
GET    /privacy/export
DELETE /privacy/location-history
DELETE /privacy/data
```

## Operations (auth:sanctum + admin ability)

```
GET    /admin/providers                   §22 health and capabilities
GET    /admin/destination-imports         filterable import inspection
POST   /admin/destination-imports/{id}/retry
GET    /admin/destination-quality         quality score and curation issues
GET    /admin/sync-failures
POST   /admin/sync-failures/{failure}/resolve
GET    /admin/merge-candidates
PATCH  /admin/external-entities/{entity}  correct a provider mapping
GET    /admin/recommendation-sets/{set}   explain a past ranking
```

## Health

```
GET    /health                            engine version, provider status, active drivers
```

`queue_driver` is included so deployment checks can distinguish a durable queue
from synchronous request execution.
