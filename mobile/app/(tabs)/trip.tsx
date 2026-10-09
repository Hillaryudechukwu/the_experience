import React, { useState } from 'react';
import { Alert, View } from 'react-native';
import { useRouter } from 'expo-router';

import {
  useAcceptReplan,
  useCreateTrip,
  useGenerateItinerary,
  useJourney,
  useReplan,
  useTrip,
  type ReplanResult,
} from '../../src/api/hooks';
import type { ItineraryItem } from '../../src/api/types';
import { Icon } from '../../src/components/Icon';
import { Sheet } from '../../src/components/Sheet';
import { StatusPill } from '../../src/components/StatusPill';
import {
  Button,
  Card,
  Divider,
  EmptyState,
  Gutter,
  Loading,
  Note,
  Row,
  Screen,
  SectionHeader,
  T,
} from '../../src/components/primitives';
import { toFitReasons, WhyThisFits } from '../../src/components/WhyThisFits';
import {
  buildPlanCtaLabel,
  clock,
  dayLabel,
  stayDayCount,
  stayRangeLabel,
  titleCase,
} from '../../src/lib/format';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

/**
 * Trip timeline (Figma: 24–28).
 *
 * Time is the organising principle, not category. Anchors read as solid and
 * locked; the gaps between them read as invitations, because the free window is
 * the only part the traveller can actually act on.
 */
export default function TripScreen() {
  const colors = useTheme();
  const router = useRouter();
  const session = useSession();

  const { data: journey } = useJourney(session.journeyId);
  const { data: trip, isLoading } = useTrip(session.tripId);
  const tz = journey?.destination.timezone;

  const generate = useGenerateItinerary(session.tripId);
  const replan = useReplan(session.tripId);
  const accept = useAcceptReplan(session.tripId);
  const createTrip = useCreateTrip();

  const [proposal, setProposal] = useState<ReplanResult | null>(null);

  if (!session.journeyId) {
    return (
      <Screen>
        <Gutter style={{ paddingTop: space.sm }}>
          <T variant="displayL">Trip</T>
          <EmptyState
            title="Your day is open"
            body="Tell us why you are here and I will build something around your plans."
            action={<Button label="Set up a journey" onPress={() => router.push('/onboarding/purpose')} />}
          />
        </Gutter>
      </Screen>
    );
  }

  const build = async () => {
    try {
      if (!session.tripId) {
        const created = await createTrip.mutateAsync(session.journeyId!);
        await session.setTrip(created.id);
      }
      await generate.mutateAsync({});
    } catch (cause) {
      Alert.alert('Could not build the plan', cause instanceof Error ? cause.message : 'Try again.');
    }
  };

  const askForReplan = async () => {
    try {
      setProposal(await replan.mutateAsync());
    } catch (cause) {
      Alert.alert('Could not replan', cause instanceof Error ? cause.message : 'Try again.');
    }
  };

  const itinerary = trip?.itinerary;
  const days = stayDayCount(journey?.starts_on, journey?.ends_on);
  const multiDay = days !== null && days > 1;

  return (
    <Screen>
      {/* ── Header ─────────────────────────────────────────────────────── */}
      <Gutter style={{ paddingTop: space.sm }}>
        <T variant="displayL">{journey?.destination.name ?? 'Your trip'}</T>
        {journey ? (
          <Row gap={space.xs} wrap style={{ marginTop: 4 }}>
            <T variant="small" color={colors.text.secondary}>
              {journey.reason_label}
            </T>
            <T variant="small" color={colors.text.tertiary}>
              ·
            </T>
            <T variant="small" color={colors.text.secondary}>
              {journey.adults} adult{journey.adults === 1 ? '' : 's'}
              {journey.children > 0 ? `, ${journey.children} child${journey.children === 1 ? '' : 'ren'}` : ''}
            </T>
            {days !== null && journey.starts_on && journey.ends_on ? (
              <>
                <T variant="small" color={colors.text.tertiary}>
                  ·
                </T>
                <T variant="small" color={colors.text.secondary}>
                  {days} day{days === 1 ? '' : 's'} ({stayRangeLabel(journey.starts_on, journey.ends_on)})
                </T>
              </>
            ) : null}
          </Row>
        ) : null}
      </Gutter>

      {/* ── What we understood ─────────────────────────────────────────── */}
      {journey?.mission_goals && journey.mission_goals.length > 0 ? (
        <Gutter>
          <SectionHeader title="What we understood" caption="From what you told us about this trip" />
          <Card tone="warm">
            <View style={{ padding: space.md, gap: space.sm }}>
              {journey.mission_goals.slice(0, 4).map((goal) => (
                <Row key={goal.goal} align="flex-start" gap={space.xs}>
                  <Icon name="sparkle" size={15} color={colors.text.secondary} />
                  <View style={{ flex: 1 }}>
                    <T variant="bodyStrong">{titleCase(goal.goal)}</T>
                    <T variant="small" color={colors.text.secondary}>
                      {goal.evidence}
                    </T>
                  </View>
                </Row>
              ))}
            </View>
          </Card>
        </Gutter>
      ) : null}

      {/* ── Fixed commitments ──────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader title="Fixed commitments" action="Add" onAction={() => router.push('/anchors')} />
        {journey && journey.anchors.length === 0 ? (
          <Note icon={<Icon name="lock" size={16} color={colors.text.secondary} />}>
            Nothing fixed yet. Add flights, meetings or dinner bookings and the plan will be built around them
            rather than over them.
          </Note>
        ) : (
          <Card>
            <View style={{ padding: space.md, gap: space.md }}>
              {journey?.anchors.map((anchor, index) => (
                <React.Fragment key={anchor.id}>
                  {index > 0 ? <Divider /> : null}
                  <Row gap={space.sm} align="flex-start">
                    <View
                      style={{
                        width: 36,
                        height: 36,
                        borderRadius: radius.compact,
                        backgroundColor: colors.background.sunken,
                        alignItems: 'center',
                        justifyContent: 'center',
                      }}
                    >
                      <Icon name="lock" size={16} color={colors.text.secondary} />
                    </View>
                    <View style={{ flex: 1 }}>
                      <T variant="bodyStrong">{anchor.title}</T>
                      <T variant="small" color={colors.text.secondary}>
                        {dayLabel(anchor.starts_at, tz)} · {clock(anchor.starts_at, tz)}–{clock(anchor.ends_at, tz)}
                      </T>
                      <T variant="caption" color={colors.text.tertiary}>
                        Nothing scheduled after {clock(anchor.protected_from, tz)}
                      </T>
                    </View>
                  </Row>
                </React.Fragment>
              ))}
            </View>
          </Card>
        )}
      </Gutter>

      {/* ── Plan ───────────────────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader
          title="Your plan"
          action={itinerary ? 'Rebuild' : undefined}
          onAction={itinerary ? build : undefined}
        />
      </Gutter>

      {isLoading ? <Loading /> : null}

      {!itinerary && !isLoading ? (
        <Gutter>
          <EmptyState
            title="Nothing built yet"
            body={
              multiDay
                ? 'I will fit realistic options across your stay, around your commitments, with travel time and opening hours accounted for.'
                : 'I will fit realistic options into the gaps between your commitments, with travel time and opening hours accounted for.'
            }
            action={
              <Button
                label={buildPlanCtaLabel(days, generate.isPending)}
                onPress={build}
                loading={generate.isPending}
                haptic="medium"
              />
            }
          />
        </Gutter>
      ) : null}

      {itinerary?.days.map((day) => (
        <Gutter key={day.id} style={{ marginTop: space.lg }}>
          <Row justify="space-between" align="flex-end" style={{ marginBottom: space.sm }}>
            <T variant="h2">{dayLabel(day.date, tz)}</T>
            <T variant="caption" color={colors.text.tertiary}>
              {day.summary}
            </T>
          </Row>

          <Card>
            <View style={{ padding: space.md }}>
              {day.items.map((item, index) => (
                <TimelineRow
                  key={item.id}
                  item={item}
                  timezone={tz}
                  first={index === 0}
                  last={index === day.items.length - 1}
                  onOpen={() => item.experience && router.push(`/experience/${item.experience.id}`)}
                />
              ))}
            </View>
          </Card>
        </Gutter>
      ))}

      {itinerary ? (
        <Gutter style={{ marginTop: space.lg }}>
          <Button
            label={replan.isPending ? 'Checking…' : 'Should I change anything?'}
            tone="secondary"
            onPress={askForReplan}
            loading={replan.isPending}
          />
          <T variant="caption" color={colors.text.tertiary} align="center" style={{ marginTop: space.sm }}>
            Version {itinerary.version} · {itinerary.engine_version}
          </T>
        </Gutter>
      ) : null}

      {/* ── Replan sheet (Figma: 28) ───────────────────────────────────── */}
      <Sheet visible={!!proposal} onClose={() => setProposal(null)} title="Suggested changes">
        {proposal ? (
          <View style={{ gap: space.md }}>
            <Note tone={proposal.changes.length > 0 ? 'warning' : 'success'}>{proposal.message}</Note>

            {proposal.trigger ? (
              <Row gap={space.xs}>
                <Icon name="weather" size={16} color={colors.text.secondary} />
                <T variant="small" color={colors.text.secondary}>
                  Triggered by {proposal.trigger.type}
                  {proposal.trigger.condition ? `: ${proposal.trigger.condition.replace(/_/g, ' ')}` : ''}
                </T>
              </Row>
            ) : null}

            {proposal.changes.length > 0 ? (
              <Card tone="sunken" bordered={false} level="none">
                <View style={{ padding: space.md, gap: space.xs }}>
                  <T variant="label" color={colors.text.tertiary}>
                    What would change
                  </T>
                  {proposal.changes.slice(0, 8).map((change, index) => (
                    <Row key={`${change.title}-${index}`} gap={space.xs} align="flex-start">
                      <Icon
                        name={change.direction === 'added' ? 'plus' : change.direction === 'removed' ? 'close' : 'chevron'}
                        size={14}
                        color={
                          change.direction === 'added'
                            ? colors.status.open
                            : change.direction === 'removed'
                              ? colors.status.error
                              : colors.text.secondary
                        }
                      />
                      <T variant="small" color={colors.text.primary} style={{ flex: 1 }}>
                        {change.title}
                        {change.now ? ` → ${clock(change.now, tz)}` : ''}
                      </T>
                    </Row>
                  ))}
                </View>
              </Card>
            ) : null}

            {proposal.blocked_by_confirmed_bookings.length > 0 ? (
              <Note tone="warning">
                This would move something you have already booked, so it needs your confirmation first.
              </Note>
            ) : null}

            {proposal.proposal && proposal.changes.length > 0 ? (
              <Row gap={space.xs}>
                <Button
                  label="Apply changes"
                  haptic="medium"
                  onPress={async () => {
                    await accept.mutateAsync(proposal.proposal!.id);
                    setProposal(null);
                  }}
                  style={{ flex: 1 }}
                />
                <Button label="Keep mine" tone="secondary" onPress={() => setProposal(null)} style={{ flex: 1 }} />
              </Row>
            ) : (
              <Button label="Close" tone="secondary" onPress={() => setProposal(null)} />
            )}
          </View>
        ) : null}
      </Sheet>
    </Screen>
  );
}

/** One row of the vertical timeline, with its rail and marker. */
function TimelineRow({
  item,
  timezone,
  first,
  last,
  onOpen,
}: {
  item: ItineraryItem;
  timezone?: string;
  first: boolean;
  last: boolean;
  onOpen: () => void;
}) {
  const colors = useTheme();

  const marker =
    item.kind === 'anchor'
      ? { fill: colors.text.primary, icon: 'lock' as const }
      : item.kind === 'meal'
        ? { fill: colors.status.closingSoon, icon: 'food' as const }
        : { fill: colors.status.open, icon: 'location' as const };

  return (
    <Row align="flex-start" gap={space.sm} style={{ paddingBottom: last ? 0 : space.md }}>
      <T variant="smallStrong" color={colors.text.tertiary} style={{ width: 42, paddingTop: 6 }}>
        {clock(item.starts_at, timezone)}
      </T>

      {/* Rail */}
      <View style={{ alignItems: 'center', alignSelf: 'stretch', width: 22 }}>
        <View style={{ height: 6, width: 2, backgroundColor: first ? 'transparent' : colors.border.subtle }} />
        <View
          style={{
            width: 22,
            height: 22,
            borderRadius: 11,
            backgroundColor: marker.fill,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Icon name={marker.icon} size={12} color="#FFFFFF" strokeWidth={2.1} />
        </View>
        {!last ? <View style={{ flex: 1, width: 2, backgroundColor: colors.border.subtle }} /> : null}
      </View>

      <View style={{ flex: 1, gap: 4 }}>
        <Row justify="space-between" align="flex-start" gap={space.xs}>
          <T variant="bodyStrong" style={{ flex: 1 }} numberOfLines={2}>
            {item.title}
          </T>
          {item.score !== null ? (
            <T variant="smallStrong" color={colors.text.tertiary}>
              {item.score}
            </T>
          ) : null}
        </Row>

        {item.travel_minutes_from_previous > 0 ? (
          <Row gap={5}>
            <Icon name={item.travel_mode === 'walk' ? 'walk' : 'transit'} size={13} color={colors.text.tertiary} />
            <T variant="caption" color={colors.text.tertiary}>
              {item.travel_minutes_from_previous} min to get there
            </T>
          </Row>
        ) : null}

        {item.locked ? <StatusPill kind="booked" label="Fixed" /> : null}

        {item.reason && item.kind === 'experience' ? (
          <WhyThisFits reasons={toFitReasons([item.reason])} title="Why here" tone="plain" />
        ) : item.reason ? (
          <T variant="small" color={colors.text.secondary}>
            {item.reason}
          </T>
        ) : null}

        {item.experience ? (
          <Button label="Open" tone="tertiary" size="small" onPress={onOpen} style={{ alignSelf: 'flex-start', paddingHorizontal: 0 }} />
        ) : null}
      </View>
    </Row>
  );
}
