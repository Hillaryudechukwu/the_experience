import React, { useState } from 'react';
import { Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useCreateJourney, useCreateTrip, useUpdateProfile } from '../../src/api/hooks';
import { Icon } from '../../src/components/Icon';
import { OnboardingChrome, OnboardingIntro } from '../../src/components/OnboardingChrome';
import { Button, Gutter, Note, Row, Screen, SectionHeader, T } from '../../src/components/primitives';
import { stayBounds } from '../../src/lib/format';
import { useOnboardingDraft, type StayDays } from '../../src/store/draft';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

const PACE = [
  { key: 'slow', label: 'Slow', hint: '2 things a day' },
  { key: 'moderate', label: 'Balanced', hint: '3 things a day' },
  { key: 'fast', label: 'Packed', hint: '5 things a day' },
] as const;

const BUDGET = [
  { key: 'budget', label: 'Budget' },
  { key: 'moderate', label: 'Moderate' },
  { key: 'premium', label: 'Comfortable' },
  { key: 'luxury', label: 'Premium' },
] as const;

const TOURIST_STYLE = [
  { value: 85, label: 'Iconic first' },
  { value: 60, label: 'Balanced' },
  { value: 25, label: 'Mostly local' },
];

const STAY: { days: StayDays; label: string; hint: string }[] = [
  { days: 1, label: 'Just today', hint: 'One day' },
  { days: 3, label: 'A few days', hint: 'About 3 days' },
  { days: 7, label: 'About a week', hint: '7 days' },
  { days: 14, label: 'Up to two weeks', hint: '14 days' },
];

/** Travel style (Figma: 14.6), then everything is submitted at once. */
export default function TravelStyle() {
  const colors = useTheme();
  const router = useRouter();
  const draft = useOnboardingDraft();
  const session = useSession();
  const [error, setError] = useState<string | null>(null);

  const updateProfile = useUpdateProfile();
  const createJourney = useCreateJourney();
  const createTrip = useCreateTrip();

  const busy = updateProfile.isPending || createJourney.isPending || createTrip.isPending;

  const finish = async () => {
    setError(null);

    try {
      await updateProfile.mutateAsync({
        ...(draft.interests.length > 0
          ? { interests: Object.fromEntries(draft.interests.map((key) => [key, 85])) }
          : {}),
        travel_pace: draft.pace,
        budget_level: draft.budget,
        iconic_vs_local: draft.touristStyle,
      });

      if (session.destinationId && draft.reason) {
        const { starts_on, ends_on } = stayBounds(draft.stayDays);
        const journey = await createJourney.mutateAsync({
          destination_id: session.destinationId,
          reason: draft.reason,
          familiarity: draft.familiarity,
          adults: draft.adults,
          children: draft.children,
          starts_on,
          ends_on,
          ...(draft.mission.trim() ? { mission_text: draft.mission.trim() } : {}),
        });

        await session.setJourney(journey.id);
        const trip = await createTrip.mutateAsync(journey.id);
        await session.setTrip(trip.id);
      }

      await session.completeOnboarding();
      draft.clear();
      router.replace('/onboarding/ready');
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Something went wrong. Try again.');
    }
  };

  const finishLabel = busy
    ? draft.stayDays > 1
      ? 'Building your itinerary…'
      : 'Building your day…'
    : 'Show me what is worth doing';

  return (
    <Screen>
      <OnboardingChrome step={5} total={6} onSkip={finish} />
      <OnboardingIntro title="How do you like to travel?" body="You can change any of this later." />

      <Gutter>
        <SectionHeader title="Pace" />
        <Row gap={space.xs}>
          {PACE.map((option) => (
            <Choice
              key={option.key}
              label={option.label}
              hint={option.hint}
              selected={draft.pace === option.key}
              onPress={() => draft.set({ pace: option.key })}
            />
          ))}
        </Row>

        <SectionHeader title="Budget for experiences" />
        <Row gap={space.xs} wrap>
          {BUDGET.map((option) => (
            <Choice
              key={option.key}
              label={option.label}
              selected={draft.budget === option.key}
              onPress={() => draft.set({ budget: option.key })}
            />
          ))}
        </Row>

        <SectionHeader title="Iconic or local?" />
        <Row gap={space.xs}>
          {TOURIST_STYLE.map((option) => (
            <Choice
              key={option.value}
              label={option.label}
              selected={draft.touristStyle === option.value}
              onPress={() => draft.set({ touristStyle: option.value })}
            />
          ))}
        </Row>

        <SectionHeader title="How long are you staying?" caption="Shapes how many days we plan" />
        <Row gap={space.xs} wrap>
          {STAY.map((option) => (
            <Choice
              key={option.days}
              label={option.label}
              hint={option.hint}
              selected={draft.stayDays === option.days}
              onPress={() => draft.set({ stayDays: option.days })}
            />
          ))}
        </Row>

        <SectionHeader title="Who is travelling?" />
        <Row gap={space.xl}>
          <Counter label="Adults" value={draft.adults} min={1} onChange={(adults) => draft.set({ adults })} />
          <Counter label="Children" value={draft.children} min={0} onChange={(children) => draft.set({ children })} />
        </Row>
      </Gutter>

      {error ? (
        <Gutter style={{ marginTop: space.lg }}>
          <Note tone="warning">{error}</Note>
        </Gutter>
      ) : null}

      <Gutter style={{ marginTop: space.xxl }}>
        <Button
          label={finishLabel}
          size="large"
          onPress={finish}
          loading={busy}
          haptic="medium"
        />
      </Gutter>
    </Screen>
  );
}

function Choice({
  label,
  hint,
  selected,
  onPress,
}: {
  label: string;
  hint?: string;
  selected: boolean;
  onPress: () => void;
}) {
  const colors = useTheme();

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      onPress={onPress}
      style={({ pressed }) => ({
        flexGrow: 1,
        minWidth: '30%',
        paddingVertical: space.sm,
        paddingHorizontal: space.sm,
        borderRadius: radius.control,
        backgroundColor: selected ? colors.action.soft : colors.background.elevated,
        borderWidth: 1.5,
        borderColor: selected ? colors.action.primary : colors.border.subtle,
        gap: 2,
        opacity: pressed ? 0.9 : 1,
      })}
    >
      <T variant="bodyStrong" color={selected ? colors.action.onSoft : colors.text.primary}>
        {label}
      </T>
      {hint ? (
        <T variant="caption" color={selected ? colors.action.onSoft : colors.text.tertiary}>
          {hint}
        </T>
      ) : null}
    </Pressable>
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
      <T variant="small" color={colors.text.secondary}>
        {label}
      </T>
      <Row gap={space.md}>
        <Stepper icon="close" onPress={() => onChange(Math.max(min, value - 1))} label={`Fewer ${label}`} />
        <T variant="h3" style={{ minWidth: 18, textAlign: 'center' }}>
          {value}
        </T>
        <Stepper icon="plus" onPress={() => onChange(Math.min(12, value + 1))} label={`More ${label}`} />
      </Row>
    </View>
  );
}

function Stepper({ icon, onPress, label }: { icon: 'plus' | 'close'; onPress: () => void; label: string }) {
  const colors = useTheme();

  return (
    <Pressable
      onPress={onPress}
      hitSlop={8}
      accessibilityRole="button"
      accessibilityLabel={label}
      style={({ pressed }) => ({
        width: 38,
        height: 38,
        borderRadius: 19,
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: colors.background.elevated,
        borderWidth: 1,
        borderColor: colors.border.subtle,
        opacity: pressed ? 0.7 : 1,
      })}
    >
      <Icon name={icon} size={16} color={colors.text.primary} strokeWidth={2} />
    </Pressable>
  );
}
