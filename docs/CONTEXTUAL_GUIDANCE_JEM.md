# Interlude — Contextual guidance JEM

**Status:** Implemented (P4b public plan links not built)  
**Scope:** Laravel API scoring and discovery, Expo Discover / Trip / Guide / account, weather advice, plan sharing, Google and Apple sign-in  
**Objective:** At any moment, tell the traveller one thing to do and one place to go, using where they are and what they have already chosen or said; show mood as a picture; say what to wear for the weather; let them share the plan; and let them create an account with Google (and Apple on iOS).

**Related docs**

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — nine score components, context engine, guide grounding
- [`API.md`](API.md) — discovery, assistant, auth
- [`ACCEPTANCE.md`](ACCEPTANCE.md) — V1 behaviour this programme must not regress
- [`RELEASE.md`](RELEASE.md) — privacy URL, deep links, store submission
- [`PRODUCTION_READINESS_JEM.md`](PRODUCTION_READINESS_JEM.md) — P2 auth and P3 share already shipped for email and single experiences
- [`TRIP_STAY_DURATION_JEM.md`](TRIP_STAY_DURATION_JEM.md) — stay length vs free-time minutes; this JEM does not change that split

---

## 1. Executive summary

Interlude already answers “what fits?” with a ranked list. It does not yet answer “go here, and do this.”

The pieces exist and are disconnected:

1. **Location and choices already reach the ranker.** `discoveryPayload()` sends coordinates, precision, destination, and journey. `ContextEngine` builds weather, the next anchor, interests, mission, and companions. Discover shows a top card.
2. **Mood is collected and then ignored.** `POST /discovery/mood` validates the key and stores it on `ScoringContext`. No component reads `$context->mood`. Imported places save `mood_affinity` as `{}`.
3. **Conversation is stored and then forgotten.** The guide persists messages and the client sends `conversation_id`, GPS, and journey. `AssistantOrchestrator::viaAnthropic()` starts the model from the current sentence only. The rules driver parses that sentence only. Later Discover calls do not see what was said.
4. **Weather is live; clothing is not.** Open-Meteo (or the seeded driver) returns condition, temperature, rain probability, and an hourly series. The header shows degrees and, when conditions are poor, says indoor options moved up. `what_to_wear` is a per-place dress code (“shoulders covered”), not a forecast.
5. **One experience can be shared. A plan cannot.** `share.ts` builds `/experience/{id}`. The Trip tab can build and replan. It has no share action. Deep-link association files claim only `/experience/*`.
6. **Sign-in is email and password.** Sanctum issues a `traveller` token. Guest data is adopted on register and login. There is no OAuth client, `users.password` is required, and account deletion re-asks for that password because a stolen phone must not be enough.

This JEM connects those pieces in six phases. It does not add a tenth scoring weight, invent a second recommendation engine, or replace email login.

```text
P0 mood actually ranks → P1 one place to go, from location + choices + chat
  → P2 what to wear → P3 mood photographs
  → P4 share the plan → P5 Google sign-in (Apple on iOS)
```

P2, P3, and P4 may be built beside P0–P1. P5 is a separate identity slice and must not block the travel work. P0 is the gate for P1: a “closest to your mood” headline over an unchanged ranking is a false answer.

---

## 2. Problem statement

A traveller standing in a city, after saying they are hungry, or after telling the guide they want something quiet and free, still sees a generic top pick. The product knows their coordinate, their journey, and the sentence they just sent, and then answers with a list that did not use the mood or the earlier turn.

Mood tiles look like filters and behave like labels. Weather tells them the temperature and not what to put on. A plan they built cannot be handed to the person they are with. Creating an account still means inventing a password, which many travellers will not do, so the journey stays on one device.

If these are built as five unrelated features, mood pictures will ship on a ranker that ignores mood, and Google sign-in will ship on a deletion screen that demands a password the account does not have.

---

## 3. Product principles

1. **One next action, then alternatives.** The home answer names one place and one thing to do. The rail underneath remains a list.
2. **Choices outrank silence.** An explicit mood, time chip, or guide constraint changes the next ranking. Absence of a mood leaves today’s nine-component score unchanged.
3. **Remember structure, not a transcript, for ranking.** The ranker reads a short-lived intent (mood, window, categories, exclusions). The model may read recent turns. Neither path stores a location trail.
4. **No tenth scoring weight.** Weights must sum to 1.0 and a neutral component’s weight is redistributed. A permanent `mood_fit` weight would shrink journey purpose even when no mood is selected. Mood is a bounded re-rank after the nine components, in the same spirit as `NameRelevance`.
5. **Missing mood affinity is neutral, not a penalty.** An empty `mood_affinity` must not bury a place. Derived affinity from place kind gives imported cities a real signal. Seeded editorial scores stay as written.
6. **Two wear lines, two jobs.** The header says what the weather calls for. The experience page says what that place requires. They are never merged into one sentence.
7. **Share the plan, not the person.** A shared plan lists times, titles, and experience links. It omits journal notes, precise coordinates, and tokens.
8. **Guest-first, then any sign-in.** Google and Apple run the same guest adoption as email register and login. Email and password stay.
9. **The phone is not proof of identity.** Deletion of a password account still requires the password. Deletion of a provider-only account requires a fresh token from that provider.
10. **Grounded copy only.** The “what to do” sentence is composed from a stored reason and the place name. The guide still passes `GroundingGuard`. Clothing advice is a function of the forecast, not a model invention.

---

## 4. Goals

### User goals

- Open Discover and see one thing to do and one place to visit, based on where they are right now.
- Have a mood they tapped, a time they chose, or a constraint they told the guide still apply on the next Discover refresh until the local day ends or they change it.
- See each mood as a picture of that mood, and have that tap change which place is on top.
- See what to wear for the conditions they are actually in.
- Send today’s plan to someone else.
- Create or enter an account with Google, and with Apple on iOS, without losing the guest journey.

### Product goals

- `mood` changes rank order on seeded and imported catalogues.
- Discovery responses include a `primary` action with a place and a directions URL.
- A live intent row is the only memory Discover needs from chat.
- Weather payloads include a clothing line. `what_to_wear` is unchanged.
- Trip has a share action. A public plan link is specified and can follow in the same phase if the share sheet is not enough.
- `POST /auth/google` and `POST /auth/apple` return the existing Sanctum traveller token.

### Initial success targets

| Check | Target |
|---|---|
| Mood re-rank on a fixture catalogue | The highest-affinity place for that mood outranks a high-quality place with affinity under 30, when time, weather, and distance are otherwise equal |
| No-mood ranking | Identical to `score-v1.3` on the existing score fixtures |
| Primary action present | 100% of non-empty `POST /discovery/now` responses |
| Intent expiry | A mood set yesterday in destination-local time does not apply today |
| Clothing present | Every discovery `context.weather` object includes `clothing` when a forecast exists |
| Clothing absent | No clothing object, and no invented garments, when weather is null |
| Plan share | Share sheet contains every current itinerary item title and no journal field |
| OAuth adoption | Guest journey, saves, and profile attach exactly as they do for `POST /auth/register` |
| OAuth deletion | A Google-only account cannot be deleted with a made-up password or with the bearer token alone |
| Suite | API tests and mobile typecheck green on the phase’s exit commit |

---

## 5. Non-goals

- A tenth score component, or retuning the nine spec weights, in this programme.
- Replacing the itinerary planner with a chat-generated day.
- Continuous background location, a location history, or finer than the existing rounded context snapshot.
- Using an experience photograph as the mood tile. A picture of a museum is not a picture of “hungry”.
- AI-written clothing advice or AI-written “what to do” copy.
- Rewriting venue `what_to_wear` seed copy.
- Public social features: follows, feeds, comments, collaborative editing of a shared plan.
- Removing email and password.
- Sign in with Apple on Android or web. Apple is required on iOS because Google is offered there.
- A password-reset flow. Out of scope unless a later JEM takes it.
- Changing bundle id `uk.co.synteric.experience` or the production web origin.

---

## 6. Personas and user stories

### Traveller in the city

- As a traveller, I want the home screen to tell me what to do and where to go from where I am standing.
- As a traveller, I want that answer to respect the mood or time I just chose.
- As a traveller, I want something I told the guide (“somewhere free”, “not a museum”) to still be true when I go back to Discover.
- As a traveller, I want to clear that and get the ordinary ranking again.
- As a traveller who declined location, I want the same answer from the city I selected, labelled as city-level rather than “near you”.

### Traveller choosing by mood

- As a traveller, I want each mood to look like that mood, not like a coloured button.
- As a traveller, I want “Hungry” to put food above a famous museum.
- As a traveller in a city that was imported rather than seeded, I want mood to still do something.

### Traveller in the weather

- As a traveller, I want a short clothing suggestion for the temperature and rain I am walking into.
- As a traveller opening a church or a viewpoint, I still want the place’s own rule (covered shoulders, wind-proof layer) on that page.

### Traveller with a plan

- As a traveller, I want to send today’s plan to the person I am with.
- As a recipient, I want to open each place. I do not want their private notes or their live location.

### Traveller creating an account

- As a traveller, I want to use Google instead of inventing a password.
- As an iPhone traveller, I want Sign in with Apple beside Google.
- As a guest who already built a trip, I want that trip on the account I just created.
- As the owner of a Google-only account, I want deletion to require Google again, not a password I was never given.

### Store reviewer

- As a reviewer, I want Google and Apple to be real sign-in buttons, account deletion to work for those accounts, and the privacy policy to name those providers.

---

## 7. Journey experience map

| Stage | Traveller action | Interlude response | System work | Failure recovery |
|---|---|---|---|---|
| Open Discover | Lands on Home | One sentence: what to do, where, how long to walk. Rail of alternatives underneath | `POST /discovery/now` with coords or city centre. Rank, then mood blend if an intent exists. Compose `primary` from the top result | If the catalogue is empty, keep today’s empty state. Do not invent a place |
| Choose a mood | Taps a pictured tile | Heading becomes “Closest to your mood”. Top card changes | Upsert live intent. Re-rank with mood blend | Toggle off clears the mood and refetches unblended |
| Choose a time | Taps a time chip | “Best use of {window}” as today | Intent stores `window_minutes`. Existing time-boxed endpoint | Clearing the chip clears the window |
| Talk to the guide | Sends a constraint | Reply plus place chips, as today. Next Discover agrees | Parse intent, upsert live intent, and on Anthropic replay recent turns | If the model fails, rules driver still writes the intent |
| Check the sky | Glances at the header | Temperature, condition, and a clothing line | `ClothingAdvisor` on the snapshot already fetched | If weather is null, omit the line. Do not guess |
| Open a place | Reads the page | Place dress code stays under its own heading | Existing `what_to_wear` | No change |
| Share the plan | Taps Share my plan | System share sheet with the day | Format itinerary on device. Optional token link in P4b | If there is no itinerary, the button is absent |
| Create account | Taps Google or Apple | Same signed-in You screen as email | Verify token, find or create, adopt guest, Sanctum token | Provider cancel returns to the form. No partial user |

---

## 8. Current baseline (evidence)

| Capability | Status | Evidence |
|---|---|---|
| Live location on discovery | Present | `mobile/src/store/session.ts` `discoveryPayload()`, 30-minute coordinate TTL, 2-minute refresh floor |
| City fallback | Present | `ContextEngine` uses destination centre when lat/lng are absent; precision `city` |
| Journey, interests, anchors, weather in the score | Present | `ContextEngine::build()`, nine components in `ExperienceScorer` |
| Mood request | Present, inert | `DiscoveryController::mood()` validates `config('experience.moods')`. `ScoringContext::$mood` is stored. Grep shows no scorer reads it |
| Mood UI | Present, no photograph | `mobile/src/components/MoodTile.tsx` gradient plus icon. `energetic` is in API config and absent from `MOODS` |
| Mood data on seeds | Present | City seeders set `mood`. `ExperienceDraftFactory` writes `'mood_affinity' => []` |
| Guide memory | Stored, unused for the next turn | `assistant_messages` rows. `viaAnthropic()` sets `$messages = [['role' => 'user', 'content' => $message]]`. `viaRules()` calls `IntentParser` on that string only |
| Guide place chips | Present | `mobile/app/guide.tsx` pushes `/experience/{id}` |
| Weather | Present | `OpenMeteoWeatherProvider` or `SeededWeatherProvider`. Home shows temperature, condition, and a poor-weather note |
| Place dress code | Present | `experiences.what_to_wear`, rendered on `mobile/app/experience/[id].tsx` |
| Share one experience | Present | `mobile/src/lib/share.ts`, analytics type `share` |
| Share a plan | Absent | `mobile/app/(tabs)/trip.tsx` actions are Rebuild and replan |
| Public routes | `/experience/*` only | `config/experience.php` `app_links.paths` |
| Email auth and guest adoption | Present | `AuthController::register`, `login`, `adoptGuestData` |
| OAuth | Absent | `composer.json` has Sanctum, not Socialite. `users.password` is non-null. Mobile deps have no auth-session package |
| Account deletion | Password or guest | `mobile/app/account/delete.tsx` and `AuthController::destroyAccount` |

**Weight constraint.** `experience.scoring.weights` sums to 1.0 and `ExperienceServiceProvider` throws at boot if it does not. `ExperienceScoreTest` locks that. `NameRelevance` is deliberately not a tenth weight. This JEM follows that decision.

---

## 9. Phased implementation

Phases P0 then P1 are sequential. P2, P3, and P4 can proceed once P0’s affinity data exists for P3’s meaning, but P3’s pictures may land before the blend if the tiles do not claim a new ranking. P5 is independent and last in the traveller-facing order only because it needs store credentials and a privacy-policy revision.

A phase is done when its exit criteria pass.

```text
P0 MoodBlend → P1 NextAction → P2 Clothing → P3 MoodPictures → P4 SharePlan → P5 OAuth
```

---

### P0 — Mood changes the ranking

**Objective.** Tapping a mood moves the top result. No mood leaves `score-v1.3` order intact.

**Done when.** A fixture proves the blend, imported drafts have a derived affinity, and Discover’s mood surface refetches a different top card on a seeded city.

#### Technical work

| Area | Detail |
|---|---|
| Blend | New `App\Domains\Recommendations\Scoring\MoodBlend`. After `ExperienceScorer::score()`, if `$context->mood` is null, return the score unchanged |
| Formula | `adjusted = round(score * (1 - blend) + affinity * 100 * blend)` with `blend` default `0.22` in `config/experience.php` under `scoring.mood_blend` |
| Affinity | `mood_affinity[mood] / 100` when the key exists. If the key is missing, affinity is `0.50` (neutral prior, not zero) |
| `different` | Does not use a single key. `0.6 * (uniqueness / 100) + 0.4 * (1 - tourist_concentration / 100)` |
| Reason | Attach one positive or negative reason: “Matches {mood}” or “Not really a {mood} fit”. Do not attach a reason when affinity is the neutral prior |
| Clamp | 0–100. Hard filters in `CandidateBuilder` still run first. Blend cannot resurrect a closed or inaccessible place |
| Engine | Bump `engine_version` to `score-v1.4` so stored recommendation sets name the new behaviour |
| Drafts | `ExperienceDraftFactory::MOOD_AFFINITY` by kind, same style as `AFFINITY`. New imports get it. Do not rewrite published seeded rows |
| Backfill | One artisan command, idempotent, fills `mood_affinity` only where the column is `{}` and `data_source` is derived. Seeded editorial JSON is left alone |
| UI keys | Add `energetic` to `MOODS` in `MoodTile.tsx` so the API list and the tiles match. Picture comes in P3; until then the existing gradient treatment is acceptable for that one tile |

Derived affinity by kind (0–100, only the moods that kind actually serves):

| Kind | adventurous | relaxed | romantic | curious | hungry | social | inspired | energetic | different |
|---|---|---|---|---|---|---|---|---|---|
| museum | | 40 | | 90 | | | 80 | | |
| gallery | | 55 | 40 | 80 | | | 88 | | 40 |
| historic | | | | 85 | | | 70 | | |
| viewpoint | 50 | 60 | 80 | | | | 75 | 40 | |
| park | 60 | 85 | 70 | | | 50 | | 70 | |
| market | | | | 60 | 90 | 85 | | 50 | 40 |
| food | | 40 | 50 | | 95 | 70 | | | |
| nightlife | 40 | | 40 | | 50 | 90 | | 70 | 50 |
| zoo / theme_park | 80 | | | 50 | | 70 | | 85 | |
| worship | | 60 | | 70 | | | 75 | | |

Empty cells stay absent so the neutral prior applies. `different` on the blend uses the uniqueness formula, not this column.

#### Tests

- Unit: blend math, neutral prior, `different`, null mood returns the input score byte-for-byte on contributions.
- Unit: weights still sum to 1.0. No new key in `scoring.weights`.
- Feature: two experiences, equal base score, mood affinities 90 vs 20, `POST /discovery/mood` returns the 90 first.
- Feature: `POST /discovery/now` with no mood matches the pre-blend order on the same fixture.
- Unit: `ExperienceDraftFactory` for `food` includes `hungry` ≥ 90.

#### Exit criteria

| Gate | Required |
|---|---|
| Mood fixture order | GO |
| No-mood fixture unchanged | GO |
| Weights still sum to 1.0 | GO |
| Derived affinity on new drafts | GO |

**Rollback.** Set `scoring.mood_blend` to `0`. Ranking returns to the nine components without a deploy of old code, as long as the blend reads that config.

---

### P1 — One thing to do, and a place to go

**Objective.** Home, at any moment, names what to do and where to go, using the live coordinate and the latest unexpired choice or guide constraint.

**Done when.** A non-empty Discover response has `primary`, the hero uses it, and a guide constraint changes the next Discover call without the traveller retyping it.

#### Live intent

One row per actor and journey. Guests use `guest_session_id`. Registered travellers use `user_id`. `adoptGuestData()` moves the row the same way it moves journeys and saves.

| Column | Type | Purpose |
|---|---|---|
| `id` | uuid | |
| `user_id` | nullable fk | Registered owner |
| `guest_session_id` | nullable fk | Guest owner |
| `journey_id` | nullable uuid | This trip. Null only before a journey exists |
| `mood` | nullable string | One of `experience.moods` |
| `window_minutes` | nullable int | Moment duration. Not stay length |
| `categories` | jsonb, default `[]` | Asked-for categories |
| `exclude_categories` | jsonb, default `[]` | Ruled out |
| `free_only` | boolean, default false | |
| `source` | string | `discover`, `assistant` |
| `expires_at` | timestamptz | End of the destination-local civil day |
| timestamps | | |

Unique on the owner columns plus `journey_id` (nullable-safe unique index per database). Upsert. A newer explicit field replaces that field. Sending mood `null` from a toggle-off clears mood. Other fields survive until expiry or an explicit clear.

`ContextEngine` applies a non-expired intent only for fields the request did not set. Request wins over memory. Expired rows are ignored and deleted on read.

Do not put coordinates on this row. Location stays on the request and on the existing rounded `journey_context_snapshots`.

#### Primary action

Add `primary` to discovery JSON when `data` is non-empty. Shape:

```json
{
  "experience_id": "uuid",
  "title": "Regent's Canal",
  "action": "Walk the towpath. The weather suits it.",
  "place_name": "Regent's Canal",
  "neighbourhood": "Angel",
  "travel_minutes": 12,
  "directions_url": "https://www.google.com/maps/dir/?api=1&destination=51.53,-0.10",
  "why": "Weather suits it"
}
```

Composition rules, deterministic, in `ExperiencePresenter` or a small `PrimaryActionComposer`:

- `title` and place fields come from the top scored card. `directions_url` uses the same lat/lng format as the experience detail presenter.
- `why` is the first positive reason already on that score. If there is none, `why` is null and `action` is “Go to {title}.”
- `action` is “{imperative from the top category or kind}. {why}” trimmed to one or two sentences. No model call. No number that is not already on the card (travel minutes may appear in the hero separately, not inside free text the grounding guard would have to police).
- `travel_minutes` is null when routing did not return one. The hero then omits “N minutes away” rather than estimating in the sentence.

`location_precision` stays on `context`. The hero uses “near you” only when precision is `precise` or `approximate`. For `city`, it says “in {city}”.

#### Home

In `mobile/app/(tabs)/index.tsx`, when `primary` exists, `heroDetail` becomes the action plus place and travel time. The top `ExperienceCard` remains the same experience, so the sentence and the card cannot disagree. “For you” clears mood and window and deletes those intent fields.

#### Guide memory

| Driver | Change |
|---|---|
| Rules | After `IntentParser`, upsert live intent from the parse (`mood`, `window_minutes`, category filters, `free_only`). Current message wins over stored fields it actually set |
| Anthropic | Before the tool loop, load the last 8 messages of this conversation into `$messages`, excluding the current user message which is then appended. Still run tools only through `AssistantToolbox`. Still run `GroundingGuard` |
| Both | Recommend calls already merge request context. They must also see the intent, which `ContextEngine` applies, so the guide and Discover share one memory |

The guide does not need a new endpoint. Discover’s next fetch is the proof.

Clearing: a guide phrase the parser already treats as a new mood replaces the mood. Add an explicit clear when the message contains “never mind”, “anything”, or “surprise me” without a mood: clear mood and exclusions. Keep this list in `IntentParser`, tested.

#### API documentation

Update [`API.md`](API.md) discovery section with `primary` and the intent rule: clients do not send the intent back; the server remembers it until local midnight.

#### Tests

- Feature: mood tap path (`POST /discovery/mood`) then `POST /discovery/now` with no mood still ranks as that mood until `expires_at`.
- Feature: a now-request that sends a different mood replaces it.
- Feature: expired intent is ignored.
- Feature: assistant message “somewhere to eat” then discovery-now puts a high-`hungry` place first, using the rules driver.
- Feature: anthropic message builder receives prior user text (fake HTTP). Grounding guard tests stay green.
- Feature: empty catalogue has `primary: null`.
- Feature: guest register still adopts the intent row.

#### Exit criteria

| Gate | Required |
|---|---|
| Hero names the same place as the top card | GO |
| Guide constraint survives into the next now-call | GO |
| Expired intent does not | GO |
| No new coordinate column | GO |
| No-mood, no-intent order still matches P0 | GO |

**Rollback.** Stop reading the intent table in `ContextEngine` (config `scoring.use_live_intent`, default true). `primary` can remain; it is the top card in sentence form. Set the flag false to restore “list only” behaviour.

---

### P2 — What to wear

**Objective.** The header and the guide say what to wear for this forecast. The place page still says what that place requires.

**Done when.** A rainy 6°C context includes a waterproof line, a dry 21°C context does not, and a null forecast adds no clothing key.

#### Advisor

`App\Domains\Discovery\Services\ClothingAdvisor`. Pure. Input is `WeatherSnapshot`. Output:

```json
{
  "headline": "Take a waterproof layer. It is cold and wet.",
  "items": ["waterproof jacket", "warm layer", "closed shoes"]
}
```

Bands, first match wins, then rain can add a waterproof if it is not already there:

| Condition | Headline intent | Items |
|---|---|---|
| `snow`, `sleet`, or temperature under 5°C | Cold. Cover up | warm coat, hat, closed shoes, waterproof if precipitation probability ≥ 40 or condition is rain/snow/sleet |
| temperature 5–12°C | Cool. A real layer | jacket, layer you can take off indoors, closed shoes |
| temperature 12–18°C | Mild. Layers | light jacket, comfortable walking shoes |
| temperature 18–25°C and not poor | Warm | light clothes, a layer for the evening |
| temperature over 25°C and not poor | Hot | breathable clothes, sun protection, water |
| poor conditions at any milder temperature (`rain`, `heavy_rain`, `storm`) | Wet | waterproof jacket, shoes that can get wet, the layer the temperature band already chose |

Precipitation probability ≥ 50 at the current hour, or in any hourly point inside the next 3 hours, adds “waterproof jacket” and a short clause on the headline even when the sky is clear now.

No wind in v1. `OpenMeteoWeatherProvider` does not request wind. Do not mention wind. A later change may add `wind_speed_10m` and a “wind-proof layer” clause above 30 km/h. Until that field exists, the advisor must not refer to wind.

Attach as `clothing` inside `WeatherSnapshot::toArray()` so discovery context, the guide’s `get_weather` tool, and snapshots all carry it. `composeWeather()` appends the headline after the existing indoor/outdoor sentence.

#### UI

`DestinationHero` gains an optional clothing line under the temperature. The poor-weather note stays; it talks about indoor ranking, not garments. Do not duplicate the headline in both places. The note remains the ranking explanation. The clothing line is the only garment copy on Home.

Experience page: keep `descriptive.what_to_wear`. Label that block **At this place**. Do not prefix it with the forecast.

#### Copy constraints

Headlines are the table above, filled with the condition word only when the existing forecast condition is interpolated the way `composeWeather` already does. No temperatures in the headline beyond what the header already shows as a number. This keeps clothing sentences free of new ungrounded numerals.

#### Tests

- Unit: each band, rain-add-on, null snapshot returns null.
- Feature: discovery context includes `clothing` when the seeded weather provider returns rain.
- Feature: assistant weather intent text contains the headline under the rules driver.
- Snapshot: `what_to_wear` on a seeded experience is unchanged.

#### Exit criteria

| Gate | Required |
|---|---|
| Band tests | GO |
| Header shows clothing only when weather exists | GO |
| Place dress code still on the experience page | GO |
| Guide weather reply includes the headline | GO |

**Rollback.** Omit `clothing` from the presenter with config `weather.clothing_enabled` default true. UI hides the line when the key is absent.

---

### P3 — Mood photographs

**Objective.** Every mood tile depicts that mood.

**Done when.** All nine moods, including `energetic`, render a local image, a label, and a selection state, and the section credits the pictures.

#### Assets

Ship files in `mobile/assets/moods/`:

`adventurous`, `relaxed`, `romantic`, `curious`, `hungry`, `social`, `inspired`, `energetic`, `different`.

Each file is a photograph or illustration we have the right to ship. It depicts the mood (a meal, a quiet park, a night out), not a specific Interlude listing. Do not bind the tile to `image_url` of the top experience. That picture is of a place, and it changes per city.

`MoodTile` draws the image full-bleed, a dark scrim so the white label stays readable, the existing icon may go. The check badge stays.

#### Credit

The `Photo` component’s rule is that a licensed image is not shown without credit. A 96px tile cannot carry a readable credit line. Put one line under the horizontal scroller: “Mood photographs: {holder}, {licence}.” If the images are original to Interlude, the line is “Mood photographs: Interlude.” The line is always present. Per-image creator, licence, and source URL live in `mobile/assets/moods/credits.json` and the section uses it.

#### Behaviour

Unchanged from P0. Tap still sets mood and refetches. Selected border and check stay. `energetic` is included here even if P0 already added a gradient tile.

#### Tests

- Typecheck.
- Browser or component check: nine tiles, each with an image role or accessible label `{Mood}, picture`.
- Missing file fails the bundle, not a blank tile in production. Import the assets statically.

#### Exit criteria

| Gate | Required |
|---|---|
| Nine tiles, nine images | GO |
| Credit line visible | GO |
| Selection still toggles mood | GO |

**Rollback.** Revert `MoodTile` to the gradient. No API change.

---

### P4 — Share my plan

**Objective.** The traveller can hand the current plan to someone else. The someone else does not receive the account.

**Done when.** With an itinerary on screen, Share my plan opens the system sheet with every item’s time and title and an experience link where an experience id exists. Anchors without an experience are included as time and title only.

#### P4a — on-device share (required)

| Area | Detail |
|---|---|
| Formatter | `shareItinerary()` in `mobile/src/lib/share.ts`, beside `shareExperience()` |
| Input | City name, timezone, itinerary days and items already on the Trip screen |
| Message | First line: `{city} plan`. Then each day label, then `{HH:mm} {title}` and, when `experience.id` exists, the existing `experienceShareUrl` |
| Exclusions | No `private_note`, no lat/lng of the traveller, no guest token, no auth token |
| UI | Trip header action `Share my plan` when `itinerary` is non-null. Rebuild stays |
| Analytics | `recordEvents` with type `share` and `surface: trip` |

iOS uses `Share.share({ message })` including the links in the message, because a plan has many URLs and the single-`url` field is for one link. Android uses the same message. This differs from `shareExperience`, which passes `url` on iOS. Document that in a comment so a later edit does not “fix” it back to one URL.

#### P4b — revocable public link (same phase, ship after P4a)

Only if a recipient must open the plan as a page rather than a message. Do not block P4a on it.

| Piece | Detail |
|---|---|
| Table `shared_plans` | `id` uuid, `token` 32+ bytes random unique, `trip_id`, `itinerary_id`, `user_id` or `guest_session_id`, `revoked_at` nullable, timestamps |
| Create | `POST /trips/{trip}/share` owned by the actor. Returns `{ url, token }` |
| Read | `GET /plans/{token}` public, no actor, returns the plan snapshot taken at share time (titles, times, experience ids, city). Not a live query of later edits |
| Revoke | `DELETE /trips/{trip}/shares/{id}` sets `revoked_at`. Public read then 404 |
| Web | A minimal Blade page, or the app route if and only after `app_links.paths` gains `/plans/*` and both association files are regenerated. Until that ships, the HTTPS page is the browser, which is the same rule `RELEASE.md` states for routes the app cannot open |
| Privacy | Snapshot omits notes and traveller coordinates. Rate-limit the public read |

#### Tests

- Unit: formatter includes titles and experience URLs and throws nothing on an anchor without an experience.
- Manual: Trip → Share → message readable in the sheet.
- Feature, P4b only: create, public read, revoke, second read 404, other actor cannot revoke.

#### Exit criteria

| Gate | Required |
|---|---|
| P4a share sheet from a built plan | GO |
| No private fields in the message | GO |
| Button hidden when there is no itinerary | GO |
| P4b | Optional. If started, revoke and ownership tests are GO before release |

**Rollback.** Remove the button. P4b tokens can be revoked in bulk by setting `revoked_at`.

---

### P5 — Google sign-up, and Apple on iOS

**Objective.** A traveller can create or enter an account with Google. On iOS, Apple is beside it. Guest work is adopted. Deletion still proves identity. Email login still works.

**Done when.** A verified Google token returns a Sanctum `traveller` token, a second call with the same subject returns the same user, a matching verified email links to an existing password user, an unverified email does not, and deleting a provider-only account requires a fresh provider token.

#### Why the server sees a token, not a redirect

The client is Expo. The API is the resource server. The app obtains an ID token and `POST`s it. The API verifies signature, issuer, audience, and expiry, then issues Sanctum as it does today. Do not add a browser OAuth session on the API as the only path. Laravel Socialite may be used if it verifies the token; a small JWT check against Google’s and Apple’s published keys is enough and avoids a new session stack. Prefer one verifier class per provider over a half-configured Socialite redirect.

#### Data

`users.password` becomes nullable.

`social_identities`:

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint | |
| `user_id` | fk cascade | |
| `provider` | string | `google` or `apple` |
| `provider_user_id` | string | `sub` |
| `email` | nullable string | Last email the provider asserted |
| timestamps | | |

Unique `(provider, provider_user_id)`.

`User` fillable includes nothing from the client except name and email set by the controller after verification. Password cast stays `hashed`. Null password must not become the string `"hashed"` of null; the cast runs only when the attribute is set. Create provider users without a password attribute.

#### Endpoints

```text
POST /auth/google     { id_token }
POST /auth/apple      { id_token, name? }
```

`name` on Apple is accepted only on first create, because Apple sends the name once. Store it then. Later logins with a null name do not wipe it.

Response, both, identical to login:

```json
{ "token": "...", "user": { "id": 1, "name": "...", "email": "..." } }
```

Status 200 for an existing user, 201 for a create. Both call `adoptGuestData`.

#### Verification

| Check | Google | Apple |
|---|---|---|
| Issuer | `https://accounts.google.com` or `accounts.google.com` | `https://appleid.apple.com` |
| Audience | Every id in `GOOGLE_CLIENT_IDS` (iOS, Android, web), comma-separated | `APPLE_CLIENT_ID` (the bundle id, and the Services ID if web is used) |
| Signature | Google certs | Apple JWKS |
| Email | Require `email_verified` true to trust the email | Apple’s private relay is a valid email. Treat it as verified when Apple says so |
| Subject | Stable `sub` | Stable `sub`. Never key users on email alone |

Lookup order:

1. Identity row for `(provider, sub)` → that user.
2. Else, if email is present and verified, and a user exists with that email case-insensitively → attach a new identity row to that user. Do not create a second user.
3. Else create a user with verified email, name, null password, and the identity row.
4. If email is missing or not verified and step 1 missed → 422. Do not create an account we cannot tell apart from another person.

Clock skew tolerance 60 seconds. Reject expired tokens. Log provider and `sub` hash on failure, not the raw token.

#### Deletion

`destroyAccount` today requires `password` whenever `userId` is non-null.

Change:

- If the user has a non-null password, keep the password check exactly.
- If the password is null, require `id_token` plus `provider`. Verify it, and require the `sub` to match an identity on that user. Then run `DeleteTravellerAccount`.
- A bearer token alone remains insufficient in both cases.
- Guest deletion is unchanged.

`mobile/app/account/delete.tsx`: password field for password users. For provider-only users, a button “Confirm with Google” or “Confirm with Apple” that fetches a fresh token and posts it. The screen can know which from a `providers` array on `GET /traveller/profile` or the auth user payload. Add `providers: string[]` to the user object returned by login, register, and profile. Empty array means password account.

#### Client

Dependencies: `expo-auth-session`, `expo-web-browser`, `expo-crypto`. Apple: `expo-apple-authentication`, which needs a dev client or a store build. It does not run in Expo Go. Document that in the phase notes so nobody tests Apple only inside Expo Go and calls it broken.

Buttons on `mobile/app/account/login.tsx`, `register.tsx`, and the guest card in `mobile/app/(tabs)/you.tsx`. Order on iOS: Apple, then Google, then the email form. On Android and web: Google, then the email form. No Apple button off iOS.

Cancel from the provider sheet returns to the form with no error alert.

Config, public on the client only as client ids: `EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID`, `EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID`, `EXPO_PUBLIC_GOOGLE_ANDROID_CLIENT_ID`. The API holds the same ids as audiences. No client secret in the app.

#### Privacy and store

- Privacy policy names Google and Apple as identity providers, what is received (name, email, subject), and that a token is verified then discarded.
- Bump the policy updated date via the existing `EXPERIENCE_PRIVACY_UPDATED` path in [`RELEASE.md`](RELEASE.md), re-export, and publish.
- App Store guideline 4.8: Google on iOS ships only together with Sign in with Apple. The iOS binary must not contain a Google button without an Apple button.
- Play Console: SHA-1 of the upload key registered on the Google Android client.

#### Tests

- Feature: valid Google token creates a user, second call returns the same id, guest journey is adopted. Use a fake verifier in tests, not the network.
- Feature: verified email collision links, does not duplicate.
- Feature: `email_verified` false returns 422 and creates no user.
- Feature: wrong audience returns 422.
- Feature: password user can still log in and still must pass the password to delete.
- Feature: null-password user deletes with a matching fresh token and 422s with a password or a token for a different `sub`.
- Feature: existing `AuthEmailNormalizationTest` stays green.

#### Exit criteria

| Gate | Required |
|---|---|
| Create, repeat login, email link, unverified reject | GO |
| Guest adoption | GO |
| Deletion proof for both account kinds | GO |
| Email login unchanged | GO |
| iOS build shows Apple and Google together | GO before any TestFlight that includes Google |
| Privacy policy updated and exported | GO |

**Rollback.** Leave the routes in place but hide the buttons with `EXPO_PUBLIC_GOOGLE_AUTH_ENABLED`. Existing identity rows remain. Password users are unaffected. Do not drop `social_identities` in the rollback; a later re-enable should still find those users.

---

## 10. Experience states and copy

### Home hero

| State | Detail line |
|---|---|
| Loading | “Working out what fits…” (unchanged) |
| Primary, precise location | “{action} {place}, {n} min away.” |
| Primary, city precision | “{action} In {city}.” |
| Empty catalogue | Existing empty copy. No invented place |
| Intent active | Section title stays “Closest to your mood” or “Best use of {window}”. A text button “Clear” next to it resets the intent fields |

Do not say “near you” on a city-centre coordinate.

### Clothing

Shown only with a forecast. One line, the advisor headline. The poor-weather note remains the sentence about indoor options.

### Place page

Heading **At this place** above `what_to_wear`. If the field is null, the block stays hidden.

### Mood section

Title remains “Or go by mood”. Credit line under the scroller.

### Trip

`Share my plan` only when an itinerary exists. Empty trip copy unchanged.

### Account

| Screen | Addition |
|---|---|
| Register / login | Provider buttons above the email fields. Email form remains |
| You, guest | Same buttons beside Create an account and Sign in |
| Delete, password user | Unchanged password field |
| Delete, provider-only | “Confirm with {provider}” and the explanation that this replaces the password check |

Failure copy for a bad token: “That sign-in could not be verified. Try again.” No provider error body on screen.

---

## 11. Service design

New types follow the existing domain layout.

```text
api/app/Domains/Recommendations/Scoring/MoodBlend.php
api/app/Domains/Discovery/Services/ClothingAdvisor.php
api/app/Domains/Discovery/Services/LiveIntentStore.php
api/app/Domains/Discovery/Services/PrimaryActionComposer.php
api/app/Domains/Identity/Actions/AuthenticateWithIdentityToken.php
api/app/Domains/Identity/Services/GoogleIdTokenVerifier.php
api/app/Domains/Identity/Services/AppleIdTokenVerifier.php
api/app/Domains/Identity/Models/SocialIdentity.php
api/app/Domains/Trips/Actions/ShareItinerary.php          (P4b only)
```

`RecommendationService::recommend()` calls `MoodBlend` after scoring and before persistence, so the stored score and reasons are the ones the traveller saw.

`LiveIntentStore` is the only writer. Controllers do not touch the table.

Verifiers are interfaces with a fake for tests. The HTTP client that fetches JWKS uses the existing outbound HTTP timeout. Cache JWKS for the cache period in the provider header, with a floor of 1 hour and a ceiling of 24 hours.

---

## 12. Security and privacy

- Live intent holds mood and filters, not coordinates.
- Context snapshots stay rounded. This programme adds no location table.
- ID tokens are verified and discarded. Do not store the token. Store `sub` and email.
- Audiences are an allow-list. A token minted for another app is rejected.
- Account linking runs only on a verified email.
- Deletion of a provider account needs a new token whose `sub` matches. Replay of an expired token fails.
- Shared plan messages and P4b snapshots exclude notes and traveller coordinates.
- P4b tokens are unguessable and revocable. Public read is rate-limited.
- Privacy policy update is a P5 exit gate, not a follow-up.
- Guest adoption must not attach one guest’s intent or identity to a different user. The existing `X-Guest-Token` lookup stays the only adoption key.

---

## 13. API compatibility

| Surface | Change | Clients |
|---|---|---|
| `POST /discovery/now`, `time-boxed`, `mood`, `search` | Add `primary` (nullable) and `context.weather.clothing` (nullable) | Old apps ignore unknown keys |
| Ranking | Order changes when a mood or intent applies. `engine_version` becomes `score-v1.4` | Explanations stay honest because the version is stored on the set |
| `POST /assistant/message` | Same request and response. Behaviour includes prior turns and intent upsert | No client change required for memory. Home shows the effect |
| `POST /auth/register`, `/auth/login` | Unchanged | |
| `POST /auth/google`, `/auth/apple` | New | New buttons |
| User payload | Add `providers: string[]` | Old apps ignore it. Delete screen on the new app reads it |
| `DELETE /auth/account` | Password still valid. Provider-only accepts `id_token` + `provider` instead | Old app cannot delete a provider-only account until it is updated. Do not create provider users in production before the delete screen ships in the same binary |
| Experience `what_to_wear` | Unchanged | |

---

## 14. Analytics

Use the existing `POST /events` types. Do not invent a new pipeline.

| Event | When | Properties |
|---|---|---|
| `accept_recommendation` | Traveller opens the primary card | `recommendation_set_id`, `surface: now` |
| `share` | Plan share sheet completes | `surface: trip` |
| existing auth is not an analytics event today | Do not add a raw email or `sub` to analytics | |

Mood selection is already visible as the discovery request. No extra event required for P0.

---

## 15. Testing strategy

- PHPUnit for blend, intent expiry, clothing bands, token verifier failures, adoption, and deletion. Fakes for JWKS and for Anthropic HTTP. No live Google call in CI.
- Existing score tests must still pass with `mood` null. Add one test that documents the blend delta so a future weight change cannot silently include mood in the nine.
- Mobile `tsc --noEmit` after P1, P3, P4a, and P5.
- Playwright: home hero renders `primary.action` when the fixture API returns it; mood tile count is nine after P3. Follow [`BROWSER_TESTS.md`](BROWSER_TESTS.md).
- Manual, once, on a device build for P5: Google on Android, Google and Apple on iOS, cancel, repeat login, delete with provider confirm.
- Do not claim Apple works from an Expo Go session.

---

## 16. Rollout

| Phase | Flag | Default in production until GO |
|---|---|---|
| P0 | `scoring.mood_blend` | `0` in production until the fixture test has run on the release commit, then `0.22` |
| P1 | `scoring.use_live_intent` | `false` until P0 is at `0.22`, then `true` |
| P2 | `weather.clothing_enabled` | `true` once unit tests are on the release. Safe: additive JSON |
| P3 | none | Asset-only. Ships with the binary |
| P4a | none | Button ships with the binary |
| P4b | route present, button “Copy link” hidden until tested | Off until revoke test is green |
| P5 | `EXPO_PUBLIC_GOOGLE_AUTH_ENABLED` | Off in store binaries until privacy export is published and Apple is on the iOS binary |

Order of production enablement: P0 blend, then P1 intent, then P2, P3, and P4a with the next binary, then P5 with the binary that contains deletion and Apple.

No data migration is destructive. Nullable password and new tables are additive.

---

## 17. Acceptance criteria

1. With no mood and no live intent, discovery order matches the `score-v1.3` fixture.
2. `POST /discovery/mood` with `hungry` ranks a high hungry-affinity place above a low one when other components are tied.
3. A following `POST /discovery/now` that omits mood still applies that mood until destination-local midnight.
4. Toggling the mood off, or “Clear”, makes the next now-call match criterion 1.
5. A non-empty now-response includes `primary.experience_id` equal to `data[0].id`, an `action` string, and a `directions_url` when the place has coordinates.
6. The home hero shows that action and does not say “near you” when `location_precision` is `city`.
7. A rules-driver guide message that parses as hungry changes the next now ranking.
8. The Anthropic loop is given prior messages when `conversation_id` is sent.
9. Rain and low temperature produce a clothing headline; a missing forecast produces none.
10. Experience `what_to_wear` still renders, under **At this place**.
11. All nine moods render an image and the credit line.
12. Share my plan includes each itinerary title and no private note.
13. Google create, repeat, and verified-email link behave as specified. Unverified email creates nothing.
14. Password deletion is unchanged. Provider-only deletion requires a fresh matching token.
15. iOS does not ship Google without Apple.
16. Guest adoption covers journey, saves, profile, and live intent.

---

## 18. Definition of done

- This JEM’s acceptance criteria are checked off against tests or a recorded manual pass.
- [`API.md`](API.md) documents `primary`, `clothing`, and the two auth routes.
- [`ARCHITECTURE.md`](ARCHITECTURE.md) mentions mood as a post-score blend and the live intent, so the next reader does not add a tenth weight.
- Privacy policy is re-exported if P5 shipped.
- `engine_version` on new recommendation sets is `score-v1.4`.
- Production flags match section 16.
- API suite and mobile typecheck are green.

---

## 19. Open questions

1. **Public plan links.** Is the system share sheet enough, or do recipients need a page that updates? Default: ship P4a, hold P4b until someone asks for a link that survives the message.
2. **Mood blend strength.** `0.22` is enough to reorder ties and near-ties and not enough to override a shut museum or a long walk. Confirm after one seeded-city pass. The flag can move without a schema change.
3. **Intent lifetime.** End of destination-local day is the default. A shorter 6-hour expiry is the alternative if mood feels sticky. Do not use “until logout”; guests do not log out.
4. **Apple on web.** Not required for the store rule. Default: no Apple button on web.
5. **Linking when emails differ.** A Google account whose email does not match the password account stays a separate user. We do not offer a merge UI in this JEM.
6. **Wind.** Left out until Open-Meteo requests `wind_speed_10m`. Do not copy wind advice from the place dress code into the header.

### Defaults if unanswered

| Question | Default |
|---|---|
| 1 | P4a only |
| 2 | `0.22` |
| 3 | Destination-local midnight |
| 4 | Apple on iOS only |
| 5 | No merge UI |
| 6 | No wind clause |

---

## 20. Recommended implementation sequence

1. **P0 blend and tests**, with `mood_blend` at `0.22` in tests and `0` in production config until the fixture is accepted.
2. **Derived `MOOD_AFFINITY` and the backfill command** for empty derived rows. Run the command once on staging and confirm a food place in an imported city carries `hungry`.
3. **`energetic` tile** so the client cannot send a mood the UI hides, and the API cannot be the only place that mood exists.
4. **Live intent table, store, and `ContextEngine` merge.** Adoption in `adoptGuestData`.
5. **`PrimaryActionComposer` and the home hero.**
6. **Guide: rules upsert, Anthropic history.** One feature test through the rules driver is the regression that matters in CI.
7. **`ClothingAdvisor`, weather payload, header, guide sentence, place heading.**
8. **Mood assets, tile, credit line.**
9. **`shareItinerary` and the Trip button.**
10. **P4b only if question 1 is answered yes.**
11. **Nullable password, `social_identities`, verifiers, both routes, deletion branch, profile `providers`.**
12. **Mobile buttons, delete screen, privacy copy, store client ids.** Enable the public flag only on a binary that includes Apple on iOS and the new delete screen.

Do not start step 11’s production flag before step 12’s binary. A Google user who cannot delete the account is a store rejection.

---

## 21. Status

Section 8 is the baseline from before this work. The phases below are the implementation state.
| Phase | Name | Status |
|---|---|---|
| P0 | Mood blend and derived affinity | Implemented |
| P1 | Primary action and live intent | Implemented |
| P2 | Clothing advice | Implemented |
| P3 | Mood photographs | Implemented |
| P4 | Share my plan | Implemented (on-device share sheet only) |
| P5 | Google and Apple sign-in | Implemented (client flag off until client ids are set) |

Update this table as phases exit GO.
