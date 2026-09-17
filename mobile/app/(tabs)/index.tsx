import React, { useCallback, useMemo, useState } from 'react';
import { Alert, Pressable, RefreshControl, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useDestination, useDiscovery, useSurpriseMe, useTrip } from '../../src/api/hooks';
import { ExperienceCardView } from '../../src/components/ExperienceCard';
import {
  Button,
  Card,
  Chip,
  EmptyState,
  Loading,
  Note,
  Row,
  ScoreBadge,
  Screen,
  SectionHeader,
  T,
} from '../../src/components/primitives';
import { Photo } from '../../src/components/Photo';
import { clock, conditionLabel, minutesLabel, timeOfDayGreeting } from '../../src/lib/format';
import { MOODS, TIME_WINDOWS } from '../../src/lib/reasons';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

/** Spec s17.1 — Home. Everything here answers "what next, right now?". */
export default function Today() {
  const colors = useTheme();
  const router = useRouter();
  const session = useSession();
  const tz = session.destinationTimezone ?? undefined;

  const [window, setWindow] = useState<number | null>(null);
  const [mood, setMood] = useState<string | null>(null);

  const surface = window ? 'time-boxed' : mood ? 'mood' : 'now';
  const payload = useMemo(
    () => (window ? { window_minutes: window } : mood ? { mood } : {}),
    [window, mood],
  );

  const discovery = useDiscovery(surface as 'now', payload);
  const { data: destination } = useDestination(session.destinationSlug);
  const { data: trip } = useTrip(session.tripId);
  const surprise = useSurpriseMe();

  const context = discovery.data?.context;
  const results = discovery.data?.data ?? [];
  const [top, ...rest] = results;

  const todaysItems = useMemo(() => {
    /* "Today" is today where the traveller is, not where their phone thinks it is. */
    const today = new Date().toLocaleDateString('en-CA', tz ? { timeZone: tz } : {});

    return trip?.itinerary?.days.find((day) => day.date === today)?.items ?? [];
  }, [trip, tz]);

  const roll = useCallback(async () => {
    try {
      const result = await surprise.mutateAsync(window ? { window_minutes: window } : {});

      if (result.data) {
        router.push(`/experience/${result.data.id}`);
      } else {
        Alert.alert('Nothing strong enough', result.message ?? 'Try widening the time you have.');
      }
    } catch {
      Alert.alert('Could not reach the API', 'Check that the backend is running.');
    }
  }, [router, surprise, window]);

  if (!session.destinationId && !session.coords) {
    return (
      <Screen>
        <EmptyState
          title="Pick a city first"
          body="We need to know where you are before we can be useful."
          action={<Button label="Choose a city" onPress={() => router.push('/onboarding')} style={{ marginTop: space.md }} />}
        />
      </Screen>
    );
  }

  return (
    <Screen
      refreshControl={
        <RefreshControl refreshing={discovery.isFetching} onRefresh={() => discovery.refetch()} tintColor={colors.accent} />
      }
    >
      <Row style={{ justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <View style={{ flex: 1 }}>
          <T variant="display">{timeOfDayGreeting()}</T>
          <Row gap={space.sm} style={{ marginTop: 6 }} wrap>
            <T variant="small" color={colors.inkMuted}>
              {session.destinationName ?? 'Your city'}
            </T>
            {context?.weather && (
              <>
                <T variant="small" color={colors.inkFaint}>·</T>
                <T variant="small" color={colors.inkMuted}>
                  {Math.round(context.weather.temperature_c)}°C {conditionLabel(context.weather.condition)}
                </T>
              </>
            )}
            {context && (
              <>
                <T variant="small" color={colors.inkFaint}>·</T>
                <T variant="small" color={colors.inkMuted}>
                  {clock(context.local_time, tz)}
                </T>
              </>
            )}
          </Row>
        </View>
        <Pressable onPress={() => router.push('/profile')} hitSlop={10}>
          <View
            style={{
              width: 40,
              height: 40,
              borderRadius: 20,
              backgroundColor: colors.surfaceAlt,
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <T variant="bodyStrong" color={colors.inkMuted}>
              ⋯
            </T>
          </View>
        </Pressable>
      </Row>

      {context?.next_anchor && (
        <View style={{ marginTop: space.lg }}>
          <Note tone="warn">
            Next fixed commitment: {context.next_anchor.title} at {clock(context.next_anchor.starts_at, tz)}.
            {context.window_minutes ? ` That leaves you about ${minutesLabel(context.window_minutes)}.` : ''}
          </Note>
        </View>
      )}

      {context?.weather?.is_poor && (
        <View style={{ marginTop: space.md }}>
          <Note tone="warn">
            {conditionLabel(context.weather.condition)} right now — indoor options have moved up the list.
          </Note>
        </View>
      )}

      <SectionHeader title="How long have you got?" />
      <Row gap={space.sm} wrap>
        <Chip label="Right now" selected={window === null && mood === null} onPress={() => { setWindow(null); setMood(null); }} />
        {TIME_WINDOWS.map((option) => (
          <Chip
            key={option.minutes}
            label={option.label}
            selected={window === option.minutes}
            onPress={() => {
              setMood(null);
              setWindow(window === option.minutes ? null : option.minutes);
            }}
          />
        ))}
      </Row>

      <SectionHeader title="Or by mood" />
      <Row gap={space.sm} wrap>
        {MOODS.map((option) => (
          <Chip
            key={option.key}
            label={option.label}
            selected={mood === option.key}
            onPress={() => {
              setWindow(null);
              setMood(mood === option.key ? null : option.key);
            }}
          />
        ))}
        <Chip label={surprise.isPending ? 'Rolling…' : 'Surprise me'} onPress={roll} tone="accent" />
      </Row>

      {discovery.isLoading && <Loading label="Working out what is worth your time" />}

      {discovery.isError && (
        <View style={{ marginTop: space.lg }}>
          <Note tone="warn">
            Could not reach the recommendation engine. Pull down to retry once the API is running.
          </Note>
        </View>
      )}

      {discovery.data?.notice && (
        <View style={{ marginTop: space.lg }}>
          <Note>{discovery.data.notice}</Note>
        </View>
      )}

      {top && (
        <>
          <SectionHeader
            title={window ? `Best use of ${minutesLabel(window)}` : mood ? 'Closest to your mood' : "Don't miss today"}
          />
          <Card onPress={() => router.push(`/experience/${top.id}`)}>
            <Photo
              id={top.id}
              uri={top.image_url}
              attribution={top.image_attribution}
              category={top.categories[0]?.key}
              height={150}
              label={top.categories[0]?.label}
            />
            <View style={{ padding: space.lg, gap: space.sm }}>
              <Row style={{ alignItems: 'flex-start', gap: space.md }}>
                <View style={{ flex: 1 }}>
                  <T variant="title">{top.title}</T>
                  <T variant="small" color={colors.inkMuted} style={{ marginTop: 4 }}>
                    {top.summary}
                  </T>
                </View>
                {top.experience_score !== null && <ScoreBadge score={top.experience_score} />}
              </Row>

              <View style={{ backgroundColor: colors.surfaceAlt, borderRadius: radius.md, padding: space.md, gap: 4 }}>
                {top.why.slice(0, 3).map((reason) => (
                  <T key={reason} variant="small">
                    {reason}
                  </T>
                ))}
              </View>

              <Row gap={space.md}>
                <T variant="small" color={colors.inkMuted}>
                  {top.is_free ? 'Free' : (top.price_from?.formatted ?? 'Price not verified')}
                </T>
                {top.travel && (
                  <T variant="small" color={colors.inkMuted}>
                    {top.travel.minutes} min away
                  </T>
                )}
              </Row>
            </View>
          </Card>
        </>
      )}

      {todaysItems.length > 0 && (
        <>
          <SectionHeader title="Your day" action="Open plan" onAction={() => router.push('/(tabs)/trip')} />
          <Card>
            <View style={{ padding: space.lg, gap: space.md }}>
              {todaysItems.slice(0, 5).map((item) => (
                <Row key={item.id} gap={space.md} style={{ alignItems: 'flex-start' }}>
                  <T variant="small" color={colors.inkFaint} style={{ width: 46 }}>
                    {clock(item.starts_at, tz)}
                  </T>
                  <View style={{ flex: 1 }}>
                    <T variant="bodyStrong" color={item.kind === 'anchor' ? colors.accent : colors.ink}>
                      {item.title}
                    </T>
                    {item.reason && (
                      <T variant="small" color={colors.inkMuted} numberOfLines={1}>
                        {item.reason}
                      </T>
                    )}
                  </View>
                </Row>
              ))}
            </View>
          </Card>
        </>
      )}

      {rest.length > 0 && (
        <>
          <SectionHeader title="Also worth your time" />
          {rest.map((card) => (
            <ExperienceCardView
              key={card.id}
              card={card}
              recommendationSetId={discovery.data?.recommendation_set_id}
              surface={surface}
            />
          ))}
        </>
      )}

      {destination && destination.dont_leave_without.length > 0 && (
        <>
          <SectionHeader title={`Don't leave ${destination.name} without`} />
          <Card>
            <View style={{ padding: space.lg, gap: space.lg }}>
              {destination.dont_leave_without.slice(0, 4).map((item) => (
                <View key={item.title} style={{ gap: 2 }}>
                  <T variant="bodyStrong">{item.title}</T>
                  <T variant="small" color={colors.inkMuted}>
                    {item.description}
                  </T>
                </View>
              ))}
            </View>
          </Card>
        </>
      )}

      {destination && (
        <Button
          label="City essentials"
          tone="secondary"
          onPress={() => router.push('/essentials')}
          style={{ marginTop: space.lg }}
        />
      )}

      {context && (
        <T variant="small" color={colors.inkFaint} style={{ marginTop: space.xl, textAlign: 'center' }}>
          Ranked by {context.engine_version} from {discovery.data?.candidates_considered ?? 0} options
        </T>
      )}
    </Screen>
  );
}
