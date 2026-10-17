import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Pressable, RefreshControl, ScrollView, View } from 'react-native';
import { useRouter } from 'expo-router';

import { ApiError, API_URL, http } from '../../src/api/client';
import { SURFACE_LABEL, useDestination, useDestinationImport, useDiscovery, useJourney, useProfile, useSaved, useSurpriseMe, useTrip, useUpdateJourney } from '../../src/api/hooks';
import { AnchorStrip, hasUpcomingAnchors } from '../../src/components/AnchorStrip';
import { DateRangeCalendar } from '../../src/components/DateRangeCalendar';
import { DestinationHero } from '../../src/components/DestinationHero';
import { QUICK_ACTIONS_OVERLAP, QuickActions } from '../../src/components/QuickActions';
import { ExperienceCardView } from '../../src/components/ExperienceCard';
import { Icon } from '../../src/components/Icon';
import { MOOD_CREDIT, MOODS, MoodTile } from '../../src/components/MoodTile';
import { Photo } from '../../src/components/Photo';
import { Sheet } from '../../src/components/Sheet';
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
import { clock, conditionLabel, isoDate, stayRangeLabel, timeOfDayGreeting, windowLabel } from '../../src/lib/format';
import { useRebuildPrompt } from '../../src/lib/useRebuildPrompt';
import { discoveryPayload, useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

function discoveryFailureMessage(error: unknown): string {
  if (error instanceof ApiError && error.status !== 0 && error.message.trim()) {
    return error.message;
  }

  const local = /^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])/i.test(API_URL);
  if (local) {
    return `Could not reach the API at ${API_URL}. Start it with "php artisan serve --host=0.0.0.0 --port=8099", then pull down to retry.`;
  }

  return `Could not reach the API at ${API_URL}. Check the connection, then pull down to retry.`;
}

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

  /*
   * Ask the device where we are, once, when the screen opens.
   *
   * Nothing outside onboarding ever did, so a granted permission stopped being
   * used the moment that screen was left. This never prompts — it reads the
   * existing grant — so a traveller who declined sees no difference.
   */
  const refreshLocation = session.refreshLocation;
  useEffect(() => {
    refreshLocation();
  }, [refreshLocation]);

  const [window, setWindow] = useState<number | null>(null);
  const [mood, setMood] = useState<string | null>(null);

  /* Trip dates, editable from here rather than two screens away. Undefined
     means untouched on this visit; the sheet works from the journey until
     then. */
  const [datesOpen, setDatesOpen] = useState(false);
  const [draftDates, setDraftDates] = useState<{ startsOn: string | null; endsOn: string | null } | undefined>();
  const [datesError, setDatesError] = useState<string | null>(null);

  const surface = window ? 'time-boxed' : mood ? 'mood' : 'now';
  /* What the API records this surface as, which is not what the route is
     called: the routes are hyphenated, the recorded names are snake_case. */
  const surfaceLabel = SURFACE_LABEL[surface];
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
  const { data: profile } = useProfile();
  const surprise = useSurpriseMe();
  const updateJourney = useUpdateJourney(session.journeyId ?? '');
  const { askToRebuild } = useRebuildPrompt(session.tripId);

  const draftStartsOn = draftDates ? draftDates.startsOn : (journey?.starts_on ?? null);
  const draftEndsOn = draftDates ? draftDates.endsOn : (journey?.ends_on ?? null);

  /* Today where they are going, or the day the trip began if that is already
     behind us — a trip under way must still be able to reach its own start. */
  const earliestDate = (() => {
    const today = isoDate(new Date(), journey?.destination.timezone);

    return journey?.starts_on && journey.starts_on < today ? journey.starts_on : today;
  })();

  /* A half-picked range is not a change: an arrival without a departure would
     store a one-day trip nobody asked for. */
  const datesChanged =
    draftDates !== undefined &&
    draftDates.startsOn !== null &&
    draftDates.endsOn !== null &&
    (draftDates.startsOn !== journey?.starts_on || draftDates.endsOn !== journey?.ends_on);

  const saveDates = async () => {
    if (!datesChanged || !journey) return;

    setDatesError(null);

    try {
      const previous = { startsOn: journey.starts_on, endsOn: journey.ends_on };
      await updateJourney.mutateAsync({ starts_on: draftDates!.startsOn, ends_on: draftDates!.endsOn });

      const close = () => {
        setDatesOpen(false);
        setDraftDates(undefined);
      };

      /* The plan may now be for days that are no longer the trip. Shared with
         the trip's edit screen so the question is asked the same way. */
      if (!askToRebuild(previous, { onDone: close, onFailed: setDatesError })) {
        close();
      }
    } catch (cause) {
      setDatesError(cause instanceof Error ? cause.message : 'That did not save. Try again.');
    }
  };
  const importProgress = useDestinationImport(session.destinationImportId);

  const setDestinationImport = session.setDestinationImport;
  const refetchDiscovery = discovery.refetch;

  /* Empty catalogue cities queue an import on first Discover hit (409 preparing). */
  useEffect(() => {
    const err = discovery.error;
    if (!(err instanceof ApiError) || err.status !== 409) return;
    const body = err.body as { code?: string; import_id?: string } | null;
    if (body?.code === 'destination_preparing' && body.import_id) {
      void setDestinationImport(body.import_id);
    }
  }, [discovery.error, setDestinationImport]);

  useEffect(() => {
    const status = importProgress.data?.status;
    if (status === 'succeeded' || status === 'partial') {
      void refetchDiscovery();
      void setDestinationImport(null);
    }
  }, [importProgress.data?.status, refetchDiscovery, setDestinationImport]);

  const preparingCity =
    !!session.destinationImportId &&
    (!importProgress.data || ['queued', 'running'].includes(importProgress.data.status));

  const context = discovery.data?.context;
  const results = discovery.data?.data ?? [];
  const [top, ...rest] = results;

  const todaysItems = useMemo(() => {
    /* The same call onboarding uses to set starts_on. Two copies of this rule
       drift apart silently: the itinerary's first day simply stops matching. */
    const today = isoDate(new Date(), tz);

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
            action={<Button label="Choose a city" onPress={() => router.push('/onboarding/destination')} />}
          />
        </Gutter>
      </Screen>
    );
  }

  const greeting = `${timeOfDayGreeting(context ? new Date(context.local_time) : undefined)}${
    profile?.display_name ? `, ${profile.display_name.split(' ')[0]}` : ''
  }`;

  const heroHeadline = context?.window_minutes
    ? `You have ${windowLabel(context.window_minutes)}${context.next_anchor ? ` before ${context.next_anchor.title.toLowerCase()}` : ''}.`
    : window
      ? `${windowLabel(window)} to spend.`
      : 'Your day is open.';

  const emptyCatalogue = !discovery.isLoading && results.length === 0 && discovery.data?.catalogue_empty === true;
  const primary = discovery.data?.primary ?? null;
  const precision = context?.location_precision;
  const nearYou = precision === 'precise' || precision === 'approximate';

  const heroDetail = discovery.isLoading || preparingCity
    ? preparingCity
      ? 'Preparing this city — finding places worth recommending…'
      : 'Working out what fits…'
    : primary
      ? nearYou
        ? primary.travel_minutes
          ? `${primary.action} ${primary.place_name}, ${primary.travel_minutes} min away.`
          : `${primary.action} ${primary.place_name}.`
        : `${primary.action} In ${session.destinationName ?? 'the city'}.`
      : results.length > 0
        ? `I found ${results.length} experience${results.length === 1 ? '' : 's'} that fit.`
        : emptyCatalogue
          ? 'This city does not have experiences loaded yet — try another covered city for now.'
          : 'Nothing strong fits right now — try widening the time or distance.';

  return (
    <Screen
      edges={['left', 'right']}
      refreshControl={
        <RefreshControl
          refreshing={discovery.isFetching}
          onRefresh={() => discovery.refetch()}
          tintColor={colors.action.primary}
        />
      }
    >
      {/* ── City header (Figma: 3, 12) ─────────────────────────────────
          Photograph of where the traveller actually is, carrying the city
          switch, the weather, the local time and the one sentence that says
          what the next stretch of the trip is good for. */}
      <DestinationHero
        city={session.destinationName ?? 'Your city'}
        imageUri={destination?.hero_image_url}
        attribution={destination?.hero_image_attribution}
        greeting={greeting}
        headline={heroHeadline}
        detail={heroDetail}
        timeLabel={context ? clock(context.local_time, tz) : null}
        dateLabel={context ? dateStamp(context.local_time, tz) : null}
        temperature={context?.weather?.temperature_c ?? null}
        condition={context?.weather ? conditionLabel(context.weather.condition) : null}
        clothing={context?.weather?.clothing?.headline ?? null}
        onPressCity={() => router.push('/onboarding/destination')}
        onPressProfile={() => router.push('/(tabs)/you')}
        onPressSearch={() => router.push('/search')}
        overlapBelow={QUICK_ACTIONS_OVERLAP}
      />

      <QuickActions
        actions={[
          { key: 'nearby', label: 'Nearby', icon: 'location', onPress: () => router.push('/search') },
          {
            key: 'for-you',
            label: 'For you',
            icon: 'sparkle',
            onPress: () => {
              void clearIntent(['mood', 'window_minutes']).then(() => {
                setWindow(null);
                setMood(null);
              });
            },
          },
          { key: 'open-now', label: 'Open now', icon: 'clock', onPress: () => setWindow(60) },
          { key: 'map', label: 'Map', icon: 'map', onPress: () => router.push('/(tabs)/map') },
        ]}
      />

      {/* ── Anchor strip ───────────────────────────────────────────────── */}
      {journey && hasUpcomingAnchors(journey.anchors) ? (
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
            {discoveryFailureMessage(discovery.error)}
          </Note>
        </Gutter>
      ) : null}

      {/* ── Quick time ─────────────────────────────────────────────────── */}
      <Gutter>
        {/* Two questions now: how long today, and when the trip is. */}
        <SectionHeader title="What are you working with?" />
      </Gutter>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.xs }}>
        <Chip
          label="Right now"
          selected={window === null && mood === null}
          onPress={() => {
            void clearIntent(['mood', 'window_minutes']).then(() => {
              setWindow(null);
              setMood(null);
            });
          }}
        />
        {TIME_WINDOWS.map((option) => (
          <Chip
            key={option.minutes}
            label={option.label}
            selected={window === option.minutes}
            onPress={() => {
              if (window === option.minutes) {
                void clearIntent(['window_minutes']).then(() => setWindow(null));
                return;
              }
              void clearIntent(['mood']).then(() => {
                setMood(null);
                setWindow(option.minutes);
              });
            }}
          />
        ))}

        {/* An action, not a filter. Every other chip here toggles something
            and stays lit; this one opens a sheet, so it never takes the
            selected state — a chip that looked switched on and was not would
            read as broken. */}
        {journey ? (
          <Chip
            label={
              journey.starts_on && journey.ends_on
                ? stayRangeLabel(journey.starts_on, journey.ends_on)
                : 'Add dates'
            }
            tone="accent"
            icon={<Icon name="calendar" size={14} color={colors.action.primary} />}
            onPress={() => {
              setDatesError(null);
              setDraftDates(undefined);
              setDatesOpen(true);
            }}
          />
        ) : null}
      </ScrollView>

      {/* ── Top picks (Figma: 3) ───────────────────────────────────────
          The headline rail: the strongest card in full, then the rest as
          feature cards the traveller can flick through. The heading names the
          part of the day rather than the algorithm, because that is the
          question being answered. */}
      <Gutter>
        <SectionHeader
          title={
            window
              ? `Best use of ${windowLabel(window)}`
              : mood
                ? 'Closest to your mood'
                : `Top picks for your ${partOfDay(context?.local_time, tz)}`
          }
          caption={
            discovery.data ? `Chosen from ${discovery.data.candidates_considered} nearby options` : undefined
          }
          action={window || mood ? 'Clear' : results.length > 0 ? 'See all' : undefined}
          onAction={() => {
            if (window || mood) {
              void clearIntent(['mood', 'window_minutes']).then(() => {
                setWindow(null);
                setMood(null);
              });
              return;
            }
            router.push('/search');
          }}
        />
      </Gutter>

      <Gutter>
        {discovery.isLoading || preparingCity ? (
          <Card level="card">
            <View style={{ padding: space.md, gap: space.sm }}>
              <Skeleton height={140} />
              <Skeleton height={20} width="70%" />
              <Skeleton height={14} width="45%" />
              {preparingCity ? (
                <T variant="small" color={colors.text.secondary}>
                  {importProgress.data?.message ?? 'Loading experiences for this city…'}
                </T>
              ) : null}
            </View>
          </Card>
        ) : null}

        {discovery.data?.notice ? (
          <View style={{ marginBottom: space.sm }}>
            <Note tone="info">{discovery.data.notice}</Note>
          </View>
        ) : null}

        {top && !preparingCity ? (
          <ExperienceCardView
            card={top}
            variant="recommendation"
            recommendationSetId={discovery.data?.recommendation_set_id}
            surface={surfaceLabel}
          />
        ) : null}

        {!discovery.isLoading && !preparingCity && results.length === 0 && !discovery.isError ? (
          <EmptyState
            title={emptyCatalogue ? 'Nothing loaded for this city yet' : 'Nothing strong fits all of that'}
            body={
              emptyCatalogue
                ? 'We have the city on the map, but no experiences to recommend yet. Try London, Rome, New York or Tokyo — or check back once this catalogue is filled.'
                : 'Loosening the time, the budget or the distance usually opens it up.'
            }
          />
        ) : null}
      </Gutter>

      {rest.length > 0 ? (
        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.sm, paddingVertical: 2 }}
        >
          {rest.slice(0, 6).map((card) => (
            <ExperienceCardView
              key={card.id}
              card={card}
              variant="feature"
              recommendationSetId={discovery.data?.recommendation_set_id}
              surface={surfaceLabel}
            />
          ))}
        </ScrollView>
      ) : null}

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
              if (mood === option.key) {
                void clearIntent(['mood']).then(() => setMood(null));
                return;
              }
              void clearIntent(['window_minutes']).then(() => {
                setWindow(null);
                setMood(option.key);
              });
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
      <Gutter>
        <T variant="caption" color={colors.text.tertiary} style={{ marginTop: space.xs }}>
          {MOOD_CREDIT}
        </T>
      </Gutter>

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
              <Card key={area.id} style={{ width: 240, overflow: 'hidden' }} tone="elevated">
                <Photo
                  id={area.id}
                  uri={area.image_url}
                  attribution={area.image_attribution}
                  height={124}
                  creditAlign="top"
                />
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

      {journey ? (
        <Sheet visible={datesOpen} onClose={() => setDatesOpen(false)} title="When are you going">
          <View style={{ padding: space.lg, gap: space.md }}>
            <DateRangeCalendar
              startsOn={draftStartsOn}
              endsOn={draftEndsOn}
              onChange={(startsOn, endsOn) => setDraftDates({ startsOn, endsOn })}
              minDate={earliestDate}
              maxSpanDays={14}
            />

            {datesError ? <Note tone="warning">{datesError}</Note> : null}

            <Button
              label={updateJourney.isPending ? 'Saving…' : 'Save dates'}
              onPress={saveDates}
              disabled={!datesChanged || updateJourney.isPending}
              loading={updateJourney.isPending}
            />
            <T variant="caption" color={colors.text.tertiary} align="center">
              {draftStartsOn && !draftEndsOn
                ? 'Pick the day you leave.'
                : 'Changing these rebuilds nothing until you say so.'}
            </T>
          </View>
        </Sheet>
      ) : null}
    </Screen>
  );
}

/** "Mon 12 May" — the design's date stamp, short enough to sit beside the clock. */
function dateStamp(iso: string, timeZone?: string): string {
  return new Date(iso).toLocaleDateString([], {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    ...(timeZone ? { timeZone } : {}),
  });
}

/** "afternoon", "morning", "evening" — what the picks are actually for. */
function partOfDay(iso: string | undefined, timeZone?: string): string {
  const hour = Number(
    new Date(iso ?? Date.now()).toLocaleString('en-GB', {
      hour: '2-digit',
      hour12: false,
      ...(timeZone ? { timeZone } : {}),
    }),
  );

  if (hour < 12) return 'morning';
  if (hour < 18) return 'afternoon';

  return 'evening';
}

function clearIntent(fields: string[]) {
  return http.post('/discovery/intent', discoveryPayload({ clear: fields }));
}
