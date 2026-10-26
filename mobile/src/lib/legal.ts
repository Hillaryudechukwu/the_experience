import { Platform } from 'react-native';

import { API_URL } from '../api/client';

/**
 * The pages the stores require, and the page that stops the charges.
 *
 * Both listings carry these URLs, and a listing URL that breaks because the
 * backend moved host is a compliance problem rather than an inconvenience —
 * so the public address wins when a build has been given one, and the copy
 * served beside the API is the fallback for development.
 */
const origin = () => API_URL.replace(/\/api\/?$/, '');

export const PRIVACY_POLICY_URL =
  process.env.EXPO_PUBLIC_PRIVACY_URL ?? `${origin()}/legal/privacy`;

export const TERMS_URL = process.env.EXPO_PUBLIC_TERMS_URL ?? `${origin()}/legal/terms`;

/**
 * Where a subscription is actually stopped.
 *
 * Not in this app, and not on our servers: the subscription belongs to the
 * store account that pays for it. Deleting a WAYOA account leaves it running,
 * which is the single most expensive thing a traveller can misunderstand, so
 * every screen that ends an account has to send them here as well.
 */
export const SUBSCRIPTION_SETTINGS_URL = Platform.select({
  ios: 'https://apps.apple.com/account/subscriptions',
  android: 'https://play.google.com/store/account/subscriptions',
  default: 'https://play.google.com/store/account/subscriptions',
});

/** What to call the place we are sending them, on the platform they are on. */
export const STORE_NAME = Platform.select({
  ios: 'Apple',
  android: 'Google Play',
  default: 'App Store or Google Play',
});
