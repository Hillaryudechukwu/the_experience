import type { ImageURISource } from 'react-native';

import { BRAND } from '../brand';

/**
 * Remote photographs, fetched with an agent that identifies us.
 *
 * Almost every photograph in this app comes from Wikimedia, and Wikimedia
 * enforces a User-Agent policy: requests from generic library agents are
 * answered with 403. React Native fetches images through OkHttp on Android,
 * whose default agent is `okhttp/<version>` — one of the agents it refuses.
 * iOS asks through NSURLSession and the web build through the browser, both of
 * which send agents Wikimedia serves, so every Wikimedia photograph in the app
 * was failing on Android alone while the other two platforms looked fine.
 *
 * Verified against the live image host: `okhttp/4.12.0` and an empty agent
 * both return 403; this string, a browser's, and iOS's all return 200.
 *
 * The API has always done this for its own calls to the same host — see
 * `experience.http.user_agent`. This is the client catching up, not a new
 * policy.
 *
 * Headers on an image source are honoured by Android and iOS and ignored by
 * the web build, which already sends an agent of its own.
 */
export const IMAGE_USER_AGENT = `${BRAND.name}/1.0 (+https://experience.synteric.co.uk)`;

export function remoteImage(uri: string): ImageURISource {
  return { uri, headers: { 'User-Agent': IMAGE_USER_AGENT } };
}
