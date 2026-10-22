import { expect, test } from '@playwright/test';

const SESSION_KEY = 'experience.session';
const GUEST_TOKEN_KEY = 'experience.guest_token';

test('open access does not make an inactive subscription look paid', async ({ page }) => {
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
          onboarded: true,
        }),
      );
      window.localStorage.setItem(guestKey, 'browser-subscription-test');
    },
    [SESSION_KEY, GUEST_TOKEN_KEY] as const,
  );

  await page.route('**/api/**', async (route) => {
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({ status: 204 });
      return;
    }

    const path = new URL(route.request().url()).pathname.replace(/^\/api/, '');

    if (path === '/billing/entitlement') {
      await route.fulfill({
        json: {
          data: {
            can_use: true,
            state: 'inactive',
            paid_until: null,
            auto_renew: false,
            pause_resumes_at: null,
          },
        },
      });
      return;
    }

    await route.fulfill({ json: { data: null } });
  });

  await page.goto('/subscription');

  await expect(page.getByText('You do not have a month running.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'See what a month costs' })).toBeVisible();
  await expect(page.getByText('£1.99 a month')).toHaveCount(0);
});
