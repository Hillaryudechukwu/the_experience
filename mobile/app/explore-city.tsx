import React, { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, RefreshControl, ScrollView, View } from 'react-native';
import { useRouter } from 'expo-router';
import * as Location from 'expo-location';

import { recordEvents, useActivateDestination, useExploreCity } from '../src/api/hooks';
import type { ExperienceCard, ExploreRange, LiveEvent } from '../src/api/types';
import { ExperienceCardView } from '../src/components/ExperienceCard';
import { Icon } from '../src/components/Icon';
import { Photo } from '../src/components/Photo';
import {
  Button,
  Card,
  Chip,
  EmptyState,
  Gutter,
  LoadFailure,
  Note,
  Row,
  Screen,
  SectionHeader,
  Skeleton,
  T,
} from '../src/components/primitives';
import { clock, conditionLabel, dayLabel } from '../src/lib/format';
import { useBackTo } from '../src/lib/navigation';
import { useSession } from '../src/store/session';
import { radius, space, useTheme } from '../src/theme';

const RANGES: { key: ExploreRange; label: string; empty: string }[] = [
  { key: 'today', label: 'Today', empty: 'Nothing listed for the rest of today.' },
  { key: 'weekend', label: 'This weekend', empty: 'Nothing listed for this weekend yet.' },
  { key: 'week', label: 'This week', empty: 'Nothing listed for the coming week.' },
];

/** A fix younger than this is still where the phone is. */
const FRESH_FIX_MS = 2 * 60_000;

type Position = { lat: number; lng: number; precision: 'precise' | 'approximate' };
type Locating = 'checking' | 'ask' | 'locating' | 'denied' | 'failed' | 'ready';

/**
 * Explore my city: what is on, and what is always good, where the phone is.
 *
 * It follows the phone, not the trip, so it reads the position itself and
 * asks for permission here — with a reason on screen first — rather than on
 * Discover. Declining leaves the rest of the app exactly as it was.
 */
export default function ExploreCity() {
  const colors = useTheme();
  const router = useRouter();
  const goBack = useBackTo('/(tabs)');
  const session = useSession();
  const [locating, setLocating] = useState<Locating>('checking');
  const [position, setPosition] = useState<Position | null>(null);
  const [range, setRange] = useState<ExploreRange>('today');

  const explore = useExploreCity(position, range);
  const activate = useActivateDestination();

  /* "Approximate" is honoured before anything leaves the phone: about a
     kilometre is enough to find the city and what is on in it. */
  const precision = session.locationPrecision === 'approximate' ? 'approximate' : 'precise';

  const locate = useCallback(
    async (prompt: boolean) => {
      try {
        const permission = prompt
          ? await Location.requestForegroundPermissionsAsync()
          : await Location.getForegroundPermissionsAsync();

        if (permission.status !== 'granted') {
          setLocating(prompt ? 'denied' : 'ask');
          return;
        }

        setLocating('locating');

        /* Read now, not from the render that made this callback. */
        const current = useSession.getState();
        const recent: { lat: number; lng: number } | null =
          current.locationSource === 'device' &&
          current.coords &&
          typeof current.coordsAt === 'number' &&
          Date.now() - current.coordsAt < FRESH_FIX_MS
            ? current.coords
            : null;

        const coords: { lat: number; lng: number } =
          recent ??
          (await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced }).then((fix) => ({
            lat: fix.coords.latitude,
            lng: fix.coords.longitude,
          })));

        /* Keep the session current too, unless location is switched off in
           settings: then this one look is all the traveller agreed to. */
        if (!recent && current.locationPrecision !== 'off') {
          await current.setCoords(coords);
        }

        setPosition(
          precision === 'approximate'
            ? { lat: Math.round(coords.lat * 100) / 100, lng: Math.round(coords.lng * 100) / 100, precision }
            : { ...coords, precision },
        );
        setLocating('ready');
      } catch {
        setLocating('failed');
      }
    },
    [precision],
  );

  /* Silent on arrival: an existing grant is used, a missing one is asked for
     only once the traveller has read why and tapped. Location switched off in
     settings is treated as a missing grant, never overridden. */
  useEffect(() => {
    if (session.locationPrecision === 'off') {
      setLocating('ask');
      return;
    }
    void locate(false);
  }, [locate, session.locationPrecision]);

  const header = (
    <Gutter style={{ paddingTop: space.sm }}>
      <Row justify="space-between">
        <Pressable accessibilityRole="button" onPress={goBack} hitSlop={12} accessibilityLabel="Back">
          <Icon name="back" size={24} color={colors.text.primary} />
        </Pressable>
      </Row>
    </Gutter>
  );

  if (locating !== 'ready') {
    return (
      <Screen>
        {header}
        <Gutter>
          {locating === 'checking' || locating === 'locating' ? (
            <View style={{ gap: space.md, marginTop: space.lg }}>
              <T variant="h2">Finding where you are…</T>
              <Skeleton height={120} />
              <Skeleton height={120} />
            </View>
          ) : locating === 'ask' ? (
            <EmptyState
              title="Explore where you are"
              body="We use your location to work out which city you are in and show what is on near you today. It is used for this search and not kept as a history."
              action={<Button label="Use my location" onPress={() => void locate(true)} />}
            />
          ) : locating === 'denied' ? (
            <EmptyState
              title="Location is off"
              body="Without it we cannot tell which city you are in. Discover still works for the city you chose, and you can allow location in your phone's settings at any time."
              action={<Button label="Back to Discover" tone="secondary" onPress={() => router.replace('/(tabs)')} />}
            />
          ) : (
            <LoadFailure
              title="Could not read your location"
              body="Your phone did not give us a position. Step outside or try again in a moment."
              onRetry={() => void locate(false)}
            />
          )}
        </Gutter>
      </Screen>
    );
  }

  if (explore.isLoading) {
    return (
      <Screen>
        {header}
        <Gutter style={{ gap: space.md, marginTop: space.lg }}>
          <T variant="h2">Looking around…</T>
          <Skeleton height={160} />
          <Skeleton height={120} />
          <Skeleton height={120} />
        </Gutter>
      </Screen>
    );
  }

  if (explore.error || !explore.data) {
    return (
      <Screen>
        {header}
        <Gutter>
          <LoadFailure onRetry={() => explore.refetch()} />
        </Gutter>
      </Screen>
    );
  }

  const data = explore.data.data;
  const tz = data.city?.timezone;
  const place = data.city?.name ?? data.candidate?.name ?? null;
  const sections = data.sections;
  const emptyRange = RANGES.find((option) => option.key === range)!.empty;

  const prepare = async () => {
    if (!data.candidate) return;
    try {
      await activate.mutateAsync(data.candidate.candidate_token);
      await explore.refetch();
    } catch {
      /* The hook has already said why. */
    }
  };

  return (
    <Screen
      refreshControl={
        <RefreshControl refreshing={explore.isFetching} onRefresh={() => explore.refetch()} tintColor={colors.action.primary} />
      }
    >
      {header}

      <Gutter style={{ gap: space.xs, marginTop: space.md }}>
        <T variant="label" color={colors.text.tertiary}>
          Explore my city
        </T>
        <T variant="h1">{place ? `Exploring ${place}` : 'Exploring near you'}</T>
        {data.weather ? (
          <Row gap={space.xs}>
            <Icon name="weather" size={16} color={colors.text.secondary} />
            <T variant="small" color={colors.text.secondary}>
              {conditionLabel(data.weather.condition)}, {Math.round(data.weather.temperature_c)}°
              {data.weather.clothing?.headline ? ` · ${data.weather.clothing.headline}` : ''}
            </T>
          </Row>
        ) : null}
      </Gutter>

      {data.preparing ? (
        <Gutter style={{ marginTop: space.md }}>
          <Note tone="info">
            {data.preparing.code === 'destination_preparing'
              ? `We are getting ${place ?? 'this city'} ready. Things to do will appear here in a minute or two.`
              : `${place ?? 'This city'} is not ready to explore yet.`}
          </Note>
        </Gutter>
      ) : null}

      {data.candidate ? (
        <Gutter style={{ marginTop: space.md }}>
          <Card>
            <View style={{ gap: space.sm }}>
              <T variant="h3">We do not cover {data.candidate.name} yet</T>
              <T variant="body" color={colors.text.secondary}>
                We can gather its places now. It takes a few minutes, and this page fills in when it is done.
              </T>
              <Button label={`Prepare ${data.candidate.name}`} loading={activate.isPending} onPress={() => void prepare()} />
            </View>
          </Card>
        </Gutter>
      ) : null}

      {!data.city && !data.candidate ? (
        <Gutter style={{ marginTop: space.md }}>
          <Note>We could not tell which city you are in, so this shows only what is on nearby.</Note>
        </Gutter>
      ) : null}

      {/* ── What's on ────────────────────────────────────────────────────── */}
      {data.events_available ? (
        <>
          <Gutter>
            <SectionHeader title="What's on" caption="Gigs, games and shows near you" />
          </Gutter>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.xs }}>
            {RANGES.map((option) => (
              <Chip
                key={option.key}
                label={option.label}
                selected={range === option.key}
                onPress={() => setRange(option.key)}
              />
            ))}
          </ScrollView>
          {data.events.length > 0 ? (
            <ScrollView
              horizontal
              showsHorizontalScrollIndicator={false}
              contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.sm, paddingTop: space.sm }}
            >
              {data.events.map((event) => (
                <EventCard key={event.id} event={event} range={range} timeZone={tz} />
              ))}
            </ScrollView>
          ) : (
            <Gutter style={{ paddingTop: space.sm }}>
              <T variant="small" color={colors.text.tertiary}>
                {emptyRange}
              </T>
            </Gutter>
          )}
        </>
      ) : null}

      {/* ── Always good ──────────────────────────────────────────────────── */}
      {sections ? (
        <>
          <Rail title="Top picks right now" cards={sections.top_picks} surface="explore_city" setId={explore.data.recommendation_set_id} />
          <Rail title="Hidden gems" caption="Less obvious than the headline attractions" cards={sections.hidden_gems} surface="explore_city" />
          <Rail title="Free" cards={sections.free} surface="explore_city" />
          {sections.by_neighbourhood.map((area) => (
            <Rail key={area.neighbourhood} title={area.neighbourhood} caption="Worth the walk" cards={area.items} surface="explore_city" />
          ))}
          {sections.top_picks.length === 0 ? (
            <Gutter>
              <EmptyState
                title="Nothing open nearby right now"
                body="Pull down to look again later, or try the events above."
              />
            </Gutter>
          ) : null}
        </>
      ) : null}

      <View style={{ height: space.xxxl }} />
    </Screen>
  );
}

function Rail({
  title,
  caption,
  cards,
  surface,
  setId,
}: {
  title: string;
  caption?: string;
  cards: ExperienceCard[];
  surface: string;
  setId?: string | null;
}) {
  if (cards.length === 0) return null;

  return (
    <>
      <Gutter>
        <SectionHeader title={title} caption={caption} />
      </Gutter>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.sm }}>
        {cards.map((card) => (
          <ExperienceCardView
            key={card.id}
            card={card}
            variant="rail"
            surface={surface}
            recommendationSetId={setId ?? undefined}
          />
        ))}
      </ScrollView>
    </>
  );
}

/** One listing: picture, when, where, from how much. Taps out to the tickets. */
function EventCard({ event, range, timeZone }: { event: LiveEvent; range: ExploreRange; timeZone?: string }) {
  const colors = useTheme();
  const when = !event.time_known
    ? dayLabel(event.starts_at, timeZone)
    : range === 'today'
      ? clock(event.starts_at, timeZone)
      : `${dayLabel(event.starts_at, timeZone)}, ${clock(event.starts_at, timeZone)}`;
  const details = [event.venue?.name, distanceLabel(event.distance_metres), priceLabel(event)].filter(Boolean).join(' · ');

  const open = () => {
    if (!event.ticket_url) return;
    recordEvents([
      {
        type: 'event_ticket_opened',
        surface: 'explore_city',
        properties: { event_id: event.id, source: event.source, category: event.category },
      },
    ]);
    void Linking.openURL(event.ticket_url);
  };

  return (
    <Pressable
      accessibilityRole={event.ticket_url ? 'link' : undefined}
      accessibilityLabel={`${event.title}, ${when}${details ? `, ${details}` : ''}`}
      onPress={open}
      disabled={!event.ticket_url}
      style={({ pressed }) => ({
        width: 240,
        borderRadius: radius.card,
        overflow: 'hidden',
        backgroundColor: colors.background.elevated,
        opacity: pressed ? 0.85 : 1,
      })}
    >
      <Photo id={event.id} uri={event.image_url} category={event.category ?? undefined} height={120} showCredit={false} />
      <View style={{ padding: space.sm, gap: 4 }}>
        <T variant="label" color={colors.action.primary}>
          {when}
        </T>
        <T variant="h3" numberOfLines={2}>
          {event.title}
        </T>
        {details ? (
          <T variant="small" color={colors.text.secondary} numberOfLines={2}>
            {details}
          </T>
        ) : null}
        {event.ticket_url ? (
          <Row gap={4} style={{ marginTop: 2 }}>
            <Icon name="ticket" size={14} color={colors.text.tertiary} />
            <T variant="caption" color={colors.text.tertiary}>
              Tickets on {event.source === 'ticketmaster' ? 'Ticketmaster' : event.source}
            </T>
          </Row>
        ) : null}
      </View>
    </Pressable>
  );
}

function distanceLabel(metres: number | null): string | null {
  if (metres === null) return null;
  return metres < 1000 ? `${Math.round(metres / 50) * 50} m` : `${(metres / 1000).toFixed(1)} km`;
}

function priceLabel(event: LiveEvent): string | null {
  if (event.price_from_minor === null || !event.currency) return null;
  try {
    const format = new Intl.NumberFormat(undefined, { style: 'currency', currency: event.currency });
    const digits = format.resolvedOptions().maximumFractionDigits ?? 2;
    return `from ${format.format(event.price_from_minor / 10 ** digits)}`;
  } catch {
    return null;
  }
}
