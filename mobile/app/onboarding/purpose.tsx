import React, { useState } from 'react';
import { Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { Icon } from '../../src/components/Icon';
import { OnboardingChrome, OnboardingIntro } from '../../src/components/OnboardingChrome';
import { Button, Chip, Gutter, Row, Screen, SectionHeader, T } from '../../src/components/primitives';
import { FAMILIARITY, JOURNEY_REASONS, MORE_REASONS } from '../../src/lib/reasons';
import { useOnboardingDraft } from '../../src/store/draft';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

/**
 * Journey purpose (Figma: 14.3).
 *
 * The single biggest input the engine has, so it gets a full screen of
 * expressive cards rather than a line in a form. The same city is a different
 * place on a business trip and on an anniversary.
 */
export default function Purpose() {
  const colors = useTheme();
  const router = useRouter();
  const destinationName = useSession((s) => s.destinationName);
  const draft = useOnboardingDraft();
  const [showAll, setShowAll] = useState(false);

  const options = showAll ? [...JOURNEY_REASONS, ...MORE_REASONS] : JOURNEY_REASONS;

  return (
    <Screen>
      <OnboardingChrome step={2} total={6} onSkip={() => router.push('/onboarding/mission')} />
      <OnboardingIntro
        eyebrow={destinationName ?? undefined}
        title="What brings you here?"
        body="This changes everything we suggest — it is the single biggest thing we use."
      />

      <Gutter style={{ marginTop: space.lg }}>
        <Row gap={space.xs} wrap>
          {options.map((reason) => {
            const selected = draft.reason === reason.key;

            return (
              <Pressable
                key={reason.key}
                accessibilityRole="button"
                accessibilityState={{ selected }}
                onPress={() => draft.set({ reason: reason.key })}
                style={({ pressed }) => ({
                  width: '48%',
                  flexGrow: 1,
                  minHeight: 116,
                  padding: space.md,
                  borderRadius: radius.card,
                  backgroundColor: selected ? colors.action.primary : colors.background.elevated,
                  borderWidth: 1,
                  borderColor: selected ? colors.action.primary : colors.border.subtle,
                  gap: space.xs,
                  justifyContent: 'space-between',
                  opacity: pressed ? 0.9 : 1,
                })}
              >
                <Icon
                  name={reason.icon}
                  size={22}
                  color={selected ? colors.text.onAccent : colors.text.secondary}
                  strokeWidth={1.8}
                />
                <View style={{ gap: 2 }}>
                  <T variant="bodyStrong" color={selected ? colors.text.onAccent : colors.text.primary}>
                    {reason.label}
                  </T>
                  <T
                    variant="caption"
                    color={selected ? 'rgba(255,255,255,0.8)' : colors.text.tertiary}
                    numberOfLines={2}
                  >
                    {reason.hint}
                  </T>
                </View>
              </Pressable>
            );
          })}
        </Row>

        {!showAll ? (
          <Button label="Show every reason" tone="tertiary" onPress={() => setShowAll(true)} style={{ marginTop: space.sm }} />
        ) : null}
      </Gutter>

      <Gutter>
        <SectionHeader title="How well do you know it?" />
        <Row gap={space.xs} wrap>
          {FAMILIARITY.map((option) => (
            <Chip
              key={option.key}
              label={option.label}
              selected={draft.familiarity === option.key}
              onPress={() => draft.set({ familiarity: option.key })}
            />
          ))}
        </Row>
      </Gutter>

      <Gutter style={{ marginTop: space.xxl }}>
        <Button
          label="Continue"
          size="large"
          onPress={() => router.push('/onboarding/mission')}
          disabled={!draft.reason}
        />
      </Gutter>
    </Screen>
  );
}
