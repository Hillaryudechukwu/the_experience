import React from 'react';
import { ActivityIndicator, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useMissionSuggestions } from '../../src/api/hooks';
import { Icon } from '../../src/components/Icon';
import { OnboardingChrome, OnboardingIntro } from '../../src/components/OnboardingChrome';
import { Button, Chip, Gutter, Note, Row, Screen, T } from '../../src/components/primitives';
import { missionSuggestionsFor } from '../../src/lib/reasons';
import { useOnboardingDraft } from '../../src/store/draft';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

/**
 * Journey mission (Figma: 14.4).
 *
 * Chips are shaped by the purpose chosen on the previous screen. The API
 * rewrites them with AI when Anthropic is configured; otherwise (and while
 * loading) we show the playbook fallback for that reason.
 */
export default function Mission() {
  const colors = useTheme();
  const router = useRouter();
  const draft = useOnboardingDraft();
  const destinationName = useSession((s) => s.destinationName);

  const { data, isFetching } = useMissionSuggestions(
    draft.reason,
    destinationName,
    draft.familiarity,
  );

  const suggestions = data?.suggestions?.length
    ? data.suggestions
    : missionSuggestionsFor(draft.reason);

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
          <Row gap={space.xs}>
            <T variant="label" color={colors.text.tertiary}>
              Or start from one of these
            </T>
            {isFetching && !data ? (
              <ActivityIndicator size="small" color={colors.text.tertiary} />
            ) : null}
          </Row>
          <Row gap={space.xs} wrap>
            {suggestions.map((suggestion) => (
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
