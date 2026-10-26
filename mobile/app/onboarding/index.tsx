import React from 'react';
import { View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { useRouter } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';

import { BRAND } from '../../src/brand';
import { WayoaLogo } from '../../src/components/WayoaLogo';
import { Button, Gutter, T } from '../../src/components/primitives';
import { space } from '../../src/theme';

/**
 * Welcome (Figma: 14.1).
 *
 * One promise, one action. Nothing here needs an account, so the second
 * button is for travellers who already have one: skipping straight to the
 * tabs without a city left them on a screen that could only ask for one.
 */
export default function Welcome() {
  const router = useRouter();

  return (
    <View style={{ flex: 1, backgroundColor: '#0D1B2A' }}>
      <LinearGradient
        colors={['#0D1B2A', '#16304F', '#0D1B2A']}
        start={{ x: 0.1, y: 0 }}
        end={{ x: 0.9, y: 1 }}
        style={{ flex: 1 }}
      >
        <SafeAreaView style={{ flex: 1, justifyContent: 'space-between' }}>
          <Gutter style={{ paddingTop: space.xxl }}>
            <WayoaLogo variant="full" size="lg" />
          </Gutter>

          <Gutter style={{ gap: space.md }}>
            <T variant="displayXl" color="#FFFFFF">
              {BRAND.tagline}
            </T>
            <T variant="bodyL" color="rgba(255,255,255,0.82)">
              Land anywhere and know what is worth doing, why it suits you, and whether it fits the time you
              actually have.
            </T>
          </Gutter>

          <Gutter style={{ paddingBottom: space.xl, gap: space.sm }}>
            <Button
              label="Start exploring"
              size="large"
              tone="onAccent"
              onPress={() => router.push('/onboarding/destination')}
              haptic="medium"
            />
            <Button
              label="I already have an account"
              tone="onAccentGhost"
              onPress={() => router.push('/account/login')}
              style={{ alignSelf: 'center' }}
            />
          </Gutter>
        </SafeAreaView>
      </LinearGradient>
    </View>
  );
}
