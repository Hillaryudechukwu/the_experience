import { expect, test } from '@playwright/test';

const SESSION_KEY = 'experience.session';
const GUEST_TOKEN_KEY = 'experience.guest_token';

const destination = {
  id: '11111111-1111-4111-8111-111111111111',
  slug: 'london-gb',
  name: 'London',
  country: 'United Kingdom',
  timezone: 'Europe/London',
  currency: 'GBP',
  lat: 51.5074,
  lng: -0.1278,
  summary: 'London',
  hero_image_url: null,
  hero_image_attribution: null,
  coverage_status: 'ready',
};

const journey = {
  id: 'journey-1',
  title: null,
  reason: 'holiday',
  reason_label: 'Holiday',
  destination,
  starts_on: '2026-11-01',
  ends_on: '2026-11-03',
  adults: 1,
  children: 0,
  child_ages: [],
  familiarity: 'first_time',
  daily_budget_minor: null,
  currency: 'GBP',
  mission_text: null,
  mission_goals: [],
  must_do: [],
  avoid: [],
  accessibility_mode: false,
  anchors: [],
};

test('the first plan build uses the id of the trip it just created', async ({ page }) => {
  const writes: string[] = [];

  await page.addInitScript(
    ([sessionKey, guestKey]) => {
      window.localStorage.setItem(sessionKey, JSON.stringify({
        destinationSlug: 'london-gb',
        destinationId: '11111111-1111-4111-8111-111111111111',
        destinationName: 'London',
        destinationTimezone: 'Europe/London',
        destinationImportId: null,
        journeyId: 'journey-1',
        tripId: null,
        coords: null,
        coordsAt: null,
        locationPrecision: 'precise',
        locationSource: 'destination',
        onboarded: true,
      }));
      window.localStorage.setItem(guestKey, 'browser-trip-build-test');
    },
    [SESSION_KEY, GUEST_TOKEN_KEY] as const,
  );

  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace(/^\/api/, '');

    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204 });
      return;
    }

    if (request.method() === 'GET' && path === '/journeys/journey-1') {
      await route.fulfill({ json: { data: journey } });
      return;
    }

    if (request.method() === 'POST') writes.push(path);

    if (request.method() === 'POST' && path === '/trips') {
      await route.fulfill({
        status: 201,
        json: { data: { id: 'trip-1', title: 'London', status: 'draft', journey_id: journey.id, itinerary: null } },
      });
      return;
    }

    if (request.method() === 'POST' && path === '/trips/trip-1/generate-itinerary') {
      await route.fulfill({
        status: 201,
        json: {
          data: {
            id: 'itinerary-1',
            version: 1,
            is_current: true,
            generated_at: '2026-11-01T08:00:00Z',
            engine_version: 'test',
            objective_value: 0,
            days: [],
          },
        },
      });
      return;
    }

    await route.fulfill({ status: 404, json: { message: `Unexpected request: ${path}` } });
  });

  await page.goto('/trip');
  await page.getByRole('button', { name: 'Build my itinerary' }).click();

  await expect.poll(() => writes).toEqual([
    '/trips',
    '/trips/trip-1/generate-itinerary',
  ]);
  expect(writes).not.toContain('/trips/null/generate-itinerary');
});
