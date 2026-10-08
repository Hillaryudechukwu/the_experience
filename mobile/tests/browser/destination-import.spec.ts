import { expect, test, type Page, type Route } from '@playwright/test';

const SESSION_KEY = 'experience.session';

const destination = {
  id: '11111111-1111-4111-8111-111111111111',
  slug: 'reykjavik-is',
  name: 'Reykjavík',
  timezone: 'Atlantic/Reykjavik',
  coverage_status: 'importing',
};

async function storedImport(page: Page, importId = 'import-1') {
  await page.addInitScript(
    ([key, value]) => window.localStorage.setItem(key, value),
    [
      SESSION_KEY,
      JSON.stringify({
        destinationSlug: null,
        destinationId: null,
        destinationName: null,
        destinationTimezone: null,
        destinationImportId: importId,
        journeyId: null,
        tripId: null,
        locationPrecision: 'precise',
        onboarded: false,
      }),
    ] as const,
  );
}

async function mockCatalogue(page: Page) {
  await page.route('**/api/destinations**', async (route) => {
    await route.fulfill({ json: { data: [], elsewhere: [] } });
  });
}

function progress(status: 'running' | 'partial' | 'failed', retryable = false) {
  return {
    data: {
      id: 'import-1',
      destination_id: destination.id,
      destination,
      status,
      stage: status === 'running' ? 'discovering_places' : status === 'partial' ? 'limited' : 'failed',
      records_seen: 4,
      places_created: 2,
      places_matched: 0,
      places_needing_review: 0,
      places_failed: 0,
      experiences_published: status === 'partial' ? 2 : 0,
      experiences_pending_content: 2,
      started_at: '2026-10-08T08:00:00Z',
      finished_at: status === 'running' ? null : '2026-10-08T08:01:00Z',
      retryable,
      message: status === 'running' ? 'Finding places worth considering.' : 'Preparation finished.',
      poll_after_seconds: status === 'running' ? 3 : null,
    },
  };
}

async function fulfillProgress(route: Route, status: 'running' | 'partial' | 'failed', retryable = false) {
  await route.fulfill({ json: progress(status, retryable) });
}

test('reopening the app resumes a running destination import', async ({ page }) => {
  await storedImport(page);
  await mockCatalogue(page);
  await page.route('**/api/destination-imports/import-1', (route) => fulfillProgress(route, 'running'));

  await page.goto('/onboarding/destination');

  await expect(page.getByText('Preparing Reykjavík')).toBeVisible();
  await expect(page.getByText('Finding places worth considering.')).toBeVisible();
});

test('limited coverage is stated honestly and can be continued', async ({ page }) => {
  await storedImport(page);
  await mockCatalogue(page);
  await page.route('**/api/destination-imports/import-1', (route) => fulfillProgress(route, 'partial'));
  await page.route('**/api/events', async (route) => route.fulfill({ json: { data: [] } }));

  await page.goto('/onboarding/destination');

  await expect(page.getByText(/coverage is still limited/i)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Continue with limited coverage' })).toBeVisible();
});

test('a retryable failure queues a new import and persists its id', async ({ page }) => {
  await storedImport(page);
  await mockCatalogue(page);
  await page.route('**/api/destination-imports/import-1', (route) => fulfillProgress(route, 'failed', true));
  await page.route('**/api/destination-imports/import-1/retry', async (route) => {
    await route.fulfill({
      status: 202,
      json: { data: { import_id: 'import-2', destination_id: destination.id, status: 'queued' } },
    });
  });
  await page.route('**/api/destination-imports/import-2', async (route) => {
    const body = progress('running');
    body.data.id = 'import-2';
    await route.fulfill({ json: body });
  });

  await page.goto('/onboarding/destination');
  await page.getByRole('button', { name: 'Try preparing again' }).click();

  await expect.poll(() => page.evaluate((key) => JSON.parse(localStorage.getItem(key) ?? '{}').destinationImportId, SESSION_KEY))
    .toBe('import-2');
  await expect(page.getByText('Preparing Reykjavík')).toBeVisible();
});
