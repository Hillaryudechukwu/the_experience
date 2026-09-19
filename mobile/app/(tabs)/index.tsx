import React, { useCallback, useMemo, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, View } from 'react-native';
import { useRouter } from 'expo-router';

import { API_URL } from '../../src/api/client';
import { useDestination, useDiscovery, useJourney, useSaved, useSurpriseMe, useTrip } from '../../src/api/hooks';
import { AnchorStrip } from '../../src/components/AnchorStrip';
import { ExperienceCardView } from '../../src/components/ExperienceCard';
import { HeroIntelligence } from '../../src/components/HeroIntelligence';
import { Icon } from '../../src/components/Icon';
import { MOODS, MoodTile } from '../../src/components/MoodTile';
import {
  Button,
  Card,
  Chip,
  EmptyState,
  Gutter,
  Loading,
  Note,
  Row,
  Screen,
  SectionHeader,
  Skeleton,
  T,
} from '../../src/components/primitives';
import { clock, conditionLabel, weekdayAndTime, windowLabel } from '../../src/lib/format';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

const TIME_WINDOWS = [
  { minutes: 30, label: '30 min' },
  { minutes: 60, label: '1 hour' },
  { minutes: 120, label: '2 hours' },
  { minutes: 180, label: '3 hours' },
  { minutes: 300, label: 'Half day' },
  { minutes: 600, label: 'All day' },
];

/**
 * Discover (Figma: 16, 17).
 *
 * One clear visual priority: the hero intelligence card, which answers what to
 * do with the time the traveller actually has. Everything below it is browsable
 * rather than demanding, and the sections vary in shape so the screen never
 * becomes a marketplace grid.
 */
export default function Discover() {
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
  const hiddenGems = useDiscovery('now', { categories: ['hidden_gem'], limit: 6 }, !window && !mood);
  const freeNearby = useDiscovery('now', { free_only: true, limit: 6 }, !window && !mood);
  const { data: destination } = useDestination(session.destinationSlug);
  const { data: journey } = useJourney(session.journeyId);
  const { data: trip } = useTrip(session.tripId);
  const { data: saved } = useSaved();
  const surprise = useSurpriseMe();

  const context = discovery.data?.context;
  const results = discovery.data?.data ?? [];
  const [top, ...rest] = results;

  const todaysItems = useMemo(() => {
    const today = new Date().toLocaleDateString('en-CA', tz ? { timeZone: tz } : {});

    return trip?.itinerary?.days.find((day) => day.date === today)?.items ?? [];
  }, [trip, tz]);

  const roll = useCallback(async () => {
    try {
      const result = await surprise.mutateAsync(window ? { window_minutes: window } : {});
      if (result.data) router.push(`/experience/${result.data.id}`);
      else Alert.alert('Nothing strong enough', result.message ?? 'Try widening the time you have.');
    } catch {
      Alert.alert('Could not reach the API', `Tried ${API_URL}.`);
    }
  }, [router, surprise, window]);

  if (!session.destinationId && !session.coords) {
    return (
      <Screen>
        <Gutter>
          <EmptyState
            title="Pick a city first"
            body="We need to know where you are before we can be useful."
            action={<Button label="Choose a city" onPress={() => router.push('/onboarding')} />}
          />
        </Gutter>
      </Screen>
    );
  }

  const heroHeadline = context?.window_minutes
    ? `You have ${windowLabel(context.window_minutes)}${context.next_anchor ? ` before ${context.next_anchor.title.toLowerCase()}` : ''}.`
    : window
      ? `${windowLabel(window)} to spend.`
      : 'Your day is open.';

  const heroDetail = discovery.isLoading
    ? 'Working out what fits…'
    : results.length > 0
      ? `I found ${results.length} experience${results.length === 1 ? '' : 's'} that fit.`
      : 'Nothing strong fits right now — try widening the time or distance.';

  return (
    <Screen
      refreshControl={
        <RefreshControl
          refreshing={discovery.isFetching}
          onRefresh={() => discovery.refetch()}
          tintColor={colors.action.primary}
        />
      }
    >
      {/* ── Header ─────────────────────────────────────────────────────── */}
      <Gutter style={{ paddingTop: space.sm }}>
        <Row justify="space-between" align="flex-start">
          <View style={{ flex: 1 }}>
            <T variant="displayL">{session.destinationName ?? 'Your city'}</T>
            <Row gap={space.xs} style={{ marginTop: 4 }} wrap>
              {context ? (
                <T variant="small" color={colors.text.secondary}>
                  {weekdayAndTime(context.local_time, tz)}
                </T>
              ) : null}
              {context?.weather ? (
                <>
                  <T variant="small" color={colors.text.tertiary}>
                    ·
                  </T>
                  <Row gap={5}>
                    <Icon name="weather" size={14} color={colors.text.tertiary} />
                    <T variant="small" color={colors.text.secondary}>
                      {Math.round(context.weather.temperature_c)}°C {conditionLabel(context.weather.condition)}
                    </T>
                  </Row>
                </>
              ) : null}
            </Row>
          </View>

          <Pressable onPress={() => router.push('/(tabs)/you')} hitSlop={10} accessibilityLabel="Your profile">
            <View
              style={{
                width: 42,
                height: 42,
                borderRadius: 21,
                backgroundColor: colors.background.elevated,
                borderWidth: 1,
                borderColor: colors.border.subtle,
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Icon name="person" size={20} color={colors.text.secondary} />
            </View>
          </Pressable>
        </Row>

        {/* ── Search entry ─────────────────────────────────────────────── */}
        <Pressable
          onPress={() => router.push('/search')}
          accessibilityRole="search"
          style={({ pressed }) => ({
            marginTop: space.md,
            flexDirection: 'row',
            alignItems: 'center',
            gap: space.xs,
            backgroundColor: colors.background.elevated,
            borderWidth: 1,
            borderColor: colors.border.subtle,
            borderRadius: radius.pill,
            paddingHorizontal: space.md,
            paddingVertical: 13,
            opacity: pressed ? 0.9 : 1,
          })}
        >
          <Icon name="search" size={18} color={colors.text.tertiary} />
          <T variant="body" color={colors.text.tertiary}>
            What do you feel like doing?
          </T>
        </Pressable>
      </Gutter>

      {/* ── Hero intelligence ──────────────────────────────────────────── */}
      <Gutter style={{ marginTop: space.md }}>
        <HeroIntelligence
          meta={context?.journey ? 'For your trip' : 'Right now'}
          headline={heroHeadline}
          detail={heroDetail}
          primaryLabel={window ? 'Build this window' : 'Build my day'}
          onPrimary={() => router.push('/(tabs)/trip')}
          secondaryLabel={results.length > 0 ? 'See options' : undefined}
          onSecondary={() => router.push('/search')}
        />
      </Gutter>

      {/* ── Anchor strip ───────────────────────────────────────────────── */}
      {journey && journey.anchors.length > 0 ? (
        <View style={{ marginTop: space.lg }}>
          <Gutter>
            <T variant="label" color={colors.text.tertiary}>
              Your day
            </T>
          </Gutter>
          <View style={{ marginTop: space.xs }}>
            <AnchorStrip anchors={journey.anchors} timezone={tz} onPressFree={(minutes) => setWindow(minutes)} />
          </View>
        </View>
      ) : null}

      {/* ── Weather / anchor notices ───────────────────────────────────── */}
      {context?.weather?.is_poor ? (
        <Gutter style={{ marginTop: space.md }}>
          <Note tone="warning" icon={<Icon name="weather" size={16} color={colors.status.warning} />}>
            {conditionLabel(context.weather.condition)} right now — indoor options have moved up.
          </Note>
        </Gutter>
      ) : null}

      {discovery.isError ? (
        <Gutter style={{ marginTop: space.md }}>
          <Note tone="warning">
            {`Could not reach the API at ${API_URL}. Start it with "php artisan serve --host=0.0.0.0 --port=8099", then pull down to retry.`}
          </Note>
        </Gutter>
      ) : null}

      {/* ── Quick time ─────────────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader title="How long have you got?" />
      </Gutter>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.xs }}>
        <Chip
          label="Right now"
          selected={window === null && mood === null}
          onPress={() => {
            setWindow(null);
            setMood(null);
          }}
        />
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
      </ScrollView>

      {/* ── Best for you now ───────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader
          title={window ? `Best use of ${windowLabel(window)}` : mood ? 'Closest to your mood' : 'Best for you now'}
          caption={
            discovery.data ? `Chosen from ${discovery.data.candidates_considered} nearby options` : undefined
          }
        />
      </Gutter>

      <Gutter>
        {discovery.isLoading ? (
          <Card level="card">
            <View style={{ padding: space.md, gap: space.sm }}>
              <Skeleton height={140} />
              <Skeleton height={20} width="70%" />
              <Skeleton height={14} width="45%" />
            </View>
          </Card>
        ) : null}

        {discovery.data?.notice ? (
          <View style={{ marginBottom: space.sm }}>
            <Note tone="info">{discovery.data.notice}</Note>
          </View>
        ) : null}

        {top ? (
          <ExperienceCardView
            card={top}
            variant="recommendation"
            recommendationSetId={discovery.data?.recommendation_set_id}
            surface={surface}
          />
        ) : null}

        {rest.slice(0, 3).map((card) => (
          <ExperienceCardView
            key={card.id}
            card={card}
            variant="compact"
            recommendationSetId={discovery.data?.recommendation_set_id}
            surface={surface}
          />
        ))}

        {!discovery.isLoading && results.length === 0 && !discovery.isError ? (
          <EmptyState
            title="Nothing strong fits all of that"
            body="Loosening the time, the budget or the distance usually opens it up."
          />
        ) : null}
      </Gutter>

      {/* ── Today's plan ───────────────────────────────────────────────── */}
      {todaysItems.length > 0 ? (
        <>
          <Gutter>
            <SectionHeader title="Today's plan" action="Open trip" onAction={() => router.push('/(tabs)/trip')} />
          </Gutter>
          <Gutter>
            <Card>
              <View style={{ padding: space.md, gap: space.md }}>
                {todaysItems.slice(0, 5).map((item) => (
                  <Row key={item.id} gap={space.sm} align="flex-start">
                    <T variant="smallStrong" color={colors.text.tertiary} style={{ width: 44 }}>
                      {clock(item.starts_at, tz)}
                    </T>
                    <View
                      style={{
                        width: 3,
                        alignSelf: 'stretch',
                        borderRadius: 2,
                        backgroundColor: item.kind === 'anchor' ? colors.text.primary : colors.status.open,
                      }}
                    />
                    <View style={{ flex: 1 }}>
                      <Row gap={6}>
                        {item.kind === 'anchor' ? <Icon name="lock" size={13} color={colors.text.tertiary} /> : null}
                        <T variant="bodyStrong" numberOfLines={1} style={{ flex: 1 }}>
                          {item.title}
                        </T>
                      </Row>
                      {item.reason ? (
                        <T variant="small" color={colors.text.secondary} numberOfLines={1}>
                          {item.reason}
                        </T>
                      ) : null}
                    </View>
                  </Row>
                ))}
              </View>
            </Card>
          </Gutter>
        </>
      ) : null}

      {/* ── Mood ───────────────────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader title="Or go by mood" />
      </Gutter>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.xs }}>
        {MOODS.map((option) => (
          <MoodTile
            key={option.key}
            mood={option}
            selected={mood === option.key}
            onPress={() => {
              setWindow(null);
              setMood(mood === option.key ? null : option.key);
            }}
          />
        ))}
        <Pressable
          onPress={roll}
          style={({ pressed }) => ({
            width: 132,
            height: 96,
            borderRadius: radius.card,
            borderWidth: 1.5,
            borderStyle: 'dashed',
            borderColor: colors.border.strong,
            alignItems: 'center',
            justifyContent: 'center',
            gap: 6,
            opacity: pressed ? 0.8 : 1,
          })}
        >
          <Icon name="sparkle" size={20} color={colors.text.secondary} />
          <T variant="smallStrong" color={colors.text.secondary}>
            {surprise.isPending ? 'Finding…' : 'Surprise me'}
          </T>
        </Pressable>
      </ScrollView>

      {/* ── Hidden gems rail ───────────────────────────────────────────── */}
      {(hiddenGems.data?.data.length ?? 0) > 0 ? (
        <>
          <Gutter>
            <SectionHeader title="Hidden gems" caption="Less obvious than the headline attractions" />
          </Gutter>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.sm }}>
            {hiddenGems.data!.data.map((card) => (
              <ExperienceCardView key={card.id} card={card} variant="rail" surface="hidden_gems" />
            ))}
          </ScrollView>
        </>
      ) : null}

      {/* ── Free near you ──────────────────────────────────────────────── */}
      {(freeNearby.data?.data.length ?? 0) > 0 ? (
        <>
          <Gutter>
            <SectionHeader title="Free near you" />
          </Gutter>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.sm }}>
            {freeNearby.data!.data.map((card) => (
              <ExperienceCardView key={card.id} card={card} variant="rail" surface="free_nearby" />
            ))}
          </ScrollView>
        </>
      ) : null}

      {/* ── Don't leave without ────────────────────────────────────────── */}
      {destination && destination.dont_leave_without.length > 0 ? (
        <>
          <Gutter>
            <SectionHeader
              title={`Don't leave ${destination.name} without`}
              caption="Things that make this city itself, not just its attractions"
            />
          </Gutter>
          <Gutter>
            <Card tone="warm">
              <View style={{ padding: space.md, gap: space.md }}>
                {destination.dont_leave_without.slice(0, 5).map((item, index) => (
                  <Row key={item.title} align="flex-start" gap={space.sm}>
                    <View
                      style={{
                        width: 24,
                        height: 24,
                        borderRadius: 12,
                        borderWidth: 1.5,
                        borderColor: colors.border.strong,
                        alignItems: 'center',
                        justifyContent: 'center',
                      }}
                    >
                      <T variant="caption" color={colors.text.secondary}>
                        {index + 1}
                      </T>
                    </View>
                    <View style={{ flex: 1, gap: 2 }}>
                      <T variant="bodyStrong">{item.title}</T>
                      <T variant="small" color={colors.text.secondary}>
                        {item.description}
                      </T>
                    </View>
                  </Row>
                ))}
              </View>
            </Card>
          </Gutter>
        </>
      ) : null}

      {/* ── Saved ──────────────────────────────────────────────────────── */}
      {(saved?.length ?? 0) > 0 ? (
        <>
          <Gutter>
            <SectionHeader title="Saved for later" />
          </Gutter>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.sm }}>
            {saved!.map((card) => (
              <ExperienceCardView key={card.id} card={card} variant="rail" surface="saved" />
            ))}
          </ScrollView>
        </>
      ) : null}

      {/* ── Neighbourhoods ─────────────────────────────────────────────── */}
      {destination && destination.neighbourhoods.length > 0 ? (
        <>
          <Gutter>
            <SectionHeader title="Neighbourhoods" caption="Places worth an afternoon in their own right" />
          </Gutter>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.sm }}>
            {destination.neighbourhoods.map((area) => (
              <Card key={area.id} style={{ width: 240 }} tone="elevated">
                <View style={{ padding: space.md, gap: 6 }}>
                  <Row justify="space-between">
                    <T variant="h3">{area.name}</T>
                    <T variant="caption" color={colors.text.tertiary}>
                      {Math.round(area.ideal_duration_minutes / 60)}h
                    </T>
                  </Row>
                  <T variant="small" color={colors.text.secondary} numberOfLines={3}>
                    {area.character}
                  </T>
                </View>
              </Card>
            ))}
          </ScrollView>
        </>
      ) : null}

      {/* ── City essentials ────────────────────────────────────────────── */}
      {destination ? (
        <Gutter style={{ marginTop: space.xxl }}>
          <Button label="City essentials" tone="secondary" onPress={() => router.push('/essentials')} />
        </Gutter>
      ) : null}

      {context ? (
        <Gutter style={{ marginTop: space.lg }}>
          <T variant="caption" color={colors.text.tertiary} align="center">
            Ranked by {context.engine_version}
          </T>
        </Gutter>
      ) : null}
    </Screen>
  );
}
