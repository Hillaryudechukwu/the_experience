import React from 'react';
import { Pressable, View } from 'react-native';

import { space, useTheme } from '../theme';
import { Icon } from './Icon';
import { Gutter, Row, T } from './primitives';
import { useBackTo } from '../lib/navigation';

/** Shared onboarding header: progress, back, and an always-available skip. */
export function OnboardingChrome({
  step,
  total,
  onSkip,
}: {
  step: number;
  total: number;
  onSkip?: () => void;
}) {
  const colors = useTheme();
  const goBack = useBackTo('/onboarding');

  return (
    <Gutter style={{ paddingTop: space.xs }}>
      <Row justify="space-between">
        <Pressable
          accessibilityRole="button"
          onPress={goBack}
          hitSlop={12}
          accessibilityLabel="Back"
          style={({ pressed }) => ({ opacity: pressed ? 0.6 : 1, width: 32 })}
        >
          <Icon name="back" size={24} color={colors.text.primary} />
        </Pressable>

        <Row gap={5}>
          {Array.from({ length: total }).map((_, index) => (
            <View
              key={index}
              style={{
                width: index === step - 1 ? 20 : 7,
                height: 7,
                borderRadius: 4,
                backgroundColor: index < step ? colors.action.primary : colors.border.subtle,
              }}
            />
          ))}
        </Row>

        {onSkip ? (
          <Pressable onPress={onSkip} hitSlop={12} style={({ pressed }) => ({ opacity: pressed ? 0.6 : 1 })}>
            <T variant="smallStrong" color={colors.text.tertiary}>
              Skip
            </T>
          </Pressable>
        ) : (
          <View style={{ width: 32 }} />
        )}
      </Row>
    </Gutter>
  );
}

/** The title block every onboarding step opens with. */
export function OnboardingIntro({ eyebrow, title, body }: { eyebrow?: string; title: string; body?: string }) {
  const colors = useTheme();

  return (
    <Gutter style={{ marginTop: space.xl, gap: space.xs }}>
      {eyebrow ? (
        <T variant="label" color={colors.action.primary}>
          {eyebrow}
        </T>
      ) : null}
      <T variant="displayL">{title}</T>
      {body ? (
        <T variant="bodyL" color={colors.text.secondary}>
          {body}
        </T>
      ) : null}
    </Gutter>
  );
}
