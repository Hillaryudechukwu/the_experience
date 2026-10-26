import React from 'react';
import { Image, Pressable, View } from 'react-native';
import * as Haptics from 'expo-haptics';

import { radius, space, useTheme } from '../theme';
import { Icon } from './Icon';
import { T } from './primitives';
import credits from '../../assets/moods/credits.json';

export type Mood = {
  key: string;
  label: string;
  image: number;
};

const IMAGES = {
  adventurous: require('../../assets/moods/adventurous.jpg'),
  relaxed: require('../../assets/moods/relaxed.jpg'),
  romantic: require('../../assets/moods/romantic.jpg'),
  curious: require('../../assets/moods/curious.jpg'),
  hungry: require('../../assets/moods/hungry.jpg'),
  social: require('../../assets/moods/social.jpg'),
  inspired: require('../../assets/moods/inspired.jpg'),
  energetic: require('../../assets/moods/energetic.jpg'),
  different: require('../../assets/moods/different.jpg'),
} as const;

/** A picture of the mood, not a picture of a place. */
export const MOODS: Mood[] = [
  { key: 'adventurous', label: 'Adventurous', image: IMAGES.adventurous },
  { key: 'relaxed', label: 'Relaxed', image: IMAGES.relaxed },
  { key: 'romantic', label: 'Romantic', image: IMAGES.romantic },
  { key: 'hungry', label: 'Hungry', image: IMAGES.hungry },
  { key: 'curious', label: 'Curious', image: IMAGES.curious },
  { key: 'social', label: 'Social', image: IMAGES.social },
  { key: 'inspired', label: 'Inspired', image: IMAGES.inspired },
  { key: 'energetic', label: 'Energetic', image: IMAGES.energetic },
  { key: 'different', label: 'Something different', image: IMAGES.different },
];

export const MOOD_CREDIT = credits.line;

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
      accessibilityRole="imagebutton"
      accessibilityLabel={`${mood.label}, picture`}
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
      <Image
        source={mood.image}
        style={{ width: '100%', height: '100%' }}
        resizeMode="cover"
        accessibilityIgnoresInvertColors
      />
      <View
        pointerEvents="none"
        style={{
          position: 'absolute',
          left: 0,
          right: 0,
          bottom: 0,
          height: 48,
          backgroundColor: 'rgba(13,27,42,0.55)',
        }}
      />
      <T
        variant="smallStrong"
        color="#FFFFFF"
        numberOfLines={2}
        style={{ position: 'absolute', left: space.sm, right: space.sm, bottom: space.sm }}
      >
        {mood.label}
      </T>

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
          {/* Fixed dark ink: on the white disc, the theme's primary text
              colour is white in dark mode and the tick disappeared. */}
          <Icon name="check" size={13} color="#0D1B2A" strokeWidth={2.4} />
        </View>
      ) : null}
    </Pressable>
  );
}
