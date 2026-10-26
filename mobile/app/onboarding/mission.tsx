import React from 'react';
import { ActivityIndicator, Pressable, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useMissionSuggestions } from '../../src/api/hooks';
import { Icon } from '../../src/components/Icon';
import { OnboardingChrome, OnboardingIntro } from '../../src/components/OnboardingChrome';
import { Button, Gutter, Note, Row, Screen, T } from '../../src/components/primitives';
import { missionSuggestionsFor, reasonLabel } from '../../src/lib/reasons';
import { useOnboardingDraft } from '../../src/store/draft';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

/**
 * Journey mission (Figma: 14.4).
 *
 * Chips are shaped by the purpose chosen on the previous screen. The API
 * rewrites them with AI when Anthropic is configured; otherwise (and while
 * loading) we show the playbook fallback for that reason.
 *
 * Full-width rows (not tiny pills) — AI sentences are too long for chips.
 */
export default function Mission() {
  const colors = useTheme();
  const router = useRouter();
  const draft = useOnboardingDraft();
  const destinationName = useSession((s) => s.destinationName);
  const purpose = reasonLabel(draft.reason);

  const { data, isFetching, isError, refetch, isRefetching } = useMissionSuggestions(
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
        body={
          purpose
            ? `Optional — suggestions are tuned for ${purpose.toLowerCase()}.`
            : 'Optional, and one sentence is plenty.'
        }
      />

      <Gutter style={{ marginTop: space.lg }}>
        <TextInput
          accessibilityLabel="What would make this trip feel worth it"
          placeholder="In your own words, or pick one below."
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
            <T variant="label" color={colors.text.tertiary} style={{ flex: 1 }}>
              Or start from one of these
            </T>
            {(isFetching || isRefetching) && !data ? (
              <ActivityIndicator size="small" color={colors.text.tertiary} />
            ) : null}
          </Row>

          {isError ? (
            <Pressable
              onPress={() => refetch()}
              style={{
                padding: space.md,
                borderRadius: radius.card,
                borderWidth: 1,
                borderColor: colors.border.subtle,
                backgroundColor: colors.background.elevated,
                gap: space.xs,
              }}
            >
              <T variant="bodyStrong">Couldn’t load tailored suggestions</T>
              <T variant="caption" color={colors.text.tertiary}>
                Showing defaults for now. Tap to retry.
              </T>
            </Pressable>
          ) : null}

          <View style={{ gap: space.xs }}>
            {suggestions.map((suggestion) => {
              const selected = draft.mission === suggestion;

              return (
                <Pressable
                  key={suggestion}
                  accessibilityRole="button"
                  accessibilityState={{ selected }}
                  onPress={() => draft.set({ mission: suggestion })}
                  style={({ pressed }) => ({
                    paddingVertical: space.md,
                    paddingHorizontal: space.md,
                    borderRadius: radius.card,
                    borderWidth: 1,
                    borderColor: selected ? colors.action.primary : colors.border.subtle,
                    backgroundColor: selected ? colors.action.primary : colors.background.elevated,
                    opacity: pressed ? 0.9 : 1,
                  })}
                >
                  <T
                    variant="body"
                    color={selected ? colors.text.onAccent : colors.text.primary}
                  >
                    {suggestion}
                  </T>
                </Pressable>
              );
            })}
          </View>
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
