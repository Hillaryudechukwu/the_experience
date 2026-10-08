import { test as base, expect, request as playwrightRequest, type Page } from '@playwright/test';

const API = process.env.EXPO_PUBLIC_API_URL ?? 'http://127.0.0.1:8099/api';

/** AsyncStorage on web is localStorage, and the store writes one key. */
const SESSION_KEY = 'experience.session';

type Destination = { id: string; slug: string; name: string; timezone: string };

/**
 * A browser with a traveller already in it.
 *
 * Without this every route redirects to the welcome screen, because the router
 * gate sends anyone with no stored session to onboarding. The first version of
 * this suite had no fixture and reported eighteen passing routes while
 * measuring the same welcome screen eighteen times — green, fast, and testing
 * nothing.
 *
 * The lookup is worker-scoped, not per-test. Per-test it made one API call for
 * every route at every viewport, which the API's own rate limiter answered by
 * refusing some of them, and a fixture that cannot reach the API skips its
 * test. The result was a run that quietly skipped a quarter of itself.
 */
export const test = base.extend<{ seeded: Page }, { destination: Destination | null }>({
  destination: [
    async ({}, use) => {
      let destination: Destination | null = null;

      try {
        const context = await playwrightRequest.newContext();
        const response = await context.get(`${API}/destinations?q=london`, { timeout: 10_000 });
        destination = (await response.json())?.data?.[0] ?? null;
        await context.dispose();
      } catch {
        /* The fixture below fails loudly. A green run with zero exercised
           screens is more dangerous than a red run with a clear dependency. */
      }

      await use(destination);
    },
    { scope: 'worker' },
  ],

  seeded: async ({ page, destination }, use) => {
    expect(
      destination,
      `The API at ${API} is not answering. Start it with "php artisan serve --port=8099"; ` +
        'the browser suite is not allowed to turn an unavailable dependency into skipped tests.',
    ).not.toBeNull();

    /* The exported bundle is served from localhost, while production rightly
       permits only the production web origin through CORS. Proxy requests
       through Playwright instead of weakening the deployed CORS policy. */
    await page.route(`${API}/**`, async (route) => {
      if (route.request().method() === 'OPTIONS') {
        await route.fulfill({
          status: 204,
          headers: {
            'access-control-allow-origin': '*',
            'access-control-allow-headers': '*',
            'access-control-allow-methods': 'GET,POST,PATCH,DELETE,OPTIONS',
          },
        });
        return;
      }

      const response = await route.fetch();
      await route.fulfill({
        response,
        headers: { ...response.headers(), 'access-control-allow-origin': '*' },
      });
    });

    await page.addInitScript(
      ([key, value]) => window.localStorage.setItem(key, value),
      [
        SESSION_KEY,
        JSON.stringify({
          destinationSlug: destination!.slug,
          destinationId: destination!.id,
          destinationName: destination!.name,
          destinationTimezone: destination!.timezone,
          journeyId: null,
          tripId: null,
          locationPrecision: 'precise',
          onboarded: true,
        }),
      ] as const,
    );

    await use(page);
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  },
});

export { expect };

/** Navigates and waits for layout to finish, because this suite measures it. */
export async function settle(page: Page, path: string) {
  await page.goto(path);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(800);
}
