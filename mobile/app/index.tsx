import React from 'react';
import { Redirect } from 'expo-router';

import { useEntitlement } from '../src/api/hooks';
import { Loading, Screen } from '../src/components/primitives';
import { useSession } from '../src/store/session';

export default function Gate() {
  const { ready, onboarded } = useSession();
  const { data: entitlement, isLoading } = useEntitlement();

  /*
   * Onboarding is free, so it is decided first and without waiting on the
   * entitlement — somebody who has not set a destination has nothing to buy
   * a month for.
   */
  if (ready && !onboarded) {
    return <Redirect href="/onboarding" />;
  }

  /* Never guess. Showing the tabs and snatching them back on a 402, or
     showing a paywall to somebody who has paid, are both worse than a
     moment of nothing. */
  if (!ready || isLoading) {
    return (
      <Screen scroll={false} contentStyle={{ justifyContent: 'center' }}>
        <Loading />
      </Screen>
    );
  }

  return <Redirect href={entitlement?.can_use ? '/(tabs)' : '/paywall'} />;
}
