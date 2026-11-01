import React from 'react';
import { Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
  type TravellerNotification,
} from '../../src/api/hooks';
import {
  Button,
  Card,
  EmptyState,
  Gutter,
  Loading,
  Row,
  Screen,
  T,
} from '../../src/components/primitives';
import { useBackTo } from '../../src/lib/navigation';
import { routeForNotification } from '../../src/lib/push';
import { space, useTheme } from '../../src/theme';

export default function NotificationsInbox() {
  const colors = useTheme();
  const router = useRouter();
  const goBack = useBackTo('/(tabs)/you');
  const { data, isLoading, isError, refetch } = useNotifications();
  const markRead = useMarkNotificationRead();
  const markAll = useMarkAllNotificationsRead();

  const rows = data ?? [];
  const unread = rows.filter((row) => row.read_at === null).length;

  const open = async (row: TravellerNotification) => {
    if (row.read_at === null) {
      markRead.mutate(row.id);
    }

    router.push(routeForNotification({
      kind: row.kind,
      path: row.payload.path ?? undefined,
      booking_id: row.payload.booking_id ?? undefined,
      trip_id: row.payload.trip_id ?? undefined,
      destination_id: row.payload.destination_id ?? undefined,
      experience_id: row.payload.experience_id ?? undefined,
    }) as never);
  };

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.sm, gap: space.md }}>
        <Pressable onPress={goBack} accessibilityRole="button" accessibilityLabel="Back">
          <T variant="body" color={colors.text.secondary}>
            Back
          </T>
        </Pressable>

        <Row justify="space-between" align="center">
          <T variant="displayL">Notifications</T>
          {unread > 0 ? (
            <Button
              label="Mark all read"
              tone="secondary"
              size="small"
              onPress={() => markAll.mutate()}
            />
          ) : null}
        </Row>

        <T variant="body" color={colors.text.secondary}>
          Booking updates, city readiness, and suggested plan changes land here when push is off.
        </T>

        {isLoading ? <Loading /> : null}

        {isError ? (
          <EmptyState
            title="Could not load notices"
            body="Check your connection and try again."
            action={<Button label="Try again" tone="secondary" onPress={() => refetch()} />}
          />
        ) : null}

        {!isLoading && !isError && rows.length === 0 ? (
          <EmptyState
            title="Nothing yet"
            body="When a booking confirms, a city finishes preparing, or the weather asks for a plan change, it will show up here."
          />
        ) : null}

        {rows.map((row) => (
          <NoticeRow key={row.id} row={row} onPress={() => open(row)} />
        ))}
      </Gutter>
    </Screen>
  );
}

function NoticeRow({ row, onPress }: { row: TravellerNotification; onPress: () => void }) {
  const colors = useTheme();
  const unread = row.read_at === null;

  return (
    <Pressable onPress={onPress} accessibilityRole="button">
      <Card>
        <View style={{ gap: space.xs, padding: space.md }}>
          <Row justify="space-between" align="center">
            <T variant="h3" style={{ flex: 1, paddingRight: space.sm }}>
              {row.title}
            </T>
            {unread ? (
              <View
                style={{
                  width: 8,
                  height: 8,
                  borderRadius: 4,
                  backgroundColor: colors.action.primary,
                }}
              />
            ) : null}
          </Row>
          <T variant="body" color={colors.text.secondary}>
            {row.body}
          </T>
          {row.created_at ? (
            <T variant="caption" color={colors.text.tertiary}>
              {formatWhen(row.created_at)}
            </T>
          ) : null}
        </View>
      </Card>
    </Pressable>
  );
}

function formatWhen(iso: string): string {
  try {
    return new Date(iso).toLocaleString('en-GB', {
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return iso;
  }
}
