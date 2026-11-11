<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Delete your account — {{ config('app.name') }}</title>
  <meta name="description" content="Request deletion of your {{ config('app.name') }} account and associated data.">
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
  </style>
</head>
<body>
<main>
  <h1>Delete your account</h1>
  <p class="updated">{{ config('app.name') }} · Account and data deletion</p>

  <p class="lede">
    You can erase your {{ config('app.name') }} account and the data attached to it at any time.
    Deletion is immediate and cannot be undone.
  </p>

  <h2>Delete in the app (fastest)</h2>
  <ol>
    <li>Open {{ config('app.name') }} on your phone.</li>
    <li>Go to <strong>You</strong>, or open the subscription screen.</li>
    <li>Tap <strong>Delete your account</strong> and confirm.</li>
  </ol>
  <p>
    Guests can erase their data the same way. If you signed in with email, you will be asked for
    your password. If you signed in with Google or Apple, you will be asked to confirm with that
    provider.
  </p>

  <h2>Request deletion by email</h2>
  <div class="box">
    <p>
      If you cannot use the app, email
      <a href="mailto:{{ $contact }}?subject={{ rawurlencode(config('app.name').' account deletion request') }}">{{ $contact }}</a>
      from the address on your account.
    </p>
    <p>
      Include the subject line <strong>{{ config('app.name') }} account deletion request</strong>
      and the email address of the account you want deleted. We will delete the account and
      associated data, then confirm by email.
    </p>
  </div>

  <h2>What is deleted</h2>
  <p>
    Your profile, trips, plans, saved and completed places, guide conversations, preferences,
    stored location context, account record, and sign-in tokens.
  </p>
  <p>
    <strong>One exception.</strong> A booking that was paid for or confirmed with a supplier is
    kept as an anonymous ledger line (amount, date, supplier reference) for tax and consumer-protection
    reasons. Traveller names and every link to you are removed.
  </p>
  <p>
    Deleting your {{ config('app.name') }} account does <strong>not</strong> cancel a Google Play
    or App Store subscription. Manage that in your store account.
  </p>

  <h2>Related</h2>
  <ul>
    <li><a href="{{ $privacyUrl }}">Privacy Policy</a></li>
    <li><a href="{{ $termsUrl }}">Terms of Use</a></li>
  </ul>

  <hr>
  <footer>{{ config('app.name') }} · {{ config('experience.brand.tagline') }}</footer>
</main>
</body>
</html>
