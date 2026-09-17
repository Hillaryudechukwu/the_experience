import React from 'react';
import { View } from 'react-native';
import { useRouter } from 'expo-router';

import { usePassport } from '../src/api/hooks';
import { Card, EmptyState, Loading, Row, Screen, SectionHeader, T } from '../src/components/primitives';
import { titleCase } from '../src/lib/format';
import { radius, space, useTheme } from '../src/theme';

/** Spec s14.1 — the Experience Passport. */
export default function PassportScreen() {
  const colors = useTheme();
  const router = useRouter();
  const { data, isLoading } = usePassport();

  if (isLoading || !data) {
    return (
      <Screen>
        <Loading />
      </Screen>
    );
  }

  if (data.totals.experiences === 0) {
    return (
      <Screen>
        <T variant="display">Passport</T>
        <EmptyState
          title="Nothing stamped yet"
          body="Mark an experience as done and it lands here, with the city and the date."
        />
      </Screen>
    );
  }

  return (
    <Screen>
      <T variant="display">Passport</T>

      <Row style={{ marginTop: space.lg, justifyContent: 'space-around' }}>
        <Stat label="Experiences" value={data.totals.experiences} />
        <Stat label="Cities" value={data.totals.cities} />
        <Stat label="Countries" value={data.totals.countries} />
      </Row>

      <SectionHeader title="Cities" />
      {data.cities.map((city) => (
        <Card key={city.id} style={{ marginBottom: space.sm }}>
          <Row style={{ padding: space.lg, justifyContent: 'space-between' }}>
            <View>
              <T variant="bodyStrong">{city.name}</T>
              <T variant="small" color={colors.inkMuted}>
                {city.country}
              </T>
            </View>
            <T variant="heading" color={colors.accent}>
              {city.experiences}
            </T>
          </Row>
        </Card>
      ))}

      <SectionHeader title="What you go for" />
      <Row gap={space.sm} wrap>
        {data.categories.map((category) => (
          <View
            key={category.key}
            style={{
              paddingVertical: 8,
              paddingHorizontal: 14,
              borderRadius: radius.pill,
              backgroundColor: colors.surfaceAlt,
            }}
          >
            <T variant="small" color={colors.inkMuted}>
              {titleCase(category.key)} · {category.count}
            </T>
          </View>
        ))}
      </Row>

      <SectionHeader title="Milestones" />
      <Card>
        <View style={{ padding: space.lg, gap: space.sm }}>
          {data.milestones.map((milestone) => (
            <Row key={milestone.key} style={{ justifyContent: 'space-between' }}>
              <T variant="small" color={milestone.reached ? colors.ink : colors.inkFaint}>
                {milestone.label}
              </T>
              <T variant="small" color={milestone.reached ? colors.positive : colors.inkFaint}>
                {milestone.reached ? 'Earned' : 'Not yet'}
              </T>
            </Row>
          ))}
        </View>
      </Card>

      <SectionHeader title="Recently" />
      {data.recent.map((entry) => (
        <Card
          key={entry.experience_id + entry.completed_at}
          style={{ marginBottom: space.sm }}
          onPress={() => router.push(`/experience/${entry.experience_id}`)}
        >
          <View style={{ padding: space.lg }}>
            <T variant="bodyStrong">{entry.title}</T>
            <T variant="small" color={colors.inkMuted}>
              {entry.destination} · {new Date(entry.completed_at).toLocaleDateString()}
            </T>
          </View>
        </Card>
      ))}
    </Screen>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  const colors = useTheme();

  return (
    <View style={{ alignItems: 'center', gap: 2 }}>
      <T variant="display">{value}</T>
      <T variant="small" color={colors.inkMuted}>
        {label}
      </T>
    </View>
  );
}
