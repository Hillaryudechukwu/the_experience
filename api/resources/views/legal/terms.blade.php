<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Terms of Use — {{ config('app.name') }}</title>
  <meta name="description" content="{{ config('experience.brand.description') }}">
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
    h3 { font-size: 1rem; margin: 24px 0 6px; }
    p, li { color: var(--muted); }
    li { margin-bottom: 6px; }
    .updated { color: var(--muted); font-size: 0.875rem; margin: 0 0 32px; }
    .lede { color: var(--ink); font-size: 1.05rem; }
    table { border-collapse: collapse; width: 100%; margin: 16px 0; font-size: 0.9rem; display: block; overflow-x: auto; }
    th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--rule); vertical-align: top; }
    th { color: var(--ink); font-weight: 600; white-space: nowrap; }
    td { color: var(--muted); }
    a { color: var(--accent); }
    hr { border: 0; border-top: 1px solid var(--rule); margin: 48px 0 24px; }
    footer { color: var(--muted); font-size: 0.875rem; }
  </style>
</head>
<body>
<main>
  <h1>Terms of Use</h1>
  <p class="updated">{{ config('app.name') }} · Last updated {{ $updated }}</p>

  <p class="lede">
    {{ config('app.name') }} is free to open and free to set up. Using it — finding what is worth
    doing, asking the guide, building a plan, booking — needs a paid month. This page says what the
    month costs, what happens when you stop it, and what we do and do not hold.
  </p>

  <h2>The subscription</h2>

  <table>
    <tr><th>Price</th><td>{{ $price }} per month</td></tr>
    <tr><th>Period</th><td>One month. There is no week, no day pass and no free trial.</td></tr>
    <tr><th>Renewal</th><td>It renews automatically until you stop it.</td></tr>
    <tr><th>Charged by</th><td>Apple or Google, depending on where you installed the app.</td></tr>
  </table>

  <p>
    Your month starts when the store confirms the payment, not when you tap subscribe. If a payment
    is still pending, the app stays closed until it goes through.
  </p>

  <h2>Stopping, and what you keep</h2>

  <p>
    <strong>The month you have paid for always runs to its end.</strong> Pausing or cancelling stops
    the next charge; it does not cut short the month you already bought and it does not refund it.
    When that month ends, the app stops working until another paid month is active.
  </p>

  <p>
    A failed renewal is not a paid month. If the store cannot take the payment, access ends when the
    month you last paid for runs out.
  </p>

  <h3>On Android</h3>
  <p>
    Google Play has a real pause. You choose how long, Play stops billing for that time, and when
    the pause ends Play tries to charge you again. If that charge succeeds, the app works again by
    itself.
  </p>

  <h3>On iPhone and iPad</h3>
  <p>
    Apple has no pause. You stop the next renewal from your Apple subscription settings, and
    {{ config('app.name') }} will <strong>not</strong> turn itself back on later. Using it again
    means buying another month.
  </p>

  <h2>Money</h2>

  <ul>
    <li>Apple and Google take the payment. We never see or store your card.</li>
    <li>Refunds are requested from Apple or Google, not from us. We cannot issue one.</li>
    <li>Prices are shown by the store in your own currency before you confirm.</li>
  </ul>

  <h2>Your account and your subscription are two different things</h2>

  <p>
    <strong>Deleting your {{ config('app.name') }} account does not cancel your subscription.</strong>
    The subscription belongs to your Apple or Google account, and only you can stop it there. If you
    delete your account and want the charges to stop, cancel in the store as well.
  </p>

  <ul>
    <li><a href="https://apps.apple.com/account/subscriptions">Manage Apple subscriptions</a></li>
    <li><a href="https://play.google.com/store/account/subscriptions">Manage Google Play subscriptions</a></li>
  </ul>

  <p>
    One paid month covers one {{ config('app.name') }} account. Signing in on another device
    restores the same month; it does not create a second one.
  </p>

  <h2>What you can expect of us</h2>

  <p>
    We recommend places and times based on what you tell us and what public sources say. Opening
    hours, prices and availability come from other people's data and can be wrong or out of date.
    Check anything that matters — a closing time, a booking, a price — before you rely on it.
  </p>

  <p>
    Bookings you make through a provider are agreements with that provider, under their terms. We
    pass you to them; we are not the ticket seller.
  </p>

  <h2>What we expect of you</h2>

  <ul>
    <li>Use the app for planning your own travel.</li>
    <li>Do not try to scrape it, resell what it produces, or get round the paid month.</li>
    <li>You need to be old enough to hold a store account in your country.</li>
  </ul>

  <h2>Ending it from our side</h2>

  <p>
    We can close an account that is being used to abuse the service or the providers behind it. If
    we do that while you have a paid month, we will say why.
  </p>

  <h2>Changes</h2>

  <p>
    If these terms change in a way that affects what you pay or what you get, the date at the top
    moves and the app will say so before the next charge.
  </p>

  <h2>Contact</h2>

  <p>Questions about these terms: <a href="mailto:{{ $contact }}">{{ $contact }}</a>.</p>

  <p>
    How we handle your data is a separate page: <a href="{{ $privacyUrl }}">Privacy Policy</a>.
  </p>

  <hr>
  <footer>{{ config('app.name') }} · {{ config('experience.brand.tagline') }}</footer>
</main>
</body>
</html>
