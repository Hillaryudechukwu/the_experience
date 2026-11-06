/**
 * Every screen the app can show, as a URL the router will accept.
 *
 * Kept as data rather than written into each test so that adding a screen
 * means adding one line here, and the checks that follow apply to it
 * automatically. A screen missing from this list is a screen nothing looks at.
 */
/** Three days out: never "too late tonight" whenever CI runs, and inside the forecast. */
export const DATE_NIGHT_DAY = new Date(Date.now() + 3 * 86_400_000).toISOString().slice(0, 10);

export const ROUTES = [
  '/',
  '/map',
  '/trip',
  '/bookings',
  '/you',
  '/search',
  '/guide',
  '/passport',
  '/essentials',
  '/anchors',
  '/paywall',
  '/subscription',
  '/understanding',
  '/account/delete',
  '/account/login',
  '/account/register',
  '/onboarding',
  '/onboarding/destination',
  '/onboarding/purpose',
  '/onboarding/interests',
  '/onboarding/style',
  '/onboarding/mission',
  '/onboarding/ready',
  '/date-night',
  `/date-night?date=${DATE_NIGHT_DAY}&starts_at=19:00`,
] as const;
