import { expect, test } from '@playwright/test';

const SESSION_KEY = 'experience.session';
const GUEST_TOKEN_KEY = 'experience.guest_token';

/**
 * Setup is free under the paywall: finishing onboarding may create a journey
 * but must not POST /trips (that route is subscribed).
 */
test('onboarding finish creates a journey and never a trip', async ({ page }) => {
  const writes: string[] = [];

  await page.addInitScript(
    ([sessionKey, guestKey]) => {
      window.localStorage.setItem(
        sessionKey,
        JSON.stringify({
          destinationSlug: 'london-gb',
          destinationId: '11111111-1111-4111-8111-111111111111',
          destinationName: 'London',
          destinationTimezone: 'Europe/London',
          journeyId: null,
          tripId: null,
          locationPrecision: 'city',
          locationSource: 'destination',
          onboarded: false,
        }),
      );
      window.localStorage.setItem(guestKey, 'browser-onboarding-free-setup');
    },
    [SESSION_KEY, GUEST_TOKEN_KEY] as const,
  );

  await page.route('**/api/**', async (route) => {
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({ status: 204 });
      return;
    }

    const url = new URL(route.request().url());
    const path = url.pathname.replace(/^\/api/, '');
    const method = route.request().method();

    if (method !== 'GET' && method !== 'HEAD') {
      writes.push(`${method} ${path}`);
    }

    if (path === '/billing/entitlement') {
      await route.fulfill({
        json: {
          data: {
            can_use: false,
            state: 'inactive',
            paid_until: null,
            auto_renew: false,
            pause_resumes_at: null,
          },
        },
      });
      return;
    }

    if (path === '/traveller/profile' && method === 'PATCH') {
      await route.fulfill({
        json: {
          data: {
            id: 'profile-1',
            display_name: 'Guest',
            is_guest: true,
            travel_pace: 'moderate',
            budget_level: 'moderate',
            iconic_vs_local: 60,
            interests: {},
          },
        },
      });
      return;
    }

    if (path === '/journeys' && method === 'POST') {
      await route.fulfill({
        status: 201,
        json: {
          data: {
            id: 'journey-1',
            destination_id: '11111111-1111-4111-8111-111111111111',
            reason: 'holiday',
            starts_on: '2026-11-20',
            ends_on: '2026-11-22',
            anchors: [],
          },
        },
      });
      return;
    }

    if (path === '/trips' && method === 'POST') {
      await route.fulfill({
        status: 402,
        json: { message: 'WAYOA needs an active month.', code: 'subscription_required' },
      });
      return;
    }

    if (path === '/journeys/journey-1') {
      await route.fulfill({
        json: {
          data: {
            id: 'journey-1',
            destination_id: '11111111-1111-4111-8111-111111111111',
            reason: 'holiday',
            starts_on: '2026-11-20',
            ends_on: '2026-11-22',
            anchors: [],
          },
        },
      });
      return;
    }

    await route.fulfill({ json: { data: null } });
  });

  await page.goto('/onboarding/purpose');
  await page.getByRole('button', { name: /Holiday/i }).click();
  await page.getByRole('button', { name: 'Continue' }).click();

  await page.getByRole('button', { name: /essentials without feeling/i }).click();
  await page.getByRole('button', { name: 'Continue' }).click();

  await page.getByRole('button', { name: 'History' }).click();
  await page.getByRole('button', { name: 'Continue' }).click();

  await expect(page).toHaveURL(/\/onboarding\/style/);
  await page.getByRole('button', { name: 'Balanced 3 things a day' }).click();
  await page.getByRole('button', { name: 'Moderate', exact: true }).click();
  await page.getByRole('button', { name: '20 November' }).click();
  await page.getByRole('button', { name: '22 November' }).click();
  await page.getByRole('button', { name: 'Show me what is worth doing' }).click();

  await expect(page).toHaveURL(/\/onboarding\/ready/, { timeout: 15_000 });
  await expect(page.getByRole('button', { name: 'See what a month unlocks' })).toBeVisible();

  expect(writes.filter((w) => w === 'POST /trips')).toEqual([]);
  expect(writes).toContain('POST /journeys');
  expect(writes).toContain('PATCH /traveller/profile');
});
