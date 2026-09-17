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
import { Button, Card, EmptyState, Loading, Note, Row, Screen, SectionHeader, T } from '../../src/components/primitives';
import { clock, dayLabel, titleCase } from '../../src/lib/format';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

/** Spec s8 — the plan, built around what cannot move. */
export default function TripScreen() {
  const colors = useTheme();
  const router = useRouter();
  const session = useSession();

  const { data: journey } = useJourney(session.journeyId);
  const tz = journey?.destination.timezone;
  const { data: trip, isLoading } = useTrip(session.tripId);
  const generate = useGenerateItinerary(session.tripId);
  const replan = useReplan(session.tripId);
  const accept = useAcceptReplan(session.tripId);
  const createTrip = useCreateTrip();

  const [proposal, setProposal] = useState<ReplanResult | null>(null);

  if (!session.journeyId) {
    return (
      <Screen>
        <EmptyState
          title="No journey yet"
          body="Tell us why you are here and we can build a day that works around your fixed commitments."
          action={<Button label="Set up a journey" onPress={() => router.push('/onboarding/purpose')} style={{ marginTop: space.md }} />}
        />
      </Screen>
    );
  }

  const ensureTrip = async () => {
    if (session.tripId) return session.tripId;

    const created = await createTrip.mutateAsync(session.journeyId!);
    await session.setTrip(created.id);

    return created.id;
  };

  const build = async () => {
    try {
      await ensureTrip();
      await generate.mutateAsync({});
    } catch (cause) {
      Alert.alert('Could not build the plan', cause instanceof Error ? cause.message : 'Try again.');
    }
  };

  const askForReplan = async () => {
    try {
      const result = await replan.mutateAsync();
      setProposal(result);
    } catch (cause) {
      Alert.alert('Could not replan', cause instanceof Error ? cause.message : 'Try again.');
    }
  };

  const itinerary = trip?.itinerary;

  return (
    <Screen>
      <T variant="display">{journey?.destination.name ?? 'Your trip'}</T>
      {journey && (
        <Row gap={space.sm} wrap style={{ marginTop: 6 }}>
          <T variant="small" color={colors.inkMuted}>
            {journey.reason_label}
          </T>
          <T variant="small" color={colors.inkFaint}>·</T>
          <T variant="small" color={colors.inkMuted}>
            {journey.adults} adult{journey.adults === 1 ? '' : 's'}
            {journey.children > 0 ? `, ${journey.children} child${journey.children === 1 ? '' : 'ren'}` : ''}
          </T>
        </Row>
      )}

      {journey?.mission_goals && journey.mission_goals.length > 0 && (
        <View style={{ marginTop: space.lg }}>
          <SectionHeader title="What we understood from you" />
          <Card>
            <View style={{ padding: space.lg, gap: space.sm }}>
              {journey.mission_goals.slice(0, 4).map((goal) => (
                <View key={goal.goal}>
                  <T variant="bodyStrong">{titleCase(goal.goal)}</T>
                  <T variant="small" color={colors.inkMuted}>
                    {goal.evidence}
                  </T>
                </View>
              ))}
            </View>
          </Card>
        </View>
      )}

      <SectionHeader title="Fixed commitments" action="Add" onAction={() => router.push('/anchors')} />
      {journey && journey.anchors.length === 0 ? (
        <Note>
          Nothing fixed yet. Add flights, meetings or dinner bookings and the plan will be built around them
          rather than over them.
        </Note>
      ) : (
        <Card>
          <View style={{ padding: space.lg, gap: space.md }}>
            {journey?.anchors.map((anchor) => (
              <Row key={anchor.id} gap={space.md} style={{ alignItems: 'flex-start' }}>
                <View
                  style={{
                    width: 4,
                    alignSelf: 'stretch',
                    borderRadius: 2,
                    backgroundColor: colors.accent,
                  }}
                />
                <View style={{ flex: 1 }}>
                  <T variant="bodyStrong">{anchor.title}</T>
                  <T variant="small" color={colors.inkMuted}>
                    {dayLabel(anchor.starts_at, tz)} · {clock(anchor.starts_at, tz)}–{clock(anchor.ends_at, tz)}
                  </T>
                  <T variant="small" color={colors.inkFaint}>
                    Protected from {clock(anchor.protected_from, tz)}
                  </T>
                </View>
              </Row>
            ))}
          </View>
        </Card>
      )}

      <SectionHeader
        title="Your plan"
        action={itinerary ? 'Rebuild' : undefined}
        onAction={itinerary ? build : undefined}
      />

      {isLoading && <Loading />}

      {!itinerary && !isLoading && (
        <EmptyState
          title="No plan built yet"
          body="We will fit realistic options into the gaps between your commitments, with travel time and opening hours accounted for."
          action={
            <Button
              label={generate.isPending ? 'Building…' : 'Build my plan'}
              onPress={build}
              loading={generate.isPending}
              style={{ marginTop: space.md }}
            />
          }
        />
      )}

      {itinerary?.days.map((day) => (
        <View key={day.id} style={{ marginBottom: space.lg }}>
          <Row style={{ justifyContent: 'space-between', marginBottom: space.sm }}>
            <T variant="heading">{dayLabel(day.date, tz)}</T>
            <T variant="small" color={colors.inkFaint}>
              {day.summary}
            </T>
          </Row>

          <Card>
            <View style={{ padding: space.lg, gap: space.lg }}>
              {day.items.map((item) => (
                <Row key={item.id} gap={space.md} style={{ alignItems: 'flex-start' }}>
                  <View style={{ width: 44 }}>
                    <T variant="small" color={colors.inkFaint}>
                      {clock(item.starts_at, tz)}
                    </T>
                  </View>

                  <View
                    style={{
                      width: 3,
                      alignSelf: 'stretch',
                      borderRadius: 2,
                      backgroundColor:
                        item.kind === 'anchor' ? colors.accent : item.kind === 'meal' ? colors.line : colors.positive,
                    }}
                  />

                  <View style={{ flex: 1, gap: 2 }}>
                    <Row style={{ justifyContent: 'space-between' }}>
                      <T variant="bodyStrong" style={{ flex: 1 }} numberOfLines={2}>
                        {item.title}
                      </T>
                      {item.score !== null && (
                        <T variant="small" color={colors.inkFaint}>
                          {item.score}
                        </T>
                      )}
                    </Row>

                    {item.travel_minutes_from_previous > 0 && (
                      <T variant="small" color={colors.inkFaint}>
                        {item.travel_minutes_from_previous} min {item.travel_mode ?? 'travel'} to get there
                      </T>
                    )}

                    {item.reason && (
                      <T variant="small" color={colors.inkMuted}>
                        {item.reason}
                      </T>
                    )}

                    {item.locked && (
                      <View
                        style={{
                          alignSelf: 'flex-start',
                          backgroundColor: colors.accentSoft,
                          borderRadius: radius.sm,
                          paddingHorizontal: 8,
                          paddingVertical: 2,
                          marginTop: 2,
                        }}
                      >
                        <T variant="small" color={colors.accent}>
                          Fixed
                        </T>
                      </View>
                    )}

                    {item.experience && (
                      <Button
                        label="Open"
                        tone="ghost"
                        onPress={() => router.push(`/experience/${item.experience!.id}`)}
                        style={{ alignSelf: 'flex-start', paddingHorizontal: 0 }}
                      />
                    )}
                  </View>
                </Row>
              ))}
            </View>
          </Card>
        </View>
      ))}

      {itinerary && (
        <Button
          label={replan.isPending ? 'Checking…' : 'Should I change anything?'}
          tone="secondary"
          onPress={askForReplan}
          loading={replan.isPending}
        />
      )}

      {proposal && (
        <View style={{ marginTop: space.lg, gap: space.md }}>
          <Note tone={proposal.changes.length > 0 ? 'warn' : 'good'}>{proposal.message}</Note>

          {proposal.changes.length > 0 && (
            <Card>
              <View style={{ padding: space.lg, gap: space.sm }}>
                {proposal.changes.slice(0, 8).map((change, index) => (
                  <T key={`${change.title}-${index}`} variant="small" color={colors.inkMuted}>
                    {change.direction === 'added' ? '+ ' : change.direction === 'removed' ? '− ' : '→ '}
                    {change.title}
                    {change.now ? ` at ${clock(change.now, tz)}` : ''}
                  </T>
                ))}
              </View>
            </Card>
          )}

          {proposal.proposal && proposal.changes.length > 0 && (
            <Row gap={space.md}>
              <Button
                label="Use this plan"
                onPress={async () => {
                  await accept.mutateAsync(proposal.proposal!.id);
                  setProposal(null);
                }}
                style={{ flex: 1 }}
              />
              <Button label="Keep mine" tone="secondary" onPress={() => setProposal(null)} style={{ flex: 1 }} />
            </Row>
          )}
        </View>
      )}

      {itinerary && (
        <T variant="small" color={colors.inkFaint} style={{ marginTop: space.xl, textAlign: 'center' }}>
          Version {itinerary.version} · {itinerary.engine_version}
        </T>
      )}
    </Screen>
  );
}
