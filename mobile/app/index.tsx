import React from 'react';
import { Redirect } from 'expo-router';

import { Loading, Screen } from '../src/components/primitives';
import { useSession } from '../src/store/session';

export default function Gate() {
  const { ready, onboarded } = useSession();

  if (!ready) {
    return (
      <Screen scroll={false} contentStyle={{ justifyContent: 'center' }}>
        <Loading />
      </Screen>
    );
  }

  return <Redirect href={onboarded ? '/(tabs)' : '/onboarding'} />;
}
