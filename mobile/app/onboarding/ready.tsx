import React from 'react';
import { View } from 'react-native';
import { useRouter } from 'expo-router';

import { useDiscovery, useJourney } from '../../src/api/hooks';
import { ScoreRing } from '../../src/components/ExperienceScore';
import { Icon } from '../../src/components/Icon';
import { Photo } from '../../src/components/Photo';
import { Button, Card, Gutter, Loading, Row, Screen, T } from '../../src/components/primitives';
import { clock, conditionLabel } from '../../src/lib/format';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

/**
 * Arrival to home (Figma: 14.7).
 *
 * A light celebration that immediately proves the point: weather, the next
 * fixed commitment, and one real recommendation with a score — the payoff for
 * the six screens before it.
 */
export default function Ready() {
  const colors = useTheme();
  const router = useRouter();
  const session = useSession();
  const tz = session.destinationTimezone ?? undefined;

  const { data: journey } = useJourney(session.journeyId);
  const discovery = useDiscovery('now', { limit: 1 });
  const context = discovery.data?.context;
  const top = discovery.data?.data[0];

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.xxl, gap: space.xs }}>
        <Row gap={space.xs}>
          <Icon name="check" size={20} color={colors.status.open} strokeWidth={2.2} />
          <T variant="label" color={colors.status.open}>
            Ready
          </T>
        </Row>
        <T variant="displayXl">{session.destinationName ?? 'Your city'} is ready.</T>
      </Gutter>

      <Gutter style={{ marginTop: space.lg }}>
        <Card tone="warm">
          <View style={{ padding: space.md, gap: space.sm }}>
            {context ? (
              <Row justify="space-between">
                <Row gap={6}>
                  <Icon name="clock" size={16} color={colors.text.secondary} />
                  <T variant="body">{clock(context.local_time, tz)}</T>
                </Row>
                {context.weather ? (
                  <Row gap={6}>
                    <Icon name="weather" size={16} color={colors.text.secondary} />
                    <T variant="body">
                      {Math.round(context.weather.temperature_c)}°C {conditionLabel(context.weather.condition)}
                    </T>
                  </Row>
                ) : null}
              </Row>
            ) : null}

            {journey && journey.anchors.length > 0 ? (
              <Row gap={6}>
                <Icon name="lock" size={16} color={colors.text.secondary} />
                <T variant="body" color={colors.text.secondary}>
                  Next: {journey.anchors[0].title} at {clock(journey.anchors[0].starts_at, tz)}
                </T>
              </Row>
            ) : null}
          </View>
        </Card>
      </Gutter>

      {discovery.isLoading ? <Loading label="Finding your first recommendation" /> : null}

      {top ? (
        <Gutter style={{ marginTop: space.lg }}>
          <T variant="label" color={colors.text.tertiary} style={{ marginBottom: space.xs }}>
            Start here
          </T>
          <Card onPress={() => router.replace(`/experience/${top.id}`)} level="feature">
            <Photo
              id={top.id}
              uri={top.image_url}
              attribution={top.image_attribution}
              category={top.categories[0]?.key}
              height={160}
            />
            <Row justify="space-between" align="flex-start" gap={space.sm} style={{ padding: space.md }}>
              <View style={{ flex: 1, gap: 4 }}>
                <T variant="h3" numberOfLines={2}>
                  {top.title}
                </T>
                {top.why[0] ? (
                  <T variant="small" color={colors.text.secondary} numberOfLines={2}>
                    {top.why[0]}
                  </T>
                ) : null}
              </View>
              {top.experience_score !== null ? <ScoreRing score={top.experience_score} size="medium" /> : null}
            </Row>
          </Card>
        </Gutter>
      ) : null}

      <Gutter style={{ marginTop: space.xxl }}>
        <Button label="Build my day" size="large" haptic="medium" onPress={() => router.replace('/(tabs)')} />
      </Gutter>
    </Screen>
  );
}
