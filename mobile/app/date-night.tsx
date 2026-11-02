import React, { useMemo, useState } from 'react';
import { Pressable, RefreshControl, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';

import { ApiError } from '../src/api/client';
import { useDateNight, useSaveDateNight, useTrip } from '../src/api/hooks';
import type { DateNightOptions, DateNightRefusal, DateNightRole, DateNightStop } from '../src/api/types';
import { addDays } from '../src/components/DateNightSheet';
import { ExperienceCardView } from '../src/components/ExperienceCard';
import { Icon } from '../src/components/Icon';
import {
  Button,
  EmptyState,
  Gutter,
  LoadFailure,
  Note,
  Row,
  Screen,
  Skeleton,
  T,
} from '../src/components/primitives';
import { clock, conditionLabel, dayLabel, isoDate } from '../src/lib/format';
import { useBackTo } from '../src/lib/navigation';
import { showAlert } from '../src/lib/alert';
import { useSession } from '../src/store/session';
import { space, useTheme } from '../src/theme';

const ROLE_LABEL: Record<DateNightRole, string> = {
  opener: 'To start',
  dinner: 'Dinner',
  nightcap: 'Nightcap',
};

/**
 * A date night, stop by stop.
 *
 * Everything needed to rebuild it is in the route, so a refresh, a
 * back-and-forth to an experience, or a deep link lands on the same evening.
 * A swap stays on this screen: the alternatives came with the plan, scored for
 * the same slot, so trying one costs no request.
 */
export default function DateNight() {
  const colors = useTheme();
  const router = useRouter();
  const goBack = useBackTo('/(tabs)');
  const session = useSession();
  const tz = session.destinationTimezone ?? undefined;
  const params = useLocalSearchParams<{ date?: string; starts_at?: string; budget?: string; nightcap?: string }>();

  const today = isoDate(new Date(), tz);
  const options = useMemo<DateNightOptions | null>(
    () =>
      params.date
        ? {
            date: params.date,
            ...(params.starts_at ? { starts_at: params.starts_at } : {}),
            ...(params.budget ? { budget: params.budget as DateNightOptions['budget'] } : {}),
            include_nightcap: params.nightcap !== '0',
          }
        : null,
    [params.date, params.starts_at, params.budget, params.nightcap],
  );

  const plan = useDateNight(options, { nearMe: params.date === today });
  const { data: trip } = useTrip(session.tripId);
  const itineraryId = trip?.itinerary?.id ?? null;
  const saveDateNight = useSaveDateNight(session.tripId, itineraryId);

  /* Which alternative each slot is showing; 0 is the planner's own pick. */
  const [choice, setChoice] = useState<Partial<Record<DateNightRole, number>>>({});
  const [saved, setSaved] = useState<{ reminderAt: string | null } | null>(null);

  const header = (
    <Gutter style={{ paddingTop: space.sm }}>
      <Row justify="space-between">
        <Pressable accessibilityRole="button" onPress={goBack} hitSlop={12} accessibilityLabel="Back">
          <Icon name="back" size={24} color={colors.text.primary} />
        </Pressable>
      </Row>
    </Gutter>
  );

  if (!options) {
    return (
      <Screen>
        {header}
        <Gutter>
          <EmptyState
            title="Which evening?"
            body="Pick a date night from Discover and we will plan it here."
            action={<Button label="Back to Discover" onPress={() => router.replace('/(tabs)')} />}
          />
        </Gutter>
      </Screen>
    );
  }

  if (plan.isLoading) {
    return (
      <Screen>
        {header}
        <Gutter style={{ gap: space.md, marginTop: space.lg }}>
          <T variant="h2">Planning your evening…</T>
          <Skeleton height={120} />
          <Skeleton height={120} />
          <Skeleton height={120} />
        </Gutter>
      </Screen>
    );
  }

  if (plan.error) {
    const refusal = refusalOf(plan.error);

    return (
      <Screen>
        {header}
        <Gutter>
          {refusal ? (
            <EmptyState
              title="That evening will not work"
              body={refusal.message}
              action={
                refusal.code === 'date_night_too_late' && options.date === today ? (
                  <Button
                    label="Plan tomorrow instead"
                    onPress={() => router.setParams({ date: addDays(today, 1) })}
                  />
                ) : (
                  <Button label="Choose another evening" tone="secondary" onPress={goBack} />
                )
              }
            />
          ) : (
            <LoadFailure onRetry={() => plan.refetch()} />
          )}
        </Gutter>
      </Screen>
    );
  }

  const evening = plan.data?.data ?? null;

  if (!evening) {
    return (
      <Screen>
        {header}
        <Gutter>
          <EmptyState
            title="Nothing fits that evening"
            body={plan.data?.notice ?? 'An earlier start or a bigger budget would open more up.'}
            action={<Button label="Change the evening" tone="secondary" onPress={goBack} />}
          />
        </Gutter>
      </Screen>
    );
  }

  const shown = evening.stops.map((stop) => ({ stop, card: cardFor(stop, choice[stop.role] ?? 0) }));

  const saveToTrip = async () => {
    try {
      const result = await saveDateNight.mutateAsync({
        date: evening.date,
        stops: shown.map(({ stop, card }) => ({
          role: stop.role,
          experience_id: card.id,
          starts_at: stop.starts_at,
          ends_at: stop.ends_at,
        })),
      });
      setSaved({ reminderAt: result.reminder_at });
    } catch (cause) {
      showAlert('This evening was not added', cause instanceof Error ? cause.message : 'Try again in a moment.');
    }
  };

  return (
    <Screen
      refreshControl={
        <RefreshControl refreshing={plan.isFetching} onRefresh={() => plan.refetch()} tintColor={colors.action.primary} />
      }
    >
      {header}

      <Gutter style={{ gap: space.xs, marginTop: space.md }}>
        <T variant="label" color={colors.text.tertiary}>
          Date night
        </T>
        <T variant="h1">
          {/* The plan's own end, not the window it was planned in: an evening
              that wraps up at 22:20 should not read as running to 23:00. */}
          {dayLabel(evening.starts_at, tz)}, {clock(evening.stops[0].starts_at, tz)}–
          {clock(evening.stops[evening.stops.length - 1].ends_at, tz)}
        </T>
        {evening.weather ? (
          <Row gap={space.xs}>
            <Icon name="weather" size={16} color={colors.text.secondary} />
            <T variant="small" color={colors.text.secondary}>
              {conditionLabel(evening.weather.condition)}, {Math.round(evening.weather.temperature_c)}°
              {evening.weather.clothing?.headline ? ` · ${evening.weather.clothing.headline}` : ''}
            </T>
          </Row>
        ) : evening.weather_note ? (
          <T variant="small" color={colors.text.tertiary}>
            {evening.weather_note}
          </T>
        ) : null}
      </Gutter>

      {plan.data?.notice ? (
        <Gutter style={{ marginTop: space.md }}>
          <Note tone="warning">{plan.data.notice}</Note>
        </Gutter>
      ) : null}

      <Gutter style={{ marginTop: space.lg, gap: space.md }}>
        {shown.map(({ stop, card }, index) => (
          <View key={stop.role} style={{ gap: space.xs }}>
            {index > 0 && stop.travel_minutes_from_previous ? (
              <Row gap={space.xs}>
                <Icon
                  name={stop.travel_mode === 'walk' ? 'walk' : 'transit'}
                  size={14}
                  color={colors.text.tertiary}
                />
                <T variant="caption" color={colors.text.tertiary}>
                  {stop.travel_minutes_from_previous} min {stop.travel_mode === 'walk' ? 'walk' : 'away'}
                </T>
              </Row>
            ) : null}

            <Row justify="space-between" align="flex-end">
              <View>
                <T variant="label" color={colors.text.tertiary}>
                  {ROLE_LABEL[stop.role]}
                </T>
                <T variant="bodyStrong">
                  {clock(stop.starts_at, tz)}–{clock(stop.ends_at, tz)}
                </T>
              </View>
              {stop.alternatives.length > 0 ? (
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`Swap ${ROLE_LABEL[stop.role].toLowerCase()}`}
                  hitSlop={10}
                  onPress={() =>
                    setChoice((current) => ({
                      ...current,
                      [stop.role]: ((current[stop.role] ?? 0) + 1) % (stop.alternatives.length + 1),
                    }))
                  }
                >
                  <T variant="smallStrong" color={colors.action.primary}>
                    Swap
                  </T>
                </Pressable>
              ) : null}
            </Row>

            <ExperienceCardView
              card={card}
              variant="compact"
              recommendationSetId={plan.data?.recommendation_set_id}
              surface="date_night"
              onPress={() => router.push(`/experience/${card.id}`)}
            />
          </View>
        ))}
      </Gutter>

      <Gutter style={{ marginTop: space.xl, marginBottom: space.xxl, gap: space.xs }}>
        {itineraryId ? (
          <>
            <Button
              label={saved ? 'Added to your trip' : 'Add to my trip'}
              disabled={saved !== null}
              loading={saveDateNight.isPending}
              onPress={saveToTrip}
            />
            {saved?.reminderAt ? (
              <T variant="small" color={colors.text.secondary}>
                We will remind you at {clock(saved.reminderAt, tz)} on the day.
              </T>
            ) : null}
          </>
        ) : (
          <T variant="small" color={colors.text.secondary}>
            Start a trip plan to save this evening to it.
          </T>
        )}
      </Gutter>
    </Screen>
  );
}

/** The planner's pick, or one of its alternatives for the same slot. */
function cardFor(stop: DateNightStop, index: number) {
  return index === 0 ? stop.experience : (stop.alternatives[index - 1] ?? stop.experience);
}

function refusalOf(error: unknown): { code: DateNightRefusal; message: string } | null {
  if (!(error instanceof ApiError) || error.status !== 422) return null;
  const body = error.body as { code?: DateNightRefusal; message?: string } | null;

  return body?.code ? { code: body.code, message: body.message ?? error.message } : null;
}
