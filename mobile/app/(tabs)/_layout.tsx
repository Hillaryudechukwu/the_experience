import React from 'react';
import { Platform, Pressable, View, type ColorValue } from 'react-native';
import { Tabs, useRouter } from 'expo-router';
import * as Haptics from 'expo-haptics';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Icon, type IconName } from '../../src/components/Icon';
import { T } from '../../src/components/primitives';
import { elevation, radius, space, useTheme } from '../../src/theme';

/* The tab bar's vertical budget, named so the height below is arithmetic
   rather than a number someone has to re-measure. */
const ICON_BOX = 24;
const LABEL_BLOCK = 14;
const ICON_LABEL_GAP = 3;
const BAR_BORDER = 1;
const BAR_CONTENT = ICON_BOX + ICON_LABEL_GAP + LABEL_BLOCK;
const BAR_PADDING_TOP = 8;
const BAR_PADDING_BOTTOM = 10;
const BAR_HEIGHT = BAR_PADDING_TOP + BAR_CONTENT + BAR_PADDING_BOTTOM + BAR_BORDER;

const TABS: { name: string; title: string; icon: IconName }[] = [
  { name: 'index', title: 'Home', icon: 'compass' },
  { name: 'map', title: 'Explore', icon: 'map' },
  { name: 'trip', title: 'Plan', icon: 'trip' },
  { name: 'bookings', title: 'Bookings', icon: 'bookings' },
  { name: 'you', title: 'Profile', icon: 'person' },
];

/**
 * Primary navigation (Figma: 15).
 *
 * Five destinations: Home, Explore, Plan, Bookings, Profile. The AI guide is
 * deliberately not one of them — it is a floating contextual action instead,
 * because spending a fifth of the navigation on a chat entry point would push
 * out something the traveller needs far more often.
 */
export default function TabsLayout() {
  const colors = useTheme();
  const router = useRouter();
  const insets = useSafeAreaInsets();

  /*
   * The icon and its label are drawn together, and the navigator's own label is
   * switched off.
   *
   * React Navigation gives its icon container `flex: 1`, so on a fixed-height
   * bar the icon absorbs every spare pixel and the label — which has no flex of
   * its own — is squeezed to a few pixels and disappears. Making the bar taller
   * only feeds the icon. Drawing both inside the icon slot takes the item out
   * of that negotiation: the column below is the whole tab, at a height this
   * file controls.
   */
  const tab = (name: IconName, label: string) =>
    function TabItem({ color, focused }: { color: ColorValue; focused: boolean }) {
      return (
        /*
         * Hidden from assistive technology, because the navigator draws this
         * slot twice to cross-fade between focused and unfocused. Two copies of
         * the icon cost nothing — two copies of the word do: the tab's
         * accessible name comes from its text content, so it would announce
         * "Home Home". The real name is set by tabBarAccessibilityLabel below.
         */
        <View
          accessibilityElementsHidden
          importantForAccessibility="no-hide-descendants"
          style={{ alignItems: 'center', justifyContent: 'flex-start', height: BAR_CONTENT, width: '100%' }}
        >
          <View style={{ height: ICON_BOX, justifyContent: 'center' }}>
            <Icon name={name} size={focused ? 23 : 22} color={color as string} strokeWidth={focused ? 2.1 : 1.7} />
          </View>
          <T
            variant="caption"
            color={color as string}
            numberOfLines={1}
            style={{ fontSize: 11, lineHeight: LABEL_BLOCK, marginTop: ICON_LABEL_GAP }}
          >
            {label}
          </T>
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
          tabBarShowLabel: false,
          /* The bar's height is derived rather than guessed. When the row is
             even a pixel short of what the icon and the label together need,
             the label is a flex child with nowhere to go and collapses to zero
             height — it stays in the DOM, reads fine to a screen reader, and is
             simply invisible, which is the worst of the three outcomes. The
             earlier 56px and 64px bars both landed there. */
          tabBarStyle: {
            backgroundColor: colors.background.elevated,
            borderTopColor: colors.border.subtle,
            borderTopWidth: BAR_BORDER,
            height: BAR_HEIGHT + insets.bottom,
            paddingTop: BAR_PADDING_TOP,
            paddingBottom: BAR_PADDING_BOTTOM + insets.bottom,
          },
          tabBarIconStyle: { flex: 1, width: '100%' },
          tabBarItemStyle: { paddingVertical: 0 },
        }}
      >
        {TABS.map(({ name, title, icon }) => (
          <Tabs.Screen
            key={name}
            name={name}
            options={{ title, tabBarIcon: tab(icon, title), tabBarAccessibilityLabel: title }}
          />
        ))}
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
        bottom: BAR_HEIGHT + bottomInset + space.md,
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
