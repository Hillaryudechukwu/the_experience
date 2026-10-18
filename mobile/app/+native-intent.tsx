/**
 * Google's installed-app sign-in returns to a custom-scheme path
 * (`oauth2redirect`, and the older `oauth2callback` / `oauthredirect`).
 * That URL carries the auth code. It is not a screen.
 *
 * Expo Router otherwise matches it as a route, and the unmatched page
 * (`interlude://oauth2redirect?...`) covers the sign-in that the auth
 * session is still finishing. Returning null leaves the app where it was.
 */
const OAUTH_RETURN = /(?:^|[/])oauth2?redirect(?:[/?#]|$)|(?:^|[/])oauth2callback(?:[/?#]|$)/;

export function redirectSystemPath({
  path,
}: {
  path: string;
  initial: boolean;
}): string | null {
  try {
    if (OAUTH_RETURN.test(path)) return null;

    return path;
  } catch {
    return path;
  }
}
