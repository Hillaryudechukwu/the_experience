import { Platform, type ImageSourcePropType } from 'react-native';

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
 */
export const IMAGE_USER_AGENT = `${BRAND.name}/1.0 (+https://experience.synteric.co.uk)`;

/**
 * The shape differs by platform, and it has to.
 *
 * Android only forwards headers from an **array** source. Image.android.js
 * lifts `headers` into a native prop in its `Array.isArray(source)` branch and
 * nowhere else; the single-object branch destructures `{uri, width, height}`
 * and drops the rest. On the native side ReactImageView.setSource reads only
 * `uri` and `cache` from each entry, and the one path to a header is the field
 * set by the `headers` ReactProp — the prop that branch never fills. Passing
 * `{uri, headers}` there is silently a plain request, which is how the first
 * attempt at this fix shipped doing nothing at all.
 *
 * The web build is the mirror image: react-native-web's `resolveAssetUri`
 * handles a number, a string or `source.uri`, with no array case, so an array
 * resolves to no URI and every photograph vanishes. Its `getAssetSize` guards
 * with `!Array.isArray(source)` for the same reason.
 *
 * iOS accepts either and does not need the header, its own agent being one
 * Wikimedia serves. So the array is used where it is required and nowhere
 * else, rather than uniformly in a way that would trade one broken platform
 * for another.
 */
export function remoteImage(uri: string): ImageSourcePropType {
  const source = { uri, headers: { 'User-Agent': IMAGE_USER_AGENT } };

  return Platform.OS === 'android' ? [source] : source;
}
