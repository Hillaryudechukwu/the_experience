import { expect, test, settle } from './fixtures';

test('city search narrows the destination list using the API', async ({ seeded: page }) => {
  await settle(page, '/onboarding/destination');
  await page.getByPlaceholder('Search cities').fill('Tokyo');

  await expect(page.getByRole('button', { name: 'Choose Tokyo' })).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole('button', { name: 'Choose London' })).not.toBeVisible();
});

/**
 * Functional search against the real local API.
 *
 * The location/weather suite mocks this endpoint to inspect its request body.
 * That cannot prove a real discovery card survives the API presenter, network
 * client and React Native Web renderer. This test deliberately keeps all of
 * those layers in the path.
 */
test('typing a place name returns and renders the matching experience', async ({ seeded: page }) => {
  const responses: number[] = [];
  page.on('response', (response) => {
    if (response.url().includes('/api/discovery/search')) responses.push(response.status());
  });

  await settle(page, '/search');
  await page.getByPlaceholder('What do you feel like doing?').fill('British Museum');

  await expect(page.getByText('British Museum', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
  await expect(page.getByText(/experience(?:s)? fit this/i)).toBeVisible();
  expect(responses).toContain(200);
});

test('a failed search explains the problem and can be retried', async ({ seeded: page }) => {
  let unavailable = true;

  await page.route('**/api/discovery/search', async (route) => {
    if (!unavailable) {
      await route.fallback();
      return;
    }

    await route.fulfill({
      status: 503,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Search is temporarily unavailable.' }),
      headers: { 'access-control-allow-origin': '*' },
    });
  });

  await settle(page, '/search');
  await page.getByPlaceholder('What do you feel like doing?').fill('British Museum');

  await expect(page.getByText('Search did not load')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText('Search is temporarily unavailable.')).toBeVisible();
  await expect(page.getByText('Nothing strong fits all your filters')).not.toBeVisible();

  unavailable = false;
  await page.getByRole('button', { name: 'Try again' }).click();

  await expect(page.getByText('British Museum', { exact: true }).first()).toBeVisible({ timeout: 20_000 });
  await expect(page.getByText('Search did not load')).not.toBeVisible();
});
