import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'expo-router';

import { ApiError } from '../api/client';
import { keys } from '../api/hooks';

/**
 * A refused product request sends the traveller to the paywall (JEM §11 P4).
 *
 * The server is the authority, so the client can be wrong about access in
 * exactly one direction: it can believe a month is still running after it has
 * ended — a renewal that failed overnight, a refund, a device that was asleep
 * when the notification arrived. The first thing that tells us is a 402.
 *
 * Watching the query cache rather than wrapping every call means a route
 * added later is covered without anyone remembering to cover it.
 */
export function usePaywallOn402() {
  const client = useQueryClient();
  const router = useRouter();

  useEffect(() => {
    return client.getQueryCache().subscribe((event) => {
      if (event.type !== 'updated' || event.action.type !== 'error') return;

      const error = event.action.error;
      if (!(error instanceof ApiError) || error.status !== 402) return;

      /* Re-ask before deciding. The refusal is the server's, and the reason
         for it belongs on the paywall — pending, paused, or simply over. */
      void client.invalidateQueries({ queryKey: keys.entitlement() });
      router.replace('/paywall');
    });
  }, [client, router]);
}
