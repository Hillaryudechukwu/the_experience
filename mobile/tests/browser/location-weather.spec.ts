import { expect, test, type Page } from '@playwright/test';

const SESSION_KEY = 'experience.session';
const GUEST_TOKEN_KEY = 'experience.guest_token';

const london = {
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

const tokyo = {
  id: '22222222-2222-4222-8222-222222222222',
  slug: 'tokyo-jp',
  name: 'Tokyo',
  country: 'Japan',
  timezone: 'Asia/Tokyo',
  currency: 'JPY',
  lat: 35.6762,
  lng: 139.6503,
  summary: 'Tokyo',
  hero_image_url: null,
  hero_image_attribution: null,
  coverage_status: 'ready',
};

function destinationDetail(destination: typeof london) {
  return {
    ...destination,
    neighbourhoods: [],
    dont_leave_without: [],
    city_essentials: [],
  };
}

type DiscoveryCall = {
  path: string;
  body: Record<string, unknown>;
};

function discoveryResponse(temperature: number, precision: 'city' | 'precise') {
  return {
    data: [],
    primary: null,
    recommendation_set_id: 'recommendation-set-1',
    context: {
      local_time: '2026-10-19T18:00:00+09:00',
      location_precision: precision,
      window_minutes: null,
      weather: {
        condition: 'clear',
        temperature_c: temperature,
        precipitation_probability: 0,
        is_poor: false,
        clothing: { headline: 'Light layers', items: ['light layer'] },
        sunset: '2026-10-19T17:05:00+09:00',
        freshness: { source: 'test', observed_at: '2026-10-19T09:00:00Z' },
      },
      next_anchor: null,
      journey: null,
      engine_version: 'test',
    },
    candidates_considered: 0,
    catalogue_empty: false,
    notice: null,
    interpreted: { understood_as: [], filters: {} },
  };
}

async function seedSession(page: Page, session: Record<string, unknown>) {
  await page.addInitScript(
    ([sessionKey, guestKey, value]) => {
      if (!window.localStorage.getItem(sessionKey)) window.localStorage.setItem(sessionKey, value);
      if (!window.localStorage.getItem(guestKey)) {
        window.localStorage.setItem(guestKey, 'browser-location-test');
      }
    },
    [SESSION_KEY, GUEST_TOKEN_KEY, JSON.stringify(session)] as const,
  );
}

async function mockAppApi(
  page: Page,
  calls: DiscoveryCall[],
  options: { temperature: number; precision: 'city' | 'precise' },
) {
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname.replace(/^\/api/, '');

    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204 });
      return;
    }

    if (request.method() === 'POST' && /^\/discovery\/(now|time-boxed|mood|search)$/.test(path)) {
      calls.push({ path, body: request.postDataJSON() as Record<string, unknown> });
      await route.fulfill({ json: discoveryResponse(options.temperature, options.precision) });
      return;
    }

    if (path === '/discovery/intent') {
      await route.fulfill({ json: { data: {} } });
      return;
    }

    if (path === `/destinations/${tokyo.slug}`) {
      await route.fulfill({ json: { data: destinationDetail(tokyo) } });
      return;
    }

    if (path === `/destinations/${london.slug}`) {
      await route.fulfill({ json: { data: destinationDetail(london) } });
      return;
    }

    if (path === '/destinations') {
      await route.fulfill({ json: { data: [london, tokyo], elsewhere: [] } });
      return;
    }

    if (path === '/traveller/profile') {
      await route.fulfill({ json: { data: { display_name: null } } });
      return;
    }

    if (path === '/experiences/saved') {
      await route.fulfill({ json: { data: [] } });
      return;
    }

    await route.fulfill({ json: { data: null } });
  });
}

function baseSession(destination = london) {
  return {
    destinationSlug: destination.slug,
    destinationId: destination.id,
    destinationName: destination.name,
    destinationTimezone: destination.timezone,
    destinationImportId: null,
    journeyId: null,
    tripId: null,
    locationPrecision: 'precise',
    onboarded: true,
  };
}

function callsFor(calls: DiscoveryCall[], path: string) {
  return calls.filter((call) => call.path === path);
}

function expectDestinationOnly(call: DiscoveryCall) {
  expect(call.body.destination_id).toBe(tokyo.id);
  expect(call.body.destination).toBe(tokyo.slug);
  expect(call.body).not.toHaveProperty('lat');
  expect(call.body).not.toHaveProperty('lng');
  expect(call.body).not.toHaveProperty('location_precision');
}

/**
 * End-to-end regression for the weather defect.
 *
 * The rendered temperature alone is not enough: a client could display the
 * mocked value while still sending London coordinates for a Tokyo journey.
 * This follows the real city picker, checks the persisted transition, checks
 * every discovery request body, and finally checks what the traveller sees.
 */
test('a chosen city drives weather and every discovery surface, not cached device coordinates', async ({ page }) => {
  const calls: DiscoveryCall[] = [];
  await seedSession(page, {
    ...baseSession(london),
    coords: { lat: london.lat, lng: london.lng },
    coordsAt: Date.now(),
    locationSource: 'device',
  });
  await mockAppApi(page, calls, { temperature: 27, precision: 'city' });

  await page.goto('/onboarding/destination');
  await page.getByRole('button', { name: 'Choose Tokyo' }).click();

  await expect.poll(() => page.evaluate((key) => {
    const session = JSON.parse(window.localStorage.getItem(key) ?? '{}');
    return {
      destination: session.destinationSlug,
      source: session.locationSource,
      coords: session.coords,
    };
  }, SESSION_KEY)).toEqual({ destination: tokyo.slug, source: 'destination', coords: null });

  await page.goto('/');

  await expect(page.getByText('Tokyo', { exact: true })).toBeVisible();
  await expect(page.getByText('27°C', { exact: true })).toBeVisible();
  await expect(page.getByText('Light layers', { exact: true })).toBeVisible();

  await expect.poll(() => callsFor(calls, '/discovery/now').length).toBeGreaterThan(0);
  callsFor(calls, '/discovery/now').forEach(expectDestinationOnly);

  await page.getByRole('button', { name: '1 hour' }).click();
  await expect.poll(() => callsFor(calls, '/discovery/time-boxed').length).toBeGreaterThan(0);
  const timeBoxed = callsFor(calls, '/discovery/time-boxed').at(-1)!;
  expectDestinationOnly(timeBoxed);
  expect(timeBoxed.body.window_minutes).toBe(60);

  await page.getByLabel('Hungry, picture').click();
  await expect.poll(() => callsFor(calls, '/discovery/mood').length).toBeGreaterThan(0);
  const mood = callsFor(calls, '/discovery/mood').at(-1)!;
  expectDestinationOnly(mood);
  expect(mood.body.mood).toBe('hungry');

  await page.goto('/search');
  await page.getByPlaceholder('What do you feel like doing?').fill('rainy day museum');
  await expect.poll(() => callsFor(calls, '/discovery/search').length).toBeGreaterThan(0);
  const search = callsFor(calls, '/discovery/search').at(-1)!;
  expectDestinationOnly(search);
  expect(search.body.q).toBe('rainy day museum');
});

test('an explicit current-location session still sends device coordinates', async ({ page }) => {
  const calls: DiscoveryCall[] = [];
  await seedSession(page, {
    ...baseSession(tokyo),
    coords: { lat: 35.6812, lng: 139.7671 },
    coordsAt: Date.now(),
    locationSource: 'device',
  });
  await mockAppApi(page, calls, { temperature: 29, precision: 'precise' });

  await page.goto('/');

  await expect(page.getByText('29°C', { exact: true })).toBeVisible();
  await expect.poll(() => callsFor(calls, '/discovery/now').length).toBeGreaterThan(0);

  for (const call of callsFor(calls, '/discovery/now')) {
    expect(call.body).toMatchObject({
      destination_id: tokyo.id,
      destination: tokyo.slug,
      lat: 35.6812,
      lng: 139.7671,
      location_precision: 'precise',
    });
  }
});

test('an installed legacy session is migrated to selected-city weather', async ({ page }) => {
  const calls: DiscoveryCall[] = [];
  await seedSession(page, {
    ...baseSession(tokyo),
    /* Old builds persisted coordinates but had no locationSource field. */
    coords: { lat: london.lat, lng: london.lng },
    coordsAt: Date.now(),
  });
  await mockAppApi(page, calls, { temperature: 27, precision: 'city' });

  await page.goto('/');

  await expect(page.getByText('27°C', { exact: true })).toBeVisible();
  await expect.poll(() => callsFor(calls, '/discovery/now').length).toBeGreaterThan(0);
  callsFor(calls, '/discovery/now').forEach(expectDestinationOnly);

  const persisted = await page.evaluate((key) => JSON.parse(window.localStorage.getItem(key) ?? '{}'), SESSION_KEY);
  expect(persisted.locationSource).toBe('destination');
  expect(persisted.coords).toBeNull();
  expect(persisted.coordsAt).toBeNull();
});
