import React, { useState } from 'react';
import { ActivityIndicator, Pressable, TextInput, View } from 'react-native';

import { ApiError } from '../src/api/client';
import { useJourney, useMissionSuggestions, useUpdateJourney } from '../src/api/hooks';
import { DateRangeCalendar } from '../src/components/DateRangeCalendar';
import { Icon } from '../src/components/Icon';
import {
  Button,
  Chip,
  EmptyState,
  Gutter,
  Loading,
  Note,
  Row,
  Screen,
  SectionHeader,
  T,
} from '../src/components/primitives';
import { confirmAction } from '../src/lib/alert';
import { isoDate, stayRangeLabel } from '../src/lib/format';
import { useBackTo } from '../src/lib/navigation';
import { usePlanForDates } from '../src/lib/usePlanForDates';
import { FAMILIARITY, JOURNEY_REASONS, MORE_REASONS, missionSuggestionsFor } from '../src/lib/reasons';
import { useSession } from '../src/store/session';
import { radius, space, useTheme } from '../src/theme';

/**
 * Change what we understood (Figma: 24, reached from the trip screen).
 *
 * The trip screen shows the goals we inferred, and until now that was the end
 * of the conversation: the answers were given once, during onboarding, and a
 * traveller whose trip had changed shape — the work trip that became a holiday,
 * the city they turned out to know well — had no way to say so. They could see
 * our reading of the trip and not correct it, which is the worst of both: the
 * reasoning is visible and immovable.
 *
 * So this edits the answers, not the goals. The goals are derived server-side
 * from the purpose and the sentence, and re-derived on save; editing them
 * directly would let the two drift apart, and the next change to either would
 * silently overwrite whatever had been typed over the top.
 */
export default function Understanding() {
  const colors = useTheme();
  const goBack = useBackTo('/(tabs)/trip');
  const journeyId = useSession((s) => s.journeyId);
  const tripId = useSession((s) => s.tripId);

  const { data: journey, isLoading, isError, refetch } = useJourney(journeyId);
  const update = useUpdateJourney(journeyId ?? '');
  const { syncPlanToDates, working } = usePlanForDates(journeyId, tripId);

  /* Undefined means "not touched on this screen", which is what keeps the
     request to the fields that actually moved. */
  const [reason, setReason] = useState<string | undefined>();
  const [mission, setMission] = useState<string | undefined>();
  const [familiarity, setFamiliarity] = useState<string | undefined>();
  const [dates, setDates] = useState<{ startsOn: string | null; endsOn: string | null } | undefined>();
  const [expanded, setExpanded] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const currentReason = reason ?? journey?.reason;
  const currentMission = mission ?? journey?.mission_text ?? '';
  const currentFamiliarity = familiarity ?? journey?.familiarity;
  const currentStartsOn = dates ? dates.startsOn : (journey?.starts_on ?? null);
  const currentEndsOn = dates ? dates.endsOn : (journey?.ends_on ?? null);

  /* The journey's own city, not the session's. Switching city on Home leaves
     journeyId pointing at the old trip until onboarding creates a new one, so
     the session can name Paris while this screen is editing London. */
  const { data: suggested, isFetching: suggesting } = useMissionSuggestions(
    currentReason,
    journey?.destination.name,
    currentFamiliarity,
  );
  const suggestions = suggested?.suggestions?.length
    ? suggested.suggestions
    : missionSuggestionsFor(currentReason);

  /*
   * Open on the full list when the trip's own purpose lives in it.
   *
   * Onboarding offers the secondary reasons too, so a trip booked as a
   * festival or a honeymoon would otherwise arrive here with the short grid
   * collapsed and nothing selected — the screen would read as though we had
   * never been told, on the one screen whose job is to show what we were told.
   */
  const showAll =
    expanded || (currentReason != null && !JOURNEY_REASONS.some((r) => r.key === currentReason));

  /* A half-picked range is not a change yet — the traveller has tapped an
     arrival and not yet a departure, and sending that would store a one-day
     trip they did not ask for. */
  const datesChanged =
    dates !== undefined &&
    dates.startsOn !== null &&
    dates.endsOn !== null &&
    (dates.startsOn !== journey?.starts_on || dates.endsOn !== journey?.ends_on);

  const changed =
    datesChanged ||
    (reason !== undefined && reason !== journey?.reason) ||
    (mission !== undefined && mission.trim() !== (journey?.mission_text ?? '').trim()) ||
    (familiarity !== undefined && familiarity !== journey?.familiarity);

  /* Leaving is still one tap when nothing has been typed; it only asks when
     there is something to lose. The answers are short but not quick to
     reconstruct, and back is the habitual gesture on this screen. */
  const leave = () => {
    if (!changed) {
      goBack();
      return;
    }

    confirmAction('Discard your changes?', 'What you have changed here has not been saved yet.', {
      confirmLabel: 'Discard',
      cancelLabel: 'Keep editing',
      destructive: true,
      onConfirm: goBack,
    });
  };

  /* Drawn in every state, including the ones that fail. A screen whose only
     way out sits below the content is a screen you cannot leave when the
     content does not arrive. */
  const header = (
    <Gutter style={{ paddingTop: space.sm }}>
      <Pressable accessibilityRole="button" onPress={leave} hitSlop={12} accessibilityLabel="Back">
        <Icon name="back" size={24} color={colors.text.primary} />
      </Pressable>
      <T variant="displayL" style={{ marginTop: space.sm }}>
        What we understood
      </T>
    </Gutter>
  );

  if (!journeyId) {
    return (
      <Screen>
        {header}
        <Gutter>
          <EmptyState
            title="No trip to change yet"
            body="Once you have told us where you are going, your answers live here and can be changed at any time."
          />
        </Gutter>
      </Screen>
    );
  }

  if (isLoading) {
    return (
      <Screen>
        {header}
        <Loading />
      </Screen>
    );
  }

  if (isError || !journey) {
    return (
      <Screen>
        {header}
        <Gutter>
          <EmptyState title="Your trip did not load" body="Check your connection and try again." />
          <Button label="Try again" tone="secondary" onPress={() => refetch()} />
        </Gutter>
      </Screen>
    );
  }

  const save = async () => {
    setError(null);

    try {
      await update.mutateAsync({
        ...(reason !== undefined ? { reason } : {}),
        ...(familiarity !== undefined ? { familiarity } : {}),
        /* An emptied box is a deliberate "forget what I said", which the API
           accepts as null — sending '' would store a blank sentence and leave
           the goals derived from one. */
        ...(mission !== undefined ? { mission_text: mission.trim() || null } : {}),
        ...(datesChanged ? { starts_on: dates!.startsOn, ends_on: dates!.endsOn } : {}),
      });

      /* Moving the dates leaves the plan describing days that are no longer
         the trip. One place decides what to do about that, because Home can
         move them too: rebuild after asking when there is a plan, and simply
         build one when there is not. */
      if (datesChanged) {
        syncPlanToDates(
          { startsOn: journey.starts_on, endsOn: journey.ends_on },
          { onDone: goBack, onFailed: setError },
        );

        return;
      }

      goBack();
    } catch (cause) {
      setError(
        cause instanceof ApiError || cause instanceof Error
          ? cause.message
          : 'That did not save. Try again.',
      );
    }
  };

  const reasonOptions = showAll ? [...JOURNEY_REASONS, ...MORE_REASONS] : JOURNEY_REASONS;

  /* Today, or the day the trip began if that is already behind us. Someone on
     day three of a trip extending it by two days must still be able to reach
     their own start date; bounded at today it is disabled, and since any tap
     restarts the range they could never get back to it. */
  const today = isoDate(new Date(), journey.destination.timezone);
  const earliest = journey.starts_on && journey.starts_on < today ? journey.starts_on : today;

  return (
    <Screen>
      {header}

      <Gutter>
        <Note>
          These are the answers behind what we suggest. Change any of them and the plan is reasoned
          again from the new ones — nothing you have booked or fixed is touched.
        </Note>
      </Gutter>

      {/* ── Dates ────────────────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader
          title="When are you going"
          caption={
            currentStartsOn && currentEndsOn
              ? stayRangeLabel(currentStartsOn, currentEndsOn)
              : 'Pick the days you will be there'
          }
        />
        <DateRangeCalendar
          startsOn={currentStartsOn}
          endsOn={currentEndsOn}
          onChange={(startsOn, endsOn) => setDates({ startsOn, endsOn })}
          minDate={earliest}
        />
      </Gutter>

      {/* ── Purpose ──────────────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader title="What brings you here" caption="The single biggest thing we use" />
        <Row gap={space.xs} wrap>
          {reasonOptions.map((option) => {
            const selected = currentReason === option.key;

            return (
              <Pressable
                key={option.key}
                accessibilityRole="button"
                accessibilityState={{ selected }}
                onPress={() => setReason(option.key)}
                style={({ pressed }) => ({
                  width: '48%',
                  flexGrow: 1,
                  minHeight: 108,
                  padding: space.md,
                  borderRadius: radius.card,
                  backgroundColor: selected ? colors.action.primary : colors.background.elevated,
                  borderWidth: 1,
                  borderColor: selected ? colors.action.primary : colors.border.subtle,
                  gap: space.xs,
                  justifyContent: 'space-between',
                  opacity: pressed ? 0.9 : 1,
                })}
              >
                <Icon
                  name={option.icon}
                  size={22}
                  color={selected ? colors.text.onAccent : colors.text.secondary}
                  strokeWidth={1.8}
                />
                <View style={{ gap: 2 }}>
                  <T variant="bodyStrong" color={selected ? colors.text.onAccent : colors.text.primary}>
                    {option.label}
                  </T>
                  <T
                    variant="caption"
                    color={selected ? 'rgba(255,255,255,0.8)' : colors.text.tertiary}
                    numberOfLines={2}
                  >
                    {option.hint}
                  </T>
                </View>
              </Pressable>
            );
          })}
        </Row>
        {!showAll ? (
          <Pressable accessibilityRole="button" onPress={() => setExpanded(true)} hitSlop={8}>
            <T variant="small" color={colors.action.primary} style={{ marginTop: space.xs }}>
              More reasons
            </T>
          </Pressable>
        ) : null}
      </Gutter>

      {/* ── The sentence ─────────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader
          title="What would make this trip feel worth it"
          caption="Optional, and one sentence is plenty"
        />
        <TextInput
          accessibilityLabel="What would make this trip feel worth it"
          value={currentMission}
          onChangeText={setMission}
          /* What the API accepts, so an over-long paste is stopped here rather
             than coming back as a server validation string. */
          maxLength={1000}
          placeholder="Eat somewhere I could not find at home."
          placeholderTextColor={colors.text.tertiary}
          multiline
          style={{
            minHeight: 96,
            padding: space.md,
            borderRadius: radius.card,
            borderWidth: 1,
            borderColor: colors.border.subtle,
            backgroundColor: colors.background.elevated,
            color: colors.text.primary,
            textAlignVertical: 'top',
          }}
        />

        <Row gap={space.xs} style={{ marginTop: space.sm }}>
          <T variant="label" color={colors.text.tertiary} style={{ flex: 1 }}>
            Or start from one of these
          </T>
          {suggesting ? <ActivityIndicator size="small" color={colors.text.tertiary} /> : null}
        </Row>

        <View style={{ gap: space.xs, marginTop: space.xs }}>
          {suggestions.map((suggestion) => {
            const selected = currentMission.trim() === suggestion;

            return (
              <Pressable
                key={suggestion}
                accessibilityRole="button"
                accessibilityState={{ selected }}
                onPress={() => setMission(suggestion)}
                style={({ pressed }) => ({
                  paddingVertical: space.md,
                  paddingHorizontal: space.md,
                  borderRadius: radius.card,
                  borderWidth: 1,
                  borderColor: selected ? colors.action.primary : colors.border.subtle,
                  backgroundColor: selected ? colors.action.primary : colors.background.elevated,
                  opacity: pressed ? 0.9 : 1,
                })}
              >
                <T variant="body" color={selected ? colors.text.onAccent : colors.text.primary}>
                  {suggestion}
                </T>
              </Pressable>
            );
          })}
        </View>
      </Gutter>

      {/* ── Familiarity ──────────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader
          title={`How well do you know ${journey.destination.name}`}
          caption="We stop explaining the obvious once you know a place"
        />
        <Row gap={space.xs} wrap>
          {FAMILIARITY.map((option) => (
            <Chip
              key={option.key}
              label={option.label}
              selected={currentFamiliarity === option.key}
              onPress={() => setFamiliarity(option.key)}
            />
          ))}
        </Row>
      </Gutter>

      {error ? (
        <Gutter>
          <Note tone="warning">{error}</Note>
        </Gutter>
      ) : null}

      <Gutter style={{ marginTop: space.md, marginBottom: space.xxl }}>
        <Button
          label={working ? 'Building your plan…' : update.isPending ? 'Saving…' : 'Save changes'}
          onPress={save}
          disabled={!changed || update.isPending || working}
          loading={update.isPending || working}
        />
        <T variant="caption" color={colors.text.tertiary} style={{ marginTop: space.xs }}>
          {changed
            ? 'We will read the trip again from these answers.'
            : 'Nothing changed yet.'}
        </T>
      </Gutter>
    </Screen>
  );
}
