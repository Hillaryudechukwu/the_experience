# Releasing to the App Store and Play Store

Two things in this repository exist only to satisfy store review and the
platforms' link verification. Both need values that do not exist until the
first build, so this is the order they have to happen in.

## 1. The privacy policy

Both stores require a URL they can open without the app, and the store listing
keeps that URL for as long as the app is listed. It must therefore outlive any
decision about where the backend runs.

The policy is authored once as a Blade view and served at `/legal/privacy`.
That copy is the fallback. The one the listings point at should sit on a domain
we own:

```bash
php artisan experience:export-legal
```

That writes `storage/app/legal/privacy.html`. Publish it at the address in
`PRIVACY_POLICY_URL`, then give that same address to:

- the Play Console listing,
- App Store Connect,
- `EXPO_PUBLIC_PRIVACY_URL` in the mobile build, so the in-app link and the
  listings agree.

Re-run the command whenever the policy changes, and move
`EXPERIENCE_PRIVACY_UPDATED` with it — a reviewer checks that date against the
content, and it is the one field they can check.

## 2. Deep links

`https://experience.synteric.co.uk/experience/{id}` should open the app rather
than the browser. Both platforms decide that by fetching a file from the
domain, which is the entire security model: only whoever controls the domain
can publish it, so only they can claim its links.

This app serves both files, driven by config:

| Path | Platform |
| --- | --- |
| `/.well-known/assetlinks.json` | Android |
| `/.well-known/apple-app-site-association` | iOS |

Neither is served half-built. Without a fingerprint or a team id they return
404 rather than an association that cannot verify — Android caches a failed
verification, and a cached failure is much harder to notice than a missing file.

### Getting the values

The Android fingerprint is the SHA-256 of the certificate the app is actually
signed with, which does not exist until EAS has built once:

```bash
npx eas-cli@latest credentials -p android
```

Copy the SHA-256 into `APP_LINKS_ANDROID_SHA256` — comma-separated if there is
more than one, and there will be if you ever rotate the key, because both the
old and new certificates must be listed or existing installs stop verifying.

The iOS team id is on the Apple Developer membership page; it goes in
`APP_LINKS_IOS_TEAM_ID`.

### Checking it worked

The domain must serve both over HTTPS with a valid certificate, as
`application/json`, with no redirect — Apple will not follow one.

```bash
curl -sI https://experience.synteric.co.uk/.well-known/assetlinks.json
```

Android's verifier can be queried on a connected device:

```bash
adb shell pm get-app-links uk.co.synteric.experience
```

`verified` is the only state that works. `legacy_failure` means the file was
wrong when it was first checked; clear it with
`adb shell pm verify-app-links --re-verify uk.co.synteric.experience` after
fixing the file, because it will not re-check on its own.

### What is claimed

Only `/experience/*`. A claimed path with no screen behind it is worse than an
unclaimed one — it takes the link away from the browser that could have
rendered it and dead-ends the traveller inside the app. Add a path to
`experience.app_links.paths` and to `android.intentFilters` in `app.json` only
when a route exists to receive it.

## 3. Identifiers

`uk.co.synteric.experience` on both platforms. Permanent on both: Play fixes
the package name at first upload and the App Store fixes the bundle id at first
submission. Neither can be changed afterwards — only abandoned for a new
listing with no installs, reviews or ranking.

## 4. Building the Android bundle

Play Console wants an `.aab` (Android App Bundle). EAS produces one from the
`production` profile in `mobile/eas.json`.

```bash
cd mobile
npx eas-cli@latest login
npx eas-cli@latest init
npx eas-cli@latest build --platform android --profile production
```

The build runs on Expo's servers and prints a URL. When it finishes, that page
has a **Download** button — the file it gives you is the `.aab` to drag onto
the Play Console release page.

EAS generates and holds the Android keystore on the first build. Do not lose
access to that Expo account: the upload key is how Google identifies your app
for the rest of its life, and a bundle signed with a different key is rejected.
Back it up with `eas credentials -p android`.

`appVersionSource` is `remote` and `autoIncrement` is on, so EAS owns the
`versionCode` and raises it on every build. That is why `app.json` has none —
two sources for one number is how you end up uploading a duplicate.

### Before you build: the API URL

`eas.json` production (and preview) must point at the live API and privacy
policy — never loopback. Left unset, `resolveApiUrl()` falls back to
`http://127.0.0.1:8099`, which on a phone is the phone.

Current production values:

- `EXPO_PUBLIC_API_URL=https://api.experience.synteric.co.uk/api`
- `EXPO_PUBLIC_PRIVACY_URL=https://experience.synteric.co.uk/privacy`

Confirm both before kicking an EAS store build. For iOS universal links, set
`APP_LINKS_IOS_TEAM_ID` on the API host and redeploy so
`/.well-known/apple-app-site-association` is published (404 until then is
intentional).
