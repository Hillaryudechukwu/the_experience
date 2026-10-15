import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';

import { http } from '../api/client';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: false,
  }),
});

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
