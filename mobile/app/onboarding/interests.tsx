import React, { useState } from 'react';
import { Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useCreateJourney, useCreateTrip, useUpdateProfile } from '../../src/api/hooks';
import { Button, Chip, Note, Row, Screen, T } from '../../src/components/primitives';
import { INTERESTS } from '../../src/lib/reasons';
import { useOnboardingDraft } from '../../src/store/draft';
import { useSession } from '../../src/store/session';
import { space, useTheme } from '../../src/theme';

/** The last step before the payoff: three taps, then recommendations. */
export default function Interests() {
  const colors = useTheme();
  const router = useRouter();
  const draft = useOnboardingDraft();
  const session = useSession();
  const [error, setError] = useState<string | null>(null);

  const updateProfile = useUpdateProfile();
  const createJourney = useCreateJourney();
  const createTrip = useCreateTrip();

  const busy = updateProfile.isPending || createJourney.isPending || createTrip.isPending;

  const toggle = (key: string) => {
    const interests = draft.interests.includes(key)
      ? draft.interests.filter((i) => i !== key)
      : [...draft.interests, key];

    draft.set({ interests });
  };

  const finish = async () => {
    setError(null);

    try {
      if (draft.interests.length > 0) {
        await updateProfile.mutateAsync({
          interests: Object.fromEntries(draft.interests.map((key) => [key, 85])),
        });
      }

      if (session.destinationId && draft.reason) {
        const journey = await createJourney.mutateAsync({
          destination_id: session.destinationId,
          reason: draft.reason,
          familiarity: draft.familiarity,
          adults: draft.adults,
          children: draft.children,
          ...(draft.mission.trim() ? { mission_text: draft.mission.trim() } : {}),
        });

        await session.setJourney(journey.id);

        const trip = await createTrip.mutateAsync(journey.id);
        await session.setTrip(trip.id);
      }

      await session.completeOnboarding();
      draft.clear();
      router.replace('/(tabs)');
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Something went wrong. Try again.');
    }
  };

  return (
    <Screen>
      <View style={{ gap: space.sm, marginTop: space.lg }}>
        <T variant="label" color={colors.accent}>
          Nearly there
        </T>
        <T variant="display">What interests you?</T>
        <T variant="body" color={colors.inkMuted}>
          Pick a few. We will refine this from what you actually do, and you can change it any time.
        </T>
      </View>

      <Row gap={space.sm} wrap style={{ marginTop: space.xl }}>
        {INTERESTS.map((interest) => (
          <Chip
            key={interest.key}
            label={interest.label}
            selected={draft.interests.includes(interest.key)}
            onPress={() => toggle(interest.key)}
            tone="accent"
          />
        ))}
      </Row>

      <T variant="label" color={colors.inkFaint} style={{ marginTop: space.xl, marginBottom: space.sm }}>
        Who is travelling?
      </T>
      <Row gap={space.lg}>
        <Counter label="Adults" value={draft.adults} min={1} onChange={(adults) => draft.set({ adults })} />
        <Counter label="Children" value={draft.children} min={0} onChange={(children) => draft.set({ children })} />
      </Row>

      {error && (
        <View style={{ marginTop: space.lg }}>
          <Note tone="warn">{error}</Note>
        </View>
      )}

      <Button
        label={busy ? 'Building your day…' : 'Show me what is worth doing'}
        onPress={finish}
        loading={busy}
        style={{ marginTop: space.xl }}
      />

      <Pressable onPress={finish} style={{ marginTop: space.md, alignItems: 'center' }}>
        <T variant="small" color={colors.inkFaint}>
          Skip — I will decide later
        </T>
      </Pressable>
    </Screen>
  );
}

function Counter({
  label,
  value,
  min,
  onChange,
}: {
  label: string;
  value: number;
  min: number;
  onChange: (value: number) => void;
}) {
  const colors = useTheme();

  return (
    <View style={{ gap: space.xs }}>
      <T variant="small" color={colors.inkMuted}>
        {label}
      </T>
      <Row gap={space.md}>
        <Stepper symbol="−" onPress={() => onChange(Math.max(min, value - 1))} />
        <T variant="heading">{value}</T>
        <Stepper symbol="+" onPress={() => onChange(Math.min(12, value + 1))} />
      </Row>
    </View>
  );
}

function Stepper({ symbol, onPress }: { symbol: string; onPress: () => void }) {
  const colors = useTheme();

  return (
    <Pressable
      onPress={onPress}
      hitSlop={8}
      style={({ pressed }) => ({
        width: 34,
        height: 34,
        borderRadius: 17,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: colors.surface,
        borderWidth: 1,
        borderColor: colors.line,
        opacity: pressed ? 0.7 : 1,
      })}
    >
      <T variant="bodyStrong" color={colors.ink}>
        {symbol}
      </T>
    </Pressable>
  );
}
