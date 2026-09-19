import React from 'react';
import { Linking, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useBookings, type BookingSummary } from '../../src/api/hooks';
import { Icon } from '../../src/components/Icon';
import { StatusPill, type StatusKind } from '../../src/components/StatusPill';
import {
  Button,
  Card,
  Divider,
  EmptyState,
  Gutter,
  Loading,
  Row,
  Screen,
  SectionHeader,
  T,
} from '../../src/components/primitives';
import { clock, dayLabel } from '../../src/lib/format';
import { space, useTheme } from '../../src/theme';

/** Booking state → the pill vocabulary travellers understand. */
function pillFor(state: string): { kind: StatusKind; label: string } {
  switch (state) {
    case 'confirmed':
      return { kind: 'booked', label: 'Confirmed' };
    case 'partially_confirmed':
      return { kind: 'booked', label: 'Partly confirmed' };
    case 'awaiting_payment':
      return { kind: 'available', label: 'Finish on supplier site' };
    case 'processing':
      return { kind: 'available', label: 'Processing' };
    case 'cancelled':
    case 'refunded':
    case 'partially_refunded':
      return { kind: 'closed', label: 'Cancelled' };
    case 'failed':
      return { kind: 'sold-out', label: 'Did not complete' };
    default:
      return { kind: 'unknown', label: state.replace(/_/g, ' ') };
  }
}

export default function Bookings() {
  const colors = useTheme();
  const router = useRouter();
  const { data: bookings, isLoading } = useBookings();

  if (isLoading) {
    return (
      <Screen>
        <Loading />
      </Screen>
    );
  }

  if (!bookings || bookings.length === 0) {
    return (
      <Screen>
        <Gutter style={{ paddingTop: space.sm }}>
          <T variant="displayL">Bookings</T>
          <EmptyState
            title="No bookings yet"
            body="Experiences you book will appear here, with your tickets, meeting point and when to leave."
            action={<Button label="Find something to book" onPress={() => router.push('/(tabs)/index')} />}
          />
        </Gutter>
      </Screen>
    );
  }

  const upcoming = bookings.filter((b) => !['cancelled', 'refunded', 'failed'].includes(b.state));
  const past = bookings.filter((b) => ['cancelled', 'refunded', 'failed'].includes(b.state));

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.sm }}>
        <T variant="displayL">Bookings</T>
      </Gutter>

      {upcoming.length > 0 ? (
        <Gutter>
          <SectionHeader title="Upcoming" />
          {upcoming.map((booking) => (
            <BookingCard key={booking.id} booking={booking} />
          ))}
        </Gutter>
      ) : null}

      {past.length > 0 ? (
        <Gutter>
          <SectionHeader title="No longer active" />
          {past.map((booking) => (
            <BookingCard key={booking.id} booking={booking} />
          ))}
        </Gutter>
      ) : null}
    </Screen>
  );
}

function BookingCard({ booking }: { booking: BookingSummary }) {
  const colors = useTheme();
  const pill = pillFor(booking.state);
  const first = booking.items[0];

  return (
    <Card style={{ marginBottom: space.sm }}>
      <View style={{ padding: space.md, gap: space.sm }}>
        <Row justify="space-between" align="flex-start" gap={space.sm}>
          <View style={{ flex: 1, gap: 4 }}>
            <T variant="bodyStrong" numberOfLines={2}>
              {first?.title ?? 'Booking'}
            </T>
            <T variant="caption" color={colors.text.tertiary}>
              {booking.reference} · {booking.provider}
            </T>
          </View>
          <StatusPill kind={pill.kind} label={pill.label} />
        </Row>

        {first?.starts_at ? (
          <Row gap={space.sm} wrap>
            <Row gap={5}>
              <Icon name="calendar" size={14} color={colors.text.tertiary} />
              <T variant="small" color={colors.text.secondary}>
                {dayLabel(first.starts_at)}
              </T>
            </Row>
            <Row gap={5}>
              <Icon name="clock" size={14} color={colors.text.tertiary} />
              <T variant="small" color={colors.text.secondary}>
                {clock(first.starts_at)}
              </T>
            </Row>
            {booking.total ? (
              <Row gap={5}>
                <Icon name="money" size={14} color={colors.text.tertiary} />
                <T variant="small" color={colors.text.secondary}>
                  {booking.total.formatted}
                </T>
              </Row>
            ) : null}
          </Row>
        ) : null}

        {booking.failure_reason ? (
          <T variant="small" color={colors.status.error}>
            {booking.failure_reason}
          </T>
        ) : null}

        {booking.cancellation_policy ? (
          <>
            <Divider />
            <T variant="caption" color={colors.text.tertiary}>
              {booking.cancellation_policy}
            </T>
          </>
        ) : null}

        {booking.redirect_url && booking.state === 'awaiting_payment' ? (
          <Button
            label="Continue on supplier site"
            tone="secondary"
            size="small"
            onPress={() => Linking.openURL(booking.redirect_url!)}
            style={{ alignSelf: 'flex-start' }}
          />
        ) : null}
      </View>
    </Card>
  );
}
