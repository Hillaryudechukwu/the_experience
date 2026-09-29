/**
 * Every screen the app can show, as a URL the router will accept.
 *
 * Kept as data rather than written into each test so that adding a screen
 * means adding one line here, and the checks that follow apply to it
 * automatically. A screen missing from this list is a screen nothing looks at.
 */
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
  '/account/delete',
  '/onboarding',
  '/onboarding/destination',
  '/onboarding/purpose',
  '/onboarding/interests',
  '/onboarding/style',
  '/onboarding/mission',
  '/onboarding/ready',
] as const;
