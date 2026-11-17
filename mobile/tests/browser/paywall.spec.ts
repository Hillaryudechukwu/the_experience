import { expect, test } from '@playwright/test';

const SESSION_KEY = 'experience.session';
const GUEST_TOKEN_KEY = 'experience.guest_token';

type Seed = { canUse: boolean; tripId: string | null };

async function seedOnboarded(page: import('@playwright/test').Page, seed: Seed) {
  await page.addInitScript(
    ([sessionKey, guestKey, tripId]) => {
      window.localStorage.setItem(
        sessionKey,
        JSON.stringify({
          destinationSlug: 'london-gb',
          destinationId: '11111111-1111-4111-8111-111111111111',
          destinationName: 'London',
          destinationTimezone: 'Europe/London',
          journeyId: 'journey-1',
          tripId,
          locationPrecision: 'city',
          locationSource: 'destination',
          onboarded: true,
        }),
      );
      window.localStorage.setItem(guestKey, 'browser-paywall-test');
    },
    [SESSION_KEY, GUEST_TOKEN_KEY, seed.tripId] as const,
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
            can_use: seed.canUse,
            state: seed.canUse ? 'active' : 'inactive',
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

function seedUnpaidOnboarded(page: import('@playwright/test').Page) {
  return seedOnboarded(page, { canUse: false, tripId: null });
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
  await expect(page.getByText('Signed in as sandbox@wayoa.test')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible();

  await page.getByRole('button', { name: 'Terms of use' }).click();
  await page.getByRole('button', { name: 'Privacy policy' }).click();
  await expect.poll(() => opened.length).toBe(2);
  expect(opened[0]).toMatch(/\/legal\/terms$/);
  expect(opened[1]).toMatch(/\/privacy$/);

  await page.getByRole('button', { name: 'Delete your account' }).click();
  await expect(page).toHaveURL(/\/account\/delete/);
  await expect(page.getByText(/Deleting this account does not cancel a subscription/i)).toBeVisible();
});

test('paywall sign out clears the account and opens login', async ({ page }) => {
  await seedUnpaidOnboarded(page);

  await page.route('**/api/auth/logout', async (route) => {
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({ status: 204 });
      return;
    }
    await route.fulfill({ json: { status: 'ok' } });
  });

  await page.goto('/paywall');
  await expect(page.getByText('Signed in as sandbox@wayoa.test')).toBeVisible();

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page).toHaveURL(/\/account\/login/);
});

test('paywall sign out still opens login when the server refuses it', async ({ page }) => {
  await seedUnpaidOnboarded(page);

  await page.route('**/api/auth/logout', async (route) => {
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({ status: 204 });
      return;
    }
    await route.fulfill({ status: 500, json: { message: 'Server error' } });
  });

  await page.goto('/paywall');
  await expect(page.getByText('Signed in as sandbox@wayoa.test')).toBeVisible();

  /* The confirm, then the hook's failure alert. */
  page.on('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page).toHaveURL(/\/account\/login/);
});

/* Onboarding leaves a journey without a trip; ready is what builds it. */
test('a month bought straight after setup opens ready, not an empty Home', async ({ page }) => {
  await seedOnboarded(page, { canUse: true, tripId: null });
  await page.goto('/paywall');
  await expect(page).toHaveURL(/\/onboarding\/ready/);
});

test('a month bought with a trip already in place opens Home', async ({ page }) => {
  await seedOnboarded(page, { canUse: true, tripId: 'trip-1' });
  await page.goto('/paywall');
  await page.waitForURL((url) => !/\/paywall|\/onboarding/.test(url.pathname));
});

test('subscription confirmation celebrates the month and continues into the app', async ({ page }) => {
  await seedOnboarded(page, { canUse: true, tripId: null });
  await page.goto('/subscription/confirmed?price=%241.99&emailed=1');

  await expect(page.getByText('Your month is active')).toBeVisible();
  await expect(page.getByText(/confirmation is on its way to your email/i)).toBeVisible();
  /* The store's price from the paywall, not the fixed fallback. */
  await expect(page.getByText('$1.99 for one month')).toBeVisible();

  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page).toHaveURL(/\/onboarding\/ready/);
});

/* A restore of a month already confirmed sends no receipt, so none is promised. */
test('subscription confirmation promises no email when none was queued', async ({ page }) => {
  await seedOnboarded(page, { canUse: true, tripId: null });
  await page.goto('/subscription/confirmed?emailed=0');

  await expect(page.getByText('Your month is active')).toBeVisible();
  await expect(page.getByText(/confirmation is on its way/i)).toHaveCount(0);
  await expect(page.getByText('£1.99 for one month')).toBeVisible();
});
