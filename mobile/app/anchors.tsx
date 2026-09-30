import React, { useState } from 'react';
import { useRouter } from 'expo-router';
import { Alert, Pressable, TextInput, View } from 'react-native';

import { useAddAnchor, useJourney } from '../src/api/hooks';
import { Icon } from '../src/components/Icon';
import { Button, Card, Chip, Divider, EmptyState, Gutter, Loading, Note, Row, Screen, SectionHeader, T } from '../src/components/primitives';
import { clock, dayLabel } from '../src/lib/format';
import { ANCHOR_TYPES } from '../src/lib/reasons';
import { useSession } from '../src/store/session';
import { radius, space, useTheme } from '../src/theme';
import { useBackTo } from '../src/lib/navigation';

/**
 * Journey anchors (Figma: 25).
 *
 * Fixed commitments get the lock language throughout, because the promise here
 * is specific: the planner will never schedule something that risks making you
 * late, and it will never move one of these silently.
 */
export default function Anchors() {
  const colors = useTheme();
  const goBack = useBackTo('/(tabs)/trip');
  const router = useRouter();
  const journeyId = useSession((s) => s.journeyId);

  const { data: journey, isLoading } = useJourney(journeyId);
  const addAnchor = useAddAnchor(journeyId ?? '');
  const tz = journey?.destination.timezone;

  const [type, setType] = useState('restaurant');
  const [title, setTitle] = useState('');
  const [date, setDate] = useState(defaultDate());
  const [start, setStart] = useState('20:00');
  const [end, setEnd] = useState('22:00');
  const [buffer, setBuffer] = useState('20');

  /*
   * A spinner is only honest while something is actually loading.
   *
   * This screen showed one whenever `journey` was falsy, and with no journey
   * selected that is permanent: no content, no explanation, and — because the
   * back control lived further down the same return — no way off the screen
   * either. Reachable directly by URL, and now by deep link.
   */
  const header = (
    <Gutter style={{ paddingTop: space.sm }}>
      <Row justify="space-between">
        <Pressable
          accessibilityRole="button"
          onPress={goBack}
          hitSlop={12}
          accessibilityLabel="Back"
        >
          <Icon name="back" size={24} color={colors.text.primary} />
        </Pressable>
      </Row>
    </Gutter>
  );

  if (isLoading && journeyId) {
    return (
      <Screen>
        {header}
        <Loading />
      </Screen>
    );
  }

  if (!journey) {
    return (
      <Screen>
        {header}
        <Gutter>
          <EmptyState
            title="No trip to plan around yet"
            body="Anchors are the fixed points of a trip — a meeting, a dinner, a train. Start a trip and they will have something to hold onto."
            action={<Button label="Start a trip" onPress={() => router.replace('/onboarding')} />}
          />
        </Gutter>
      </Screen>
    );
  }

  const submit = async () => {
    if (!title.trim()) {
      Alert.alert('Give it a name', 'Something like "Dinner with the team" or "Flight home".');

      return;
    }

    try {
      await addAnchor.mutateAsync({
        type,
        title: title.trim(),
        starts_at: `${date}T${start}:00`,
        ends_at: `${date}T${end}:00`,
        buffer_before_minutes: Number(buffer) || 0,
      });

      setTitle('');
      Alert.alert('Added', 'Your plan will be built around this from now on.');
    } catch (cause) {
      Alert.alert('Could not add it', cause instanceof Error ? cause.message : 'Check the times and try again.');
    }
  };

  return (
    <Screen>
      {header}
      <Gutter>
        <T variant="displayL" style={{ marginTop: space.sm }}>
          Fixed commitments
        </T>
      </Gutter>

      <Gutter style={{ marginTop: space.md }}>
        <Note icon={<Icon name="lock" size={16} color={colors.text.secondary} />}>
          Times are local to {journey.destination.name}. Anything here is protected: we will never schedule
          something that risks making you late, and the buffer you set is respected on top.
        </Note>
      </Gutter>

      {journey.anchors.length > 0 ? (
        <Gutter>
          <SectionHeader title="Already fixed" />
          <Card>
            <View style={{ padding: space.md }}>
              {journey.anchors.map((anchor, index) => (
                <React.Fragment key={anchor.id}>
                  {index > 0 ? <Divider style={{ marginVertical: space.sm }} /> : null}
                  <Row gap={space.sm} align="flex-start">
                    <View
                      style={{
                        width: 34,
                        height: 34,
                        borderRadius: radius.compact,
                        backgroundColor: colors.background.sunken,
                        alignItems: 'center',
                        justifyContent: 'center',
                      }}
                    >
                      <Icon name="lock" size={15} color={colors.text.secondary} />
                    </View>
                    <View style={{ flex: 1 }}>
                      <T variant="bodyStrong">{anchor.title}</T>
                      <T variant="small" color={colors.text.secondary}>
                        {dayLabel(anchor.starts_at, tz)} · {clock(anchor.starts_at, tz)}–{clock(anchor.ends_at, tz)}
                      </T>
                      <T variant="caption" color={colors.text.tertiary}>
                        Nothing scheduled after {clock(anchor.protected_from, tz)}
                      </T>
                    </View>
                  </Row>
                </React.Fragment>
              ))}
            </View>
          </Card>
        </Gutter>
      ) : null}

      <Gutter>
        <SectionHeader title="Add one" />
        <Row gap={space.xs} wrap>
          {ANCHOR_TYPES.map((option) => (
            <Chip
              key={option.key}
              label={option.label}
              size="small"
              selected={type === option.key}
              onPress={() => setType(option.key)}
              icon={
                <Icon
                  name={option.icon}
                  size={13}
                  color={type === option.key ? colors.text.onAccent : colors.text.tertiary}
                />
              }
            />
          ))}
        </Row>

        <Field label="What is it?" value={title} onChange={setTitle} placeholder="Dinner with the team" />
        <Field label="Date" value={date} onChange={setDate} placeholder="2026-09-20" />

        <Row gap={space.sm} align="flex-start">
          <View style={{ flex: 1 }}>
            <Field label="Starts" value={start} onChange={setStart} placeholder="20:00" />
          </View>
          <View style={{ flex: 1 }}>
            <Field label="Ends" value={end} onChange={setEnd} placeholder="22:00" />
          </View>
        </Row>

        <Field label="Buffer before (minutes)" value={buffer} onChange={setBuffer} placeholder="20" />

        <Button
          label={addAnchor.isPending ? 'Adding…' : 'Add commitment'}
          size="large"
          onPress={submit}
          loading={addAnchor.isPending}
          haptic="medium"
          style={{ marginTop: space.lg }}
        />
      </Gutter>
    </Screen>
  );
}

function Field({
  label,
  value,
  onChange,
  placeholder,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder: string;
}) {
  const colors = useTheme();

  return (
    <View style={{ marginTop: space.md, gap: space.xxs }}>
      <T variant="small" color={colors.text.secondary}>
        {label}
      </T>
      <TextInput
        value={value}
        onChangeText={onChange}
        placeholder={placeholder}
        placeholderTextColor={colors.text.tertiary}
        autoCapitalize="none"
        style={{
          backgroundColor: colors.background.elevated,
          borderColor: colors.border.subtle,
          borderWidth: 1,
          borderRadius: radius.control,
          paddingHorizontal: space.md,
          paddingVertical: 13,
          color: colors.text.primary,
          fontFamily: 'Inter_400Regular',
          fontSize: 15,
        }}
      />
    </View>
  );
}

function defaultDate(): string {
  return new Date(Date.now() + 86_400_000).toISOString().slice(0, 10);
}
