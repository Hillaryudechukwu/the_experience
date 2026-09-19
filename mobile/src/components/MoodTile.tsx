import React from 'react';
import { Pressable, View } from 'react-native';
import * as Haptics from 'expo-haptics';
import { LinearGradient } from 'expo-linear-gradient';

import { radius, space, useTheme } from '../theme';
import { Icon, type IconName } from './Icon';
import { T } from './primitives';

export type Mood = {
  key: string;
  label: string;
  icon: IconName;
  from: string;
  to: string;
};

/** Expressive tiles rather than ordinary filter chips (Figma: 16, 43). */
export const MOODS: Mood[] = [
  { key: 'adventurous', label: 'Adventurous', icon: 'compass', from: '#2856F6', to: '#123AAE' },
  { key: 'relaxed', label: 'Relaxed', icon: 'weather', from: '#27A875', to: '#12694A' },
  { key: 'romantic', label: 'Romantic', icon: 'save', from: '#FF6B5E', to: '#B93B32' },
  { key: 'hungry', label: 'Hungry', icon: 'food', from: '#F4B63F', to: '#B4790E' },
  { key: 'curious', label: 'Curious', icon: 'sparkle', from: '#7B5AE0', to: '#4A2F9C' },
  { key: 'social', label: 'Social', icon: 'family', from: '#1C9BD1', to: '#0E5F84' },
  { key: 'inspired', label: 'Inspired', icon: 'camera', from: '#0D1B2A', to: '#25405C' },
  { key: 'different', label: 'Something different', icon: 'map', from: '#E0559B', to: '#93235F' },
];

export function MoodTile({
  mood,
  selected,
  onPress,
  width = 132,
}: {
  mood: Mood;
  selected?: boolean;
  onPress: () => void;
  width?: number;
}) {
  const colors = useTheme();

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected: !!selected }}
      onPress={() => {
        Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => {});
        onPress();
      }}
      style={({ pressed }) => ({
        width,
        height: 96,
        borderRadius: radius.card,
        overflow: 'hidden',
        opacity: pressed ? 0.9 : 1,
        borderWidth: selected ? 2 : 0,
        borderColor: colors.text.primary,
      })}
    >
      <LinearGradient
        colors={[mood.from, mood.to]}
        start={{ x: 0, y: 0 }}
        end={{ x: 1, y: 1 }}
        style={{ flex: 1, padding: space.sm, justifyContent: 'space-between' }}
      >
        <Icon name={mood.icon} size={20} color="rgba(255,255,255,0.92)" />
        <T variant="smallStrong" color="#FFFFFF" numberOfLines={2}>
          {mood.label}
        </T>
      </LinearGradient>

      {selected ? (
        <View
          style={{
            position: 'absolute',
            top: space.xs,
            right: space.xs,
            width: 20,
            height: 20,
            borderRadius: 10,
            backgroundColor: '#FFFFFF',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Icon name="check" size={13} color={mood.from} strokeWidth={2.4} />
        </View>
      ) : null}
    </Pressable>
  );
}
