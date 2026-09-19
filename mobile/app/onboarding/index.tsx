import React from 'react';
import { View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { useRouter } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Icon } from '../../src/components/Icon';
import { Button, Gutter, Row, T } from '../../src/components/primitives';
import { useSession } from '../../src/store/session';
import { space, useTheme } from '../../src/theme';

/**
 * Welcome (Figma: 14.1).
 *
 * One promise, one action. Continuing as a guest is offered with equal weight
 * because nothing here needs an account, and a sign-up wall before any value
 * has been shown is the fastest way to lose someone.
 */
export default function Welcome() {
  const colors = useTheme();
  const router = useRouter();
  const session = useSession();

  return (
    <View style={{ flex: 1, backgroundColor: '#0D1B2A' }}>
      <LinearGradient
        colors={['#123A8F', '#1B3FD4', '#0D1B2A']}
        start={{ x: 0.1, y: 0 }}
        end={{ x: 0.9, y: 1 }}
        style={{ flex: 1 }}
      >
        <SafeAreaView style={{ flex: 1, justifyContent: 'space-between' }}>
          <Gutter style={{ paddingTop: space.xxl }}>
            <Row gap={space.xs}>
              <Icon name="compass" size={26} color="#FFFFFF" strokeWidth={1.9} />
              <T variant="label" color="rgba(255,255,255,0.8)">
                The Experience
              </T>
            </Row>
          </Gutter>

          <Gutter style={{ gap: space.md }}>
            <T variant="displayXl" color="#FFFFFF">
              Don't just visit.{'\n'}Experience it.
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
              onPress={() => router.push('/onboarding/destination')}
              style={{ backgroundColor: '#FFFFFF' }}
              tone="secondary"
              haptic="medium"
            />
            <Button
              label="Continue as guest"
              tone="tertiary"
              onPress={async () => {
                await session.completeOnboarding();
                router.replace('/(tabs)');
              }}
              style={{ alignSelf: 'center' }}
            />
          </Gutter>
        </SafeAreaView>
      </LinearGradient>
    </View>
  );
}
