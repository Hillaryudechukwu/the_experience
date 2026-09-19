import React from 'react';
import { Platform, Pressable, View, type ColorValue } from 'react-native';
import { Tabs, useRouter } from 'expo-router';
import * as Haptics from 'expo-haptics';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Icon, type IconName } from '../../src/components/Icon';
import { T } from '../../src/components/primitives';
import { elevation, radius, space, useTheme } from '../../src/theme';

/**
 * Primary navigation (Figma: 15).
 *
 * Five destinations: Discover, Map, Trip, Bookings, You. The AI guide is
 * deliberately not one of them — it is a floating contextual action instead,
 * because spending a fifth of the navigation on a chat entry point would push
 * out something the traveller needs far more often.
 */
export default function TabsLayout() {
  const colors = useTheme();
  const router = useRouter();
  const insets = useSafeAreaInsets();

  const tab = (name: IconName) =>
    function TabIcon({ color, focused }: { color: ColorValue; focused: boolean }) {
      return (
        <View style={{ alignItems: 'center', justifyContent: 'center', height: 28 }}>
          <Icon name={name} size={focused ? 23 : 22} color={color as string} strokeWidth={focused ? 2.1 : 1.7} />
        </View>
      );
    };

  return (
    <View style={{ flex: 1 }}>
      <Tabs
        screenOptions={{
          headerShown: false,
          tabBarActiveTintColor: colors.action.primary,
          tabBarInactiveTintColor: colors.text.tertiary,
          tabBarStyle: {
            backgroundColor: colors.background.elevated,
            borderTopColor: colors.border.subtle,
            borderTopWidth: 1,
            height: 56 + insets.bottom,
            paddingTop: 6,
            paddingBottom: insets.bottom || 8,
          },
          tabBarLabelStyle: { fontFamily: 'Inter_500Medium', fontSize: 11, marginTop: 2 },
        }}
      >
        <Tabs.Screen name="index" options={{ title: 'Discover', tabBarIcon: tab('compass') }} />
        <Tabs.Screen name="map" options={{ title: 'Map', tabBarIcon: tab('map') }} />
        <Tabs.Screen name="trip" options={{ title: 'Trip', tabBarIcon: tab('trip') }} />
        <Tabs.Screen name="bookings" options={{ title: 'Bookings', tabBarIcon: tab('bookings') }} />
        <Tabs.Screen name="you" options={{ title: 'You', tabBarIcon: tab('person') }} />
      </Tabs>

      <FloatingGuide onPress={() => router.push('/guide')} bottomInset={insets.bottom} />
    </View>
  );
}

/** The AI guide, always within reach but never occupying a tab. */
function FloatingGuide({ onPress, bottomInset }: { onPress: () => void; bottomInset: number }) {
  const colors = useTheme();

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel="Open the Experience Guide"
      onPress={() => {
        Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => {});
        onPress();
      }}
      style={({ pressed }) => ({
        position: 'absolute',
        right: space.lg,
        bottom: 56 + bottomInset + space.md,
        flexDirection: 'row',
        alignItems: 'center',
        gap: space.xs,
        paddingVertical: 11,
        paddingHorizontal: space.md,
        borderRadius: radius.pill,
        backgroundColor: colors.text.primary,
        opacity: pressed ? 0.92 : 1,
        transform: [{ scale: pressed ? 0.97 : 1 }],
        ...(Platform.OS === 'android' ? { elevation: 8 } : elevation.floating),
      })}
    >
      <Icon name="sparkle" size={18} color={colors.text.inverse} strokeWidth={1.9} />
      <T variant="smallStrong" color={colors.text.inverse}>
        Ask
      </T>
    </Pressable>
  );
}
