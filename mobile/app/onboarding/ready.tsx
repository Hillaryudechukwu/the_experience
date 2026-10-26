import React, { useEffect, useState } from 'react';
import { View } from 'react-native';
import { useRouter } from 'expo-router';

import { ApiError } from '../../src/api/client';
import { useBuildItinerary, useCreateTrip, useDiscovery, useEntitlement, useJourney } from '../../src/api/hooks';
import { ScoreRing } from '../../src/components/ExperienceScore';
import { Icon } from '../../src/components/Icon';
import { Photo } from '../../src/components/Photo';
import { Button, Card, CardPress, Gutter, Loading, Note, Row, Screen, T } from '../../src/components/primitives';
import { buildPlanCtaLabel, clock, conditionLabel, stayDayCount } from '../../src/lib/format';
import { registerForPush } from '../../src/lib/push';
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
  const entitlement = useEntitlement();
  const canUse = entitlement.data?.can_use === true;

  /* Recommendations are the paid product. Asking for one without a month
     would only bounce off a 402 halfway through this screen. */
  const discovery = useDiscovery('now', { limit: 1 }, canUse);
  const context = discovery.data?.context;
  const top = discovery.data?.data[0];
  const days = stayDayCount(journey?.starts_on, journey?.ends_on);

  const preparingBody =
    discovery.error instanceof ApiError && discovery.error.status === 409
      ? (discovery.error.body as { code?: string; import_id?: string } | null)
      : null;
  const preparing = preparingBody?.code === 'destination_preparing';

  useEffect(() => {
    if (preparing && preparingBody?.import_id) {
      void session.setDestinationImport(preparingBody.import_id);
    }
  }, [preparing, preparingBody?.import_id, session]);

  const createTrip = useCreateTrip();
  const buildItinerary = useBuildItinerary();
  const [buildError, setBuildError] = useState<string | null>(null);
  const building = createTrip.isPending || buildItinerary.isPending;

  /* The button promises a plan, so it builds one rather than opening an
     empty Plan tab that asks the same question again. */
  const build = async () => {
    if (!canUse) {
      router.replace('/paywall');
      return;
    }

    if (!session.journeyId || preparing) {
      router.replace(preparing ? '/(tabs)' : '/(tabs)/trip');
      return;
    }

    setBuildError(null);

    try {
      let tripId = session.tripId;

      if (!tripId) {
        const created = await createTrip.mutateAsync(session.journeyId);
        await session.setTrip(created.id);
        tripId = created.id;
      }

      await buildItinerary.mutateAsync({ tripId });
      router.replace('/(tabs)/trip');
    } catch (cause) {
      setBuildError(cause instanceof Error ? cause.message : 'The plan did not build. Try again from Plan.');
    }
  };

  const ctaLabel = !entitlement.data
    ? 'Continue'
    : !canUse
      ? 'See what a month unlocks'
      : preparing
        ? 'Go to home'
        : building
          ? multiDayBuildingLabel(days)
          : buildPlanCtaLabel(days);

  useEffect(() => {
    registerForPush().catch(() => {});
  }, []);

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.xxl, gap: space.xs }}>
        <Row gap={space.xs}>
          <Icon name="check" size={20} color={colors.status.open} strokeWidth={2.2} />
          <T variant="label" color={colors.status.open}>
            Ready
          </T>
        </Row>
        <T variant="displayXl">
          {preparing
            ? `Preparing ${session.destinationName ?? 'your city'}.`
            : `${session.destinationName ?? 'Your city'} is ready.`}
        </T>
      </Gutter>

      {preparing ? (
        <Gutter style={{ marginTop: space.lg }}>
          <Note tone="info">
            We are gathering what is worth doing in {session.destinationName ?? 'this city'}. It usually takes a few
            minutes, and Home fills in as soon as it is done.
          </Note>
        </Gutter>
      ) : null}

      {context || (journey && journey.anchors.length > 0) ? (
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
      ) : null}

      {discovery.isLoading ? <Loading label="Finding your first recommendation" /> : null}

      {top ? (
        <Gutter style={{ marginTop: space.lg }}>
          <T variant="label" color={colors.text.tertiary} style={{ marginBottom: space.xs }}>
            Start here
          </T>
          <Card level="feature">
            <CardPress
              onPress={() => router.replace(`/experience/${top.id}`)}
              accessibilityLabel={`Start with ${top.title}`}
            >
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
            </CardPress>
          </Card>
        </Gutter>
      ) : null}

      <Gutter style={{ marginTop: space.xxl, gap: space.sm }}>
        {buildError ? <Note tone="warning">{buildError}</Note> : null}
        <Button
          label={ctaLabel}
          size="large"
          haptic="medium"
          onPress={build}
          loading={building}
          disabled={entitlement.isLoading}
        />
        {building && days !== null && days > 7 ? (
          <T variant="small" color={colors.text.secondary} style={{ textAlign: 'center' }}>
            Planning {days} days takes a little longer.
          </T>
        ) : null}
      </Gutter>
    </Screen>
  );
}

function multiDayBuildingLabel(days: number | null): string {
  return days !== null && days > 1 ? `Planning ${days} days…` : 'Building…';
}
