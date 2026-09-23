<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Privacy Policy — {{ config('app.name') }}</title>
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
  <h1>Privacy Policy</h1>
  <p class="updated">{{ config('app.name') }} · Last updated {{ $updated }}</p>

  <p class="lede">
    This app answers one question: what is the best use of the next part of your trip? To answer it
    we need to know a little about where you are and what you like. This page says exactly what that
    means, who else sees it, and how to get rid of it.
  </p>

  <h2>What we collect</h2>

  <table>
    <tr><th>Location</th><td>Your coordinates, only while the app is open and only if you allow it. The app works without it — you can pick a city instead. Coordinates stored against a trip are rounded to roughly 100&nbsp;m before they are saved, so what is kept is an area rather than a trail.</td></tr>
    <tr><th>Your trip</th><td>The city, dates, who is travelling, and any fixed commitments you enter — a meeting, a dinner reservation, a hotel. These are the things we plan around, and some of them are sensitive.</td></tr>
    <tr><th>Preferences</th><td>Interests, pace, budget level, accessibility needs, and what you have saved or marked as done.</td></tr>
    <tr><th>Behaviour</th><td>Which suggestions you opened, saved or ignored. This is what makes later suggestions better.</td></tr>
    <tr><th>Conversations</th><td>What you type to the in-app guide.</td></tr>
    <tr><th>Account</th><td>Name, email and a hashed password — only if you create an account. You can use the whole app without one.</td></tr>
    <tr><th>Bookings</th><td>If you book something: the traveller names required by the supplier, the amount, and a reference. We do not store card numbers; payment is handled by the provider.</td></tr>
  </table>

  <h2>What we do not do</h2>
  <ul>
    <li>We do not track your location in the background, or when the app is closed.</li>
    <li>We do not sell your data, and we do not share it with advertisers or data brokers.</li>
    <li>We do not build advertising profiles or run third-party ad SDKs.</li>
  </ul>

  <h2>Who else sees it</h2>
  <p>
    To answer a question about where you are, we have to ask services that know about places, roads
    and weather. We send them the least that will get an answer — usually a coordinate or a place
    name, never your identity, your email or your trip.
  </p>
  <ul>
    <li><strong>OpenStreetMap / Overpass, Nominatim</strong> — what places exist nearby.</li>
    <li><strong>Google Places</strong> — ratings, opening hours and photographs of places.</li>
    <li><strong>OSRM</strong> — how long it takes to walk somewhere.</li>
    <li><strong>Open-Meteo</strong> — the weather where you are.</li>
    <li><strong>Wikimedia / Wikipedia</strong> — descriptions and licensed photographs.</li>
    <li><strong>Frankfurter</strong> — currency conversion. No personal data is sent.</li>
    <li><strong>Ticketing partners</strong> — only if you choose to book, and only what the booking requires.</li>
    <li><strong>Anthropic</strong> — if you use the in-app guide, your message and the relevant facts about your trip are sent to the model that answers it. Do not type anything into it you would not want processed by a third party.</li>
  </ul>

  <h2>How long we keep it</h2>
  <ul>
    <li>Trip and location context: until you delete it, or the trip has been over for 12 months.</li>
    <li>Behavioural events: 24 months, then deleted.</li>
    <li>Bookings: retained for as long as tax and consumer-protection law requires, which is typically six years. See the note under deletion.</li>
    <li>Everything else: until you delete your account.</li>
  </ul>

  <h2>Your controls</h2>
  <p>All of these are in the app, under Profile, and all of them work for guests as well as account holders.</p>
  <ul>
    <li><strong>Export</strong> — get a copy of everything held about you.</li>
    <li><strong>Forget where I have been</strong> — delete stored location context without deleting anything else.</li>
    <li><strong>Delete account</strong> — erase your account and everything attached to it.</li>
  </ul>

  <h3>What deletion does, precisely</h3>
  <p>
    Deleting your account removes your profile, trips, plans, saved and completed places, your
    conversations with the guide, everything we learned about your preferences, your stored location
    context, your account record and every sign-in token.
  </p>
  <p>
    <strong>One thing survives.</strong> A booking that was actually paid for or confirmed with a
    supplier is a record of a transaction, and a supplier, a tax authority or a card dispute may
    legitimately need it later. We keep the amount, the date and the supplier's reference — and we
    delete the traveller names and sever every link between that record and you. It is no longer
    yours; it is an anonymous line in a ledger. Bookings that never reached payment are deleted
    outright.
  </p>
  <p>Deletion is immediate and cannot be undone.</p>

  <h2>Children</h2>
  <p>
    This app is not directed at children under 13, and we do not knowingly collect their data. Ages
    of children travelling with you are used only to filter out unsuitable suggestions.
  </p>

  <h2>Your rights</h2>
  <p>
    If you are in the UK or EU, the UK GDPR and GDPR give you the right to access, correct, export,
    restrict and erase your data, and to object to processing. The export and deletion controls above
    are how to exercise most of them immediately; for anything else, write to us. You may also
    complain to your data protection authority — in the UK, the Information Commissioner's Office.
  </p>

  <h2>Changes</h2>
  <p>
    If this policy changes in a way that affects what we collect or who sees it, the app will tell you
    before the change takes effect rather than relying on you re-reading this page.
  </p>

  <h2>Contact</h2>
  <p>
    Questions, or a request you cannot action in the app:
    <a href="mailto:{{ $contact }}">{{ $contact }}</a>.
  </p>

  <hr>
  <footer>{{ config('app.name') }} · Travel Deeper. Live More.</footer>
</main>
</body>
</html>
