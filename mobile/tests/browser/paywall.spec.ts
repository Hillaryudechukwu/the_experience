import { expect, test } from '@playwright/test';

const SESSION_KEY = 'experience.session';
const GUEST_TOKEN_KEY = 'experience.guest_token';

async function seedUnpaidOnboarded(page: import('@playwright/test').Page) {
  await page.addInitScript(
    ([sessionKey, guestKey]) => {
      window.localStorage.setItem(
        sessionKey,
        JSON.stringify({
          destinationSlug: 'london-gb',
          destinationId: '11111111-1111-4111-8111-111111111111',
          destinationName: 'London',
          destinationTimezone: 'Europe/London',
          journeyId: 'journey-1',
          tripId: null,
          locationPrecision: 'city',
          locationSource: 'destination',
          onboarded: true,
        }),
      );
      window.localStorage.setItem(guestKey, 'browser-paywall-test');
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

    if (path === '/traveller/profile') {
      await route.fulfill({
        json: {
          data: {
            id: 'profile-1',
            display_name: 'Sandbox Tester',
            is_guest: false,
            has_password: true,
            account: { name: 'Sandbox Tester', email: 'sandbox@wayoa.test', initials: 'ST' },
          },
        },
      });
      return;
    }

    await route.fulfill({ json: { data: null } });
  });
}

test('root sends an unpaid onboarded traveller to the paywall', async ({ page }) => {
  await seedUnpaidOnboarded(page);
  await page.goto('/');
  await expect(page).toHaveURL(/\/paywall/);
  await expect(page.getByText(/WAYOA is £1\.99 a month/i)).toBeVisible();
});

test('paywall shows price, period, legal links and delete before any purchase', async ({ page }) => {
  await seedUnpaidOnboarded(page);

  const opened: string[] = [];
  await page.exposeFunction('__recordOpen', (url: string) => {
    opened.push(url);
  });
  await page.addInitScript(() => {
    const record = (url: string) => {
      // @ts-expect-error bridge installed in test
      void window.__recordOpen?.(url);
    };
    const originalOpen = window.open.bind(window);
    window.open = ((url?: string | URL, ...rest: unknown[]) => {
      if (url != null) record(String(url));
      return originalOpen(url as string, ...(rest as [string?, string?]));
    }) as typeof window.open;
  });

  await page.goto('/paywall');

  await expect(page.getByText(/WAYOA is £1\.99 a month/i)).toBeVisible();
  await expect(page.getByText('£1.99 for one month')).toBeVisible();
  await expect(page.getByText('Renews until you pause or cancel')).toBeVisible();
  await expect(page.getByText('The month you have paid for always runs to its end')).toBeVisible();
  await expect(page.getByText(/Subscribe in the WAYOA app on iPhone or Android/i)).toBeVisible();

  await page.getByRole('button', { name: 'Terms of use' }).click();
  await page.getByRole('button', { name: 'Privacy policy' }).click();
  await expect.poll(() => opened.length).toBeGreaterThanOrEqual(1);

  await page.getByRole('button', { name: 'Delete your account' }).click();
  await expect(page).toHaveURL(/\/account\/delete/);
  await expect(page.getByText(/Deleting this account does not cancel a subscription/i)).toBeVisible();
});
