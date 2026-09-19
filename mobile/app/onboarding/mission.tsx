import React from 'react';
import { TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { Icon } from '../../src/components/Icon';
import { OnboardingChrome, OnboardingIntro } from '../../src/components/OnboardingChrome';
import { Button, Chip, Gutter, Note, Row, Screen, T } from '../../src/components/primitives';
import { MISSION_SUGGESTIONS } from '../../src/lib/reasons';
import { useOnboardingDraft } from '../../src/store/draft';
import { radius, space, useTheme } from '../../src/theme';

/**
 * Journey mission (Figma: 14.4).
 *
 * One optional question that captures what the trip is actually for. The note
 * underneath is not boilerplate — it is the promise that this text biases
 * suggestions and never overrides a booking, a closing time or an exclusion.
 */
export default function Mission() {
  const colors = useTheme();
  const router = useRouter();
  const draft = useOnboardingDraft();

  return (
    <Screen>
      <OnboardingChrome step={3} total={6} onSkip={() => router.push('/onboarding/interests')} />
      <OnboardingIntro
        title="What would make this trip feel worth it?"
        body="Optional, and one sentence is plenty."
      />

      <Gutter style={{ marginTop: space.lg }}>
        <TextInput
          placeholder="I'm here for work, but I don't want to leave without experiencing the city."
          placeholderTextColor={colors.text.tertiary}
          value={draft.mission}
          onChangeText={(mission) => draft.set({ mission })}
          multiline
          style={{
            backgroundColor: colors.background.elevated,
            borderColor: colors.border.subtle,
            borderWidth: 1,
            borderRadius: radius.card,
            padding: space.md,
            minHeight: 124,
            color: colors.text.primary,
            fontFamily: 'Inter_400Regular',
            fontSize: 16,
            lineHeight: 24,
            textAlignVertical: 'top',
          }}
        />

        <View style={{ marginTop: space.md, gap: space.xs }}>
          <T variant="label" color={colors.text.tertiary}>
            Or start from one of these
          </T>
          <Row gap={space.xs} wrap>
            {MISSION_SUGGESTIONS.map((suggestion) => (
              <Chip key={suggestion} label={suggestion} size="small" onPress={() => draft.set({ mission: suggestion })} />
            ))}
          </Row>
        </View>

        <View style={{ marginTop: space.lg }}>
          <Note icon={<Icon name="info" size={16} color={colors.text.secondary} />}>
            We turn this into preferences that nudge what we suggest. It never overrides your bookings, opening
            times or anything you have asked us to avoid.
          </Note>
        </View>
      </Gutter>

      <Gutter style={{ marginTop: space.xxl }}>
        <Button label="Continue" size="large" onPress={() => router.push('/onboarding/interests')} />
      </Gutter>
    </Screen>
  );
}
