import React, { useState } from 'react';
import { TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { Button, Card, Chip, Row, Screen, T } from '../../src/components/primitives';
import { FAMILIARITY, JOURNEY_REASONS } from '../../src/lib/reasons';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';
import { useOnboardingDraft } from '../../src/store/draft';

/** Spec s3.1: the question that changes everything downstream. */
export default function Purpose() {
  const colors = useTheme();
  const router = useRouter();
  const destinationName = useSession((s) => s.destinationName);
  const draft = useOnboardingDraft();
  const [showAll, setShowAll] = useState(false);

  const visible = showAll ? JOURNEY_REASONS : JOURNEY_REASONS.slice(0, 8);

  return (
    <Screen>
      <View style={{ gap: space.sm, marginTop: space.lg }}>
        <T variant="label" color={colors.accent}>
          {destinationName ?? 'Your trip'}
        </T>
        <T variant="display">What brings you here?</T>
        <T variant="body" color={colors.inkMuted}>
          The same city is a different place on a business trip and on an anniversary. This is the single
          biggest thing we use.
        </T>
      </View>

      <View style={{ gap: space.sm, marginTop: space.xl }}>
        {visible.map((reason) => {
          const selected = draft.reason === reason.key;

          return (
            <Card key={reason.key} onPress={() => draft.set({ reason: reason.key })}>
              <View
                style={{
                  padding: space.lg,
                  gap: 2,
                  borderLeftWidth: 3,
                  borderLeftColor: selected ? colors.accent : 'transparent',
                }}
              >
                <T variant="bodyStrong" color={selected ? colors.accent : colors.ink}>
                  {reason.label}
                </T>
                <T variant="small" color={colors.inkMuted}>
                  {reason.hint}
                </T>
              </View>
            </Card>
          );
        })}

        {!showAll && (
          <Button label="Show every reason" tone="ghost" onPress={() => setShowAll(true)} />
        )}
      </View>

      <T variant="label" color={colors.inkFaint} style={{ marginTop: space.xl, marginBottom: space.sm }}>
        How well do you know it?
      </T>
      <Row gap={space.sm} wrap>
        {FAMILIARITY.map((option) => (
          <Chip
            key={option.key}
            label={option.label}
            selected={draft.familiarity === option.key}
            onPress={() => draft.set({ familiarity: option.key })}
          />
        ))}
      </Row>

      <T variant="label" color={colors.inkFaint} style={{ marginTop: space.xl, marginBottom: space.sm }}>
        Optional: what would make this trip worth it?
      </T>
      <TextInput
        placeholder="I'm here for meetings, but it's my first time and I don't want to only see hotel rooms."
        placeholderTextColor={colors.inkFaint}
        value={draft.mission}
        onChangeText={(mission) => draft.set({ mission })}
        multiline
        style={{
          backgroundColor: colors.surface,
          borderColor: colors.line,
          borderWidth: 1,
          borderRadius: radius.md,
          padding: space.lg,
          minHeight: 96,
          color: colors.ink,
          fontSize: 15,
          textAlignVertical: 'top',
        }}
      />
      <T variant="small" color={colors.inkFaint} style={{ marginTop: space.sm }}>
        We turn this into preferences that nudge what we suggest. It never overrides your bookings, opening
        times or anything you have asked us to avoid.
      </T>

      <Button
        label="Continue"
        onPress={() => router.push('/onboarding/interests')}
        disabled={!draft.reason}
        style={{ marginTop: space.xl }}
      />
    </Screen>
  );
}
