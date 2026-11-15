import { expect, test } from '@playwright/test';

/* The link carries a signature, not a hash of the address; the screen has to
   hand all three fields to the API untouched. */
test('opening a confirmation link posts its signed fields and confirms', async ({ page }) => {
  let posted: unknown = null;

  await page.route('**/api/**', async (route) => {
    if (route.request().method() === 'OPTIONS') {
      await route.fulfill({ status: 204 });
      return;
    }

    const path = new URL(route.request().url()).pathname.replace(/^\/api/, '');

    if (path === '/auth/email/verify') {
      posted = route.request().postDataJSON();
      await route.fulfill({
        json: {
          status: 'verified',
          user: { id: 7, name: 'Ada', email: 'ada@example.com', email_verified: true, providers: [] },
        },
      });
      return;
    }

    await route.fulfill({ json: { data: null } });
  });

  await page.goto('/account/verify-email?id=7&expires=1900000000&signature=abc123');

  await expect(page.getByText('Email confirmed')).toBeVisible();
  expect(posted).toEqual({ id: 7, expires: 1900000000, signature: 'abc123' });
});
