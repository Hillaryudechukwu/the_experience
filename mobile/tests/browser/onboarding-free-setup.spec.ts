import { expect, test, type Page } from '@playwright/test';

const SESSION_KEY = 'experience.session';
const GUEST_TOKEN_KEY = 'experience.guest_token';
const DESTINATION_TZ = 'Europe/London';

const MONTHS = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
] as const;

/** Match DateRangeCalendar / isoDate: YYYY-MM-DD in the destination zone. */
function londonToday(): string {
  return new Date().toLocaleDateString('en-CA', { timeZone: DESTINATION_TZ });
}

function addCalendarDays(iso: string, days: number): string {
  const [year, month, day] = iso.split('-').map(Number);
  const date = new Date(year, month - 1, day);
  date.setDate(date.getDate() + days);
  return date.toLocaleDateString('en-CA');
}

function dayButtonName(iso: string): string {
  const [, month, day] = iso.split('-').map(Number);
  return `${day} ${MONTHS[month - 1]}`;
}

function monthYearLabel(iso: string): string {
  const [year, month] = iso.split('-').map(Number);
  return `${MONTHS[month - 1]} ${year}`;
}

/** Calendar opens on minDate's month; advance if the pick spills into the next. */
async function pickCalendarDay(page: Page, iso: string) {
  const target = monthYearLabel(iso);
  for (let i = 0; i < 3; i++) {
    if (await page.getByText(target, { exact: true }).isVisible()) break;
    await page.getByRole('button', { name: 'Next month' }).click();
  }
  await expect(page.getByText(target, { exact: true })).toBeVisible();
  await page.getByRole('button', { name: dayButtonName(iso) }).click();
}

/**
 * Setup is free under the paywall: finishing onboarding may create a journey
 * but must not POST /trips (that route is subscribed).
 */
test('onboarding finish creates a journey and never a trip', async ({ page }) => {
  const writes: string[] = [];
  /* Three days out keeps the range after minDate (today) whenever CI runs. */
  const startsOn = addCalendarDays(londonToday(), 3);
  const endsOn = addCalendarDays(startsOn, 2);

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
            starts_on: startsOn,
            ends_on: endsOn,
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
            starts_on: startsOn,
            ends_on: endsOn,
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
  await pickCalendarDay(page, startsOn);
  await pickCalendarDay(page, endsOn);
  await page.getByRole('button', { name: 'Show me what is worth doing' }).click();

  await expect(page).toHaveURL(/\/onboarding\/ready/, { timeout: 15_000 });
  await expect(page.getByRole('button', { name: 'See what a month unlocks' })).toBeVisible();

  expect(writes.filter((w) => w === 'POST /trips')).toEqual([]);
  expect(writes).toContain('POST /journeys');
  expect(writes).toContain('PATCH /traveller/profile');
});
