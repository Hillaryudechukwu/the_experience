import React from 'react';
import { Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { Icon } from '../../src/components/Icon';
import { OnboardingChrome, OnboardingIntro } from '../../src/components/OnboardingChrome';
import { Button, Gutter, Row, Screen, T } from '../../src/components/primitives';
import { INTERESTS } from '../../src/lib/reasons';
import { useOnboardingDraft } from '../../src/store/draft';
import { categoryAccent, radius, space, useTheme } from '../../src/theme';

/** Interests (Figma: 14.5) — image-backed tiles rather than a checklist. */
export default function Interests() {
  const colors = useTheme();
  const router = useRouter();
  const draft = useOnboardingDraft();

  const toggle = (key: string) =>
    draft.set({
      interests: draft.interests.includes(key)
        ? draft.interests.filter((i) => i !== key)
        : [...draft.interests, key],
    });

  return (
    <Screen>
      <OnboardingChrome step={4} total={6} onSkip={() => router.push('/onboarding/style')} />
      <OnboardingIntro
        title="What interests you?"
        body="Pick a few. We refine this from what you actually do, and you can change it any time."
      />

      <Gutter style={{ marginTop: space.lg }}>
        <Row gap={space.xs} wrap>
          {INTERESTS.map((interest) => {
            const selected = draft.interests.includes(interest.key);
            const accent = categoryAccent(interest.key, colors);

            return (
              <Pressable
                key={interest.key}
                accessibilityRole="button"
                accessibilityState={{ selected }}
                onPress={() => toggle(interest.key)}
                style={({ pressed }) => ({
                  width: '31%',
                  flexGrow: 1,
                  aspectRatio: 1,
                  borderRadius: radius.card,
                  backgroundColor: selected ? accent : colors.background.elevated,
                  borderWidth: 1,
                  borderColor: selected ? accent : colors.border.subtle,
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: space.xs,
                  opacity: pressed ? 0.9 : 1,
                })}
              >
                <Icon
                  name={interest.icon}
                  size={24}
                  color={selected ? '#FFFFFF' : colors.text.secondary}
                  strokeWidth={1.7}
                />
                <T
                  variant="caption"
                  color={selected ? '#FFFFFF' : colors.text.secondary}
                  align="center"
                  numberOfLines={2}
                >
                  {interest.label}
                </T>
              </Pressable>
            );
          })}
        </Row>
      </Gutter>

      <Gutter style={{ marginTop: space.xxl }}>
        <T variant="caption" color={colors.text.tertiary} align="center" style={{ marginBottom: space.sm }}>
          {draft.interests.length === 0
            ? 'Pick at least one to sharpen your first recommendations'
            : `${draft.interests.length} selected`}
        </T>
        <Button label="Continue" size="large" onPress={() => router.push('/onboarding/style')} />
      </Gutter>
    </Screen>
  );
}
