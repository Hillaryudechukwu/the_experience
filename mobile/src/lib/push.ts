import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';
import type { ImperativeRouter } from 'expo-router';

import { http } from '../api/client';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: false,
  }),
});

export type NotificationRouteData = {
  kind?: string;
  notification_id?: string;
  path?: string;
  booking_id?: string;
  trip_id?: string;
  destination_id?: string;
  import_id?: string;
  itinerary_id?: string;
  experience_id?: string;
  reference?: string;
};

/**
 * Ask once for notification permission and store the Expo token.
 *
 * A refusal, a simulator, or a missing project id must not block booking or
 * the end of onboarding. The outbox still keeps an in-app row either way.
 */
export async function registerForPush(): Promise<void> {
  if (Platform.OS === 'web') return;

  const existing = await Notifications.getPermissionsAsync();
  const next =
    existing.status === 'granted' ? existing : await Notifications.requestPermissionsAsync();

  if (next.status !== 'granted') return;

  const projectId = Constants.expoConfig?.extra?.eas?.projectId;
  if (!projectId) return;

  const token = (await Notifications.getExpoPushTokenAsync({ projectId })).data;
  await http.post('/notifications/push-token', { token, platform: Platform.OS });
}

/** Map a notification kind / payload to an in-app route. */
export function routeForNotification(data: NotificationRouteData | undefined | null): string {
  if (!data) return '/account/notifications';

  if (typeof data.path === 'string' && data.path.startsWith('/')) {
    return data.path;
  }

  const kind = data.kind ?? '';

  if (kind.startsWith('booking_')) return '/(tabs)/bookings';
  if (kind.startsWith('destination_import_')) return '/(tabs)/index';
  if (kind.startsWith('replan_')) return '/(tabs)/trip';
  if (data.experience_id) return `/experience/${data.experience_id}`;

  return '/account/notifications';
}

const handledResponses = new Set<string>();

/**
 * Wire tap → navigate once for the app lifetime.
 *
 * Cold starts use getLastNotificationResponseAsync; warm taps use the listener.
 * Returns an unsubscribe so the root layout can clean up.
 */
export function wirePushNavigation(router: ImperativeRouter): () => void {
  if (Platform.OS === 'web') {
    return () => {};
  }

  const open = (response: Notifications.NotificationResponse | null) => {
    if (!response) return;

    /* The OS keeps the last response until cleared, and a cold start can
       deliver the same tap to both paths below. Handle each tap once. */
    const id = response.notification.request.identifier;
    if (handledResponses.has(id)) return;
    handledResponses.add(id);
    Notifications.clearLastNotificationResponse();

    const data = response.notification.request.content.data as NotificationRouteData;
    const path = routeForNotification(data);
    try {
      router.push(path as never);
    } catch {
      /* Navigation can race splash / auth hydrate; the inbox is still there. */
    }
  };

  Notifications.getLastNotificationResponseAsync()
    .then(open)
    .catch(() => {});

  const subscription = Notifications.addNotificationResponseReceivedListener(open);

  return () => subscription.remove();
}
