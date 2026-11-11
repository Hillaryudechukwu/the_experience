<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Delete your {{ config('app.name') }} account and data</title>
  <meta name="description" content="How to request deletion of your {{ config('app.name') }} account and associated data.">
  <style>
    :root { color-scheme: light dark; --ink: #0D1B2A; --muted: #4A5468; --rule: #E4E8EF; --bg: #FFFFFF; --accent: #2856F6; }
    @media (prefers-color-scheme: dark) {
      :root { --ink: #F2F6FC; --muted: #A8B6C8; --rule: #22344C; --bg: #08111F; --accent: #8FAEFF; }
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; background: var(--bg); color: var(--ink);
      font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      padding: 48px 24px 96px;
    }
    main { max-width: 46rem; margin: 0 auto; }
    h1 { font-size: 2rem; line-height: 1.2; margin: 0 0 8px; letter-spacing: -0.02em; }
    h2 { font-size: 1.15rem; margin: 40px 0 10px; letter-spacing: -0.01em; }
    p, li { color: var(--muted); }
    li { margin-bottom: 6px; }
    .updated { color: var(--muted); font-size: 0.875rem; margin: 0 0 32px; }
    .lede { color: var(--ink); font-size: 1.05rem; }
    a { color: var(--accent); }
    hr { border: 0; border-top: 1px solid var(--rule); margin: 48px 0 24px; }
    footer { color: var(--muted); font-size: 0.875rem; }
    .box {
      border: 1px solid var(--rule);
      border-radius: 12px;
      padding: 20px 22px;
      margin: 24px 0;
    }
    .box p:last-child { margin-bottom: 0; }
    table { border-collapse: collapse; width: 100%; margin: 16px 0; font-size: 0.9rem; display: block; overflow-x: auto; }
    th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--rule); vertical-align: top; }
    th { color: var(--ink); font-weight: 600; white-space: nowrap; }
    td { color: var(--muted); }
  </style>
</head>
<body>
<main>
  <h1>Delete your {{ config('app.name') }} account and data</h1>
  <p class="updated">{{ config('app.name') }} · Request account and data deletion</p>

  <p class="lede">
    This page is for the {{ config('app.name') }} app. You can request that we delete your account
    and the personal data we hold about you. Deletion is immediate once we process the request
    and cannot be undone.
  </p>

  <h2>How to request deletion</h2>
  <p><strong>In the app (fastest)</strong></p>
  <ol>
    <li>Open the {{ config('app.name') }} app on your phone.</li>
    <li>Go to <strong>You</strong>, or open the subscription / paywall screen.</li>
    <li>Tap <strong>Delete your account</strong> and confirm.</li>
  </ol>
  <p>
    Guests can erase their data the same way. Email sign-in asks for your password. Google or
    Apple sign-in asks you to confirm with that provider.
  </p>

  <div class="box">
    <p><strong>By email</strong> — if you cannot use the app:</p>
    <ol>
      <li>Email <a href="mailto:{{ $contact }}?subject={{ rawurlencode(config('app.name').' data deletion request') }}">{{ $contact }}</a> from the address on your account (or the email you used as a guest, if any).</li>
      <li>Use the subject line <strong>{{ config('app.name') }} data deletion request</strong>.</li>
      <li>Include the account email (or enough detail for us to find your data) and say you want your account and associated data deleted.</li>
    </ol>
    <p>We will delete the data, then confirm by email.</p>
  </div>

  <h2>Data that is deleted</h2>
  <p>When deletion completes, we erase:</p>
  <ul>
    <li>Account record (name, email, hashed password) and sign-in tokens</li>
    <li>Profile and preferences (interests, pace, budget, accessibility needs)</li>
    <li>Trips, plans, saved and completed places, journal notes</li>
    <li>Stored location context</li>
    <li>Guide / assistant conversations</li>
    <li>Behavioural events (opens, saves, ignores)</li>
    <li>Push notification device tokens</li>
    <li>Subscription entitlement records we hold for your account</li>
  </ul>

  <h2>Data that is kept, and for how long</h2>
  <table>
    <tr>
      <th>Kept</th>
      <td>Paid or confirmed booking ledger only: amount, date, and the supplier’s reference. Traveller names and every link to you are removed — it is an anonymous line, not your account.</td>
    </tr>
    <tr>
      <th>Why</th>
      <td>Tax and consumer-protection obligations (and supplier / payment disputes).</td>
    </tr>
    <tr>
      <th>Retention</th>
      <td>Typically up to six years, then deleted. Bookings that never reached payment are deleted outright with your account.</td>
    </tr>
  </table>
  <p>
    Deleting your {{ config('app.name') }} account does <strong>not</strong> cancel a Google Play
    or App Store subscription. Manage that in your store account.
  </p>

  <h2>Related</h2>
  <ul>
    <li><a href="{{ $privacyUrl }}">{{ config('app.name') }} Privacy Policy</a></li>
    <li><a href="{{ $termsUrl }}">{{ config('app.name') }} Terms of Use</a></li>
  </ul>

  <hr>
  <footer>{{ config('app.name') }} · {{ config('experience.brand.tagline') }}</footer>
</main>
</body>
</html>
