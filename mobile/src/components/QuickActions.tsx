import React from 'react';
import { Pressable, View } from 'react-native';
import * as Haptics from 'expo-haptics';

import { Icon, type IconName } from './Icon';
import { T } from './primitives';
import { elevation, radius, space, TOUCH_TARGET, useTheme } from '../theme';

export type QuickAction = {
  key: string;
  label: string;
  icon: IconName;
  onPress: () => void;
};

/**
 * The four shortcuts that sit across the top of the page (Figma: 3).
 *
 * In the design this card overlaps the photograph above it by about a third of
 * its height, which is what ties the two together — the header stops being a
 * banner and becomes the top of a continuous page. The negative margin is
 * therefore load-bearing, not decoration.
 *
 * Only four, and they never scroll. A row you can take in at a glance is the
 * reason this exists; a fifth item would turn it into another rail.
 */
export function QuickActions({ actions, overlap = 28 }: { actions: QuickAction[]; overlap?: number }) {
  const colors = useTheme();

  return (
    <View
      style={{
        marginTop: -overlap,
        marginHorizontal: space.lg,
        backgroundColor: colors.background.elevated,
        borderRadius: radius.card,
        flexDirection: 'row',
        paddingVertical: space.sm,
        ...elevation.feature,
      }}
    >
      {actions.map((action) => (
        <Pressable
          key={action.key}
          accessibilityRole="button"
          accessibilityLabel={action.label}
          onPress={() => {
            Haptics.selectionAsync().catch(() => {});
            action.onPress();
          }}
          style={({ pressed }) => ({
            flex: 1,
            minHeight: TOUCH_TARGET,
            alignItems: 'center',
            justifyContent: 'center',
            gap: 5,
            paddingVertical: 4,
            opacity: pressed ? 0.6 : 1,
          })}
        >
          <Icon name={action.icon} size={21} color={colors.action.primary} strokeWidth={1.8} />
          <T variant="caption" color={colors.text.secondary} numberOfLines={1}>
            {action.label}
          </T>
        </Pressable>
      ))}
    </View>
  );
}
