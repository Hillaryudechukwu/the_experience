import { useCallback } from 'react';
import { useRouter, type Href } from 'expo-router';

/**
 * Back, with somewhere to land.
 *
 * Every back control in the app called router.back() unconditionally, which
 * does nothing when the screen is the first entry in the stack — React
 * Navigation warns "the action GO_BACK was not handled by any navigator" and
 * the button silently fails.
 *
 * That stopped being hypothetical when the app started claiming
 * https://experience.synteric.co.uk/experience/... as an App Link. A traveller
 * opening a shared experience arrives on the detail screen with nothing behind
 * it, and the one control in the corner does not work. The same is true of any
 * route opened directly on the web.
 *
 * `replace` rather than `push` for the fallback: there is no history to
 * preserve, and pushing would leave a stack whose back button has the same
 * problem one screen later.
 */
export function useBackTo(fallback: Href = '/(tabs)'): () => void {
  const router = useRouter();

  return useCallback(() => {
    if (router.canGoBack()) {
      router.back();

      return;
    }

    router.replace(fallback);
  }, [router, fallback]);
}
