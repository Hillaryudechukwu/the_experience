import { defineConfig, devices } from '@playwright/test';

/**
 * Browser tests against the exported web build.
 *
 * The app ships to phones, not browsers, so this is not here to prove the
 * product works — the API suite and the type checker do most of that. It is
 * here for one class of defect that only a real layout engine can see: an
 * element that is present in the DOM, correct in the accessibility tree, and
 * invisible or unusable on screen. Nothing else in this repository can catch
 * that, and it has shipped twice.
 *
 * The export is static, so the build runs once and a plain file server hosts
 * it. The two viewports matter: several of these failures only appear when
 * content overflows a fixed-height box, which is a function of screen size.
 */
export default defineConfig({
  testDir: './tests/browser',
  fullyParallel: true,

  /*
   * Capped, because the suite shares one IP with the API's own rate limiter.
   *
   * The API allows 300 requests a minute per address, and ninety tests across
   * two viewports at full parallelism push past it. Requests then come back
   * 429 in a pattern that depends on machine speed, which is not something a
   * layout suite should be measuring.
   *
   * Worth recording what happened the first time this bit: two tests timed out
   * waiting for a control, and the cap did not fix them, because they were not
   * flaky. The 429s had exposed a real defect — three screens rendered a
   * spinner forever when their request failed — and the rate limit was simply
   * the thing making it reproducible. The cap is for determinism; it was the
   * screens that were broken.
   *
   * Raising the app's limit to suit the tests would have been fixing the wrong
   * thing: the limit is real and protects a real deployment.
   */
  workers: 3,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: process.env.CI ? 'github' : 'list',

  use: {
    /* Deliberately not 8099: that is the API's port in local development, and
       `reuseExistingServer` will happily bind to whatever is already there.
       The first run of this suite did exactly that — it tested the Laravel
       welcome page and its 404s, found no crushed text in them, and reported
       72 passes without ever loading the app. */
    baseURL: 'http://127.0.0.1:4173',
    trace: 'retain-on-failure',
  },

  /*
   * Chromium at phone sizes, rather than WebKit.
   *
   * This is already a proxy: the shipped app lays out with Yoga natively, not
   * with a browser's flexbox, and React Native Web maps one onto the other. So
   * the engine is not the thing being reproduced — the viewport is. Both
   * defects these tests exist for showed up identically here, and Chromium is
   * a fraction of the download and the CI time.
   *
   * What this cannot do is replace looking at the real app on a real device.
   */
  projects: [
    {
      name: 'phone',
      use: { ...devices['iPhone 13'], browserName: 'chromium', defaultBrowserType: 'chromium' },
    },
    /* The smallest screen still in use. Fixed-height boxes that fit at 390
       overflow here, which is exactly when their contents get crushed. */
    {
      name: 'small phone',
      use: { ...devices['iPhone SE'], browserName: 'chromium', defaultBrowserType: 'chromium' },
    },
  ],

  webServer: {
    /* The --proxy argument is http-server's single-page fallback: a request
       for /onboarding/purpose has no file behind it, so it is served index.html
       and the router takes over. Without it every route but / is a 404. */
    command:
      'npx expo export --platform web --output-dir dist --clear && ' +
      'npx http-server dist -p 4173 -s --proxy "http://127.0.0.1:4173?"',
    url: 'http://127.0.0.1:4173',
    reuseExistingServer: !process.env.CI,
    timeout: 300_000,
  },
});
