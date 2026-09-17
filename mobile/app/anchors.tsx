import React, { useState } from 'react';
import { Alert, Pressable, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useAddAnchor, useJourney } from '../src/api/hooks';
import { Button, Card, Chip, Loading, Note, Row, Screen, SectionHeader, T } from '../src/components/primitives';
import { clock, dayLabel } from '../src/lib/format';
import { ANCHOR_TYPES } from '../src/lib/reasons';
import { useSession } from '../src/store/session';
import { radius, space, useTheme } from '../src/theme';

/** Spec s3.4 — Journey Anchors. The things the plan must be built around. */
export default function Anchors() {
  const colors = useTheme();
  const router = useRouter();
  const journeyId = useSession((s) => s.journeyId);

  const { data: journey, isLoading } = useJourney(journeyId);
  const tz = journey?.destination.timezone;
  const addAnchor = useAddAnchor(journeyId ?? '');

  const [type, setType] = useState('restaurant');
  const [title, setTitle] = useState('');
  const [date, setDate] = useState(defaultDate());
  const [start, setStart] = useState('20:00');
  const [end, setEnd] = useState('22:00');
  const [buffer, setBuffer] = useState('20');

  if (isLoading || !journey) {
    return (
      <Screen>
        <Loading />
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
      <Row style={{ justifyContent: 'space-between' }}>
        <T variant="display">Fixed commitments</T>
        <Pressable onPress={() => router.back()} hitSlop={10}>
          <T variant="bodyStrong" color={colors.accent}>
            Done
          </T>
        </Pressable>
      </Row>

      <Note>
        Times are local to {journey.destination.name}. Anything you add here is protected: we will never
        schedule something that risks making you late for it, and the buffer you set is respected on top.
      </Note>

      {journey.anchors.length > 0 && (
        <>
          <SectionHeader title="Already fixed" />
          {journey.anchors.map((anchor) => (
            <Card key={anchor.id} style={{ marginBottom: space.sm }}>
              <View style={{ padding: space.lg, gap: 2 }}>
                <T variant="bodyStrong">{anchor.title}</T>
                <T variant="small" color={colors.inkMuted}>
                  {dayLabel(anchor.starts_at, tz)} · {clock(anchor.starts_at, tz)}–{clock(anchor.ends_at, tz)}
                </T>
                <T variant="small" color={colors.inkFaint}>
                  Nothing scheduled after {clock(anchor.protected_from, tz)}
                </T>
              </View>
            </Card>
          ))}
        </>
      )}

      <SectionHeader title="Add one" />

      <Row gap={space.sm} wrap>
        {ANCHOR_TYPES.map((option) => (
          <Chip key={option.key} label={option.label} selected={type === option.key} onPress={() => setType(option.key)} />
        ))}
      </Row>

      <Field label="What is it?" value={title} onChange={setTitle} placeholder="Dinner with the team" />
      <Field label="Date" value={date} onChange={setDate} placeholder="2026-09-18" />

      <Row gap={space.md}>
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
        onPress={submit}
        loading={addAnchor.isPending}
        style={{ marginTop: space.lg }}
      />
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
    <View style={{ marginTop: space.md, gap: space.xs }}>
      <T variant="small" color={colors.inkMuted}>
        {label}
      </T>
      <TextInput
        value={value}
        onChangeText={onChange}
        placeholder={placeholder}
        placeholderTextColor={colors.inkFaint}
        autoCapitalize="none"
        style={{
          backgroundColor: colors.surface,
          borderColor: colors.line,
          borderWidth: 1,
          borderRadius: radius.md,
          paddingHorizontal: space.lg,
          paddingVertical: 12,
          color: colors.ink,
          fontSize: 15,
        }}
      />
    </View>
  );
}

function defaultDate(): string {
  const tomorrow = new Date(Date.now() + 86_400_000);

  return tomorrow.toISOString().slice(0, 10);
}
