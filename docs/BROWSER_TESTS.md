# Browser tests

```bash
cd mobile && npm run test:browser
```

Playwright against the exported web build. Two viewports (iPhone 13 and
iPhone SE), 18 routes, ~47 seconds.

## What they are for

One class of defect, which nothing else in this repository can see: an element
that is **present in the DOM, correct in the accessibility tree, and invisible
or unusable on screen**. TypeScript cannot catch it, the API suite cannot see
it, and a person reading a screenshot cannot either — the element is still
there, it is simply two pixels tall.

Every check corresponds to something that actually shipped:

| Check | What it caught |
| --- | --- |
| Text is not silently crushed | Five tab bar labels at 0px; a Creative Commons photo credit at 2px, which was also a licensing problem |
| No nested interactive controls | A save heart inside a tappable card — unreachable by keyboard or screen reader |

These are not a substitute for looking at the real app. The shipped build lays
out with Yoga natively, not with a browser's flexbox; React Native Web maps one
onto the other, and the two defects above happened to reproduce identically.
The viewport is what is being reproduced here, not the engine.

## They need the API

The suite drives the real app against real data, because the screens these
defects live on are empty without it — a hero with no photograph has no photo
credit to crush. Start the API first:

```bash
cd api && php artisan serve --port=8099
```

Without it the tests **skip** with that message rather than passing.

## Why there is a "the detectors can fail" block

Every real check asserts that a list is empty, which is the shape of assertion
that keeps passing after it has stopped looking. This suite has already
produced one false green: before the session fixture existed, every route
redirected to the welcome screen, and eighteen tests reported success while
measuring the same page eighteen times.

So three tests exist purely to prove the machinery works — one that the fixture
actually reaches the app rather than onboarding, and one per detector, pointed
at a deliberately broken element and required to find it. If a selector drifts
or React Native Web changes what it emits, those fail before the real checks
start passing for the wrong reason.

## Adding a screen

Add its path to `tests/browser/routes.ts`. Every check applies to it
automatically. A screen missing from that list is a screen nothing looks at.

## Known limits

- **Text only.** A crushed icon or SVG has no text node and is not flagged.
- **No font scaling.** A user with large text turned on stresses fixed-height
  boxes harder than a small screen does, and that cannot be emulated from the
  web build. Check it on a device with Accessibility → Larger Text at maximum
  before shipping.
- **Chromium, not WebKit.** See above: the engine is not what is being
  reproduced.
