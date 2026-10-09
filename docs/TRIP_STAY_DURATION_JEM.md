# Interlude — Trip stay duration JEM

**Status:** Implemented on `cursor/trip-stay-duration`  
**Scope:** Expo onboarding + Trip tab, Journey dates, itinerary generation  
**Objective:** Plan around how long the traveller is in the city, not only free-time minutes today.

## 1. Executive summary

Interlude’s Plan surface says “Build my day” and Discover asks “How long have you got?” Those correctly capture **moment** duration (available minutes between now and the next commitment). They do **not** capture **stay** duration (arrival → departure).

The API already supports journey `starts_on` / `ends_on` and the itinerary planner loops that range. Mobile never collected or sent those fields, so generation fell back to a silent today→+2 day default. That breaks holiday trust: onboarding feels like a trip setup; Plan behaves like a same-day filler.

This JEM wires stay length as a first-class product input while keeping free-time windows for Discover.

```text
Stay (nights/days)     →  journey.starts_on / ends_on  →  multi-day itinerary
Moment (window_minutes) →  discovery time-boxed          →  what fits now
```

## 2. Problem statement

A traveller who is in Rome for four days cannot tell Interlude that. The product still builds a plan — but against an accidental three-day server default, not their stay. Pace, companions, and mission shape *what* fills a slot; they cannot replace *how many days* to fill.

## 3. Product principles

1. **Two durations, two jobs.** Stay length scopes the itinerary; free-time minutes scope Discover.
2. **Ask once, early.** Stay length is collected in onboarding with the rest of trip setup.
3. **No silent multi-day.** Generation uses journey dates; the UI names the stay.
4. **Presets first.** Most travellers choose “today / a few days / a week”; custom calendars can come later.
5. **API already ready.** Prefer wiring existing fields over new schema.
6. **Discover unchanged.** Time chips remain the moment layer.

## 4. Goals

### User goals

- State how long they are in the city during setup.
- See a plan that covers that stay (1–14 days, planner hard max).
- Still use “how long have you got?” for opportunistic discovery today.

### Product goals

- Journey create always receives `starts_on` and `ends_on` from onboarding.
- Trip CTA reflects stay length (“Build my day” vs “Build my itinerary”).
- Trip header shows the stay range.
- Regression: generating with journey dates produces one itinerary day per calendar day in range (capped at 14).

### Non-goals

- Full calendar date-picker UX (v1.1).
- Changing Discover `window_minutes` semantics.
- Multi-city trips or open-jaw travel.
- Auto-replan when stay dates change mid-trip (patch journey + rebuild is enough for v1).

## 5. User stories

- As a traveller, I want to say I am here for a weekend so the plan is not a single afternoon.
- As a traveller, I want “just today” to still produce a single day when that is my stay.
- As a traveller, I want Discover’s time chips to keep meaning free time *now*, not my whole trip.

## 6. Experience design

### Onboarding (Travel style screen)

New section **How long are you staying?** before Who is travelling:

| Preset | Calendar days | `starts_on` | `ends_on` |
|---|---|---|---|
| Just today | 1 | today (device local date) | today |
| A few days | 3 | today | today+2 |
| About a week | 7 | today | today+6 |
| Up to two weeks | 14 | today | today+13 |

Default: **A few days** (matches prior accidental server span, but now intentional and labelled).

Skip path still sends the default stay so generation is never date-less for new journeys.

### Trip tab

- Subtitle includes stay label, e.g. `Holiday · 2 adults · 3 days (9–11 Oct)`.
- Empty CTA: **Build my day** when stay is 1 day; **Build my itinerary** otherwise.
- Body copy mentions commitments across the stay when multi-day.
- Generate continues to POST `{}`; planner reads journey dates.

### Discover

Unchanged. `window_minutes` remains moment duration.

## 7. Technical work

| Layer | Change |
|---|---|
| Draft store | `stayDays: 1 \| 3 \| 7 \| 14` |
| `style.tsx` | Stay presets; pass `starts_on`/`ends_on` into `useCreateJourney` |
| `format.ts` | `isoDate`, `stayRange`, `stayLabel` helpers |
| `trip.tsx` | Stay-aware CTA and header |
| API | No schema change; optional test that journey dates drive day count |
| Docs | This JEM; README + PRODUCTION_READINESS pointer |

## 8. Acceptance criteria

1. Creating a journey from onboarding with “About a week” stores `ends_on = starts_on + 6 days`.
2. Generating an itinerary for that journey yields up to 7 day rows (planner max 14).
3. “Just today” yields a single day and CTA “Build my day”.
4. Multi-day stay shows “Build my itinerary”.
5. Discover time-boxed still requires `window_minutes` and does not use stay length.
6. Existing journeys with null dates keep current planner fallback (today→+2) until rebuilt via onboarding.

## 9. Test plan

- Feature: journey create with dates; generate-itinerary day count equals stay length.
- Manual: onboarding → Trip → build → N day headers match preset.

## 10. Rollout

Ship with soft-launch. No feature flag required — additive fields on journey create. Existing guests without dates unchanged until they start a new journey.

## 11. Status

| Item | Status |
|---|---|
| JEM | Done |
| Onboarding stay presets → `starts_on` / `ends_on` | Done |
| Trip / Ready stay-aware CTA copy | Done |
| API journey dates + generate day count tests | Done |
| Discover `window_minutes` unchanged | Done (no code change) |
