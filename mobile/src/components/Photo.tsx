import React, { useState } from 'react';
import { Image, View, type StyleProp, type ViewStyle } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';

import { Icon, type IconName } from './Icon';
import { artworkFor, radius, space, useTheme } from '../theme';
import { T } from './primitives';

export type ImageAttribution = {
  creator: string | null;
  licence: string | null;
  licence_url: string | null;
  source_url: string | null;
} | null;

/**
 * The glyph drawn on a generated card.
 *
 * Without it the fallback was a bare gradient, which reads as an image that
 * failed to load rather than as a deliberate stand-in. A mark that matches the
 * kind of place makes the same card look intentional, and it tells the
 * traveller something — a fork means somewhere to eat, whatever the wash
 * behind it happens to be.
 */
function glyphFor(category?: string): IconName {
  switch (category) {
    case 'food_experience':
      return 'food';
    case 'nightlife':
      return 'sparkle';
    case 'family':
      return 'family';
    case 'nature':
      return 'walk';
    case 'free':
      return 'money';
    case 'culture':
    case 'must_experience':
      return 'guide';
    case 'hidden_gem':
    case 'local_favourite':
      return 'compass';
    case 'iconic':
      return 'location';
    default:
      return 'camera';
  }
}

/** Deterministic stand-in where no licensed photograph exists. */
export function Artwork({
  id,
  category,
  height = 120,
  style,
  glyph = true,
}: {
  id: string;
  category?: string;
  height?: number | `${number}%`;
  style?: StyleProp<ViewStyle>;
  glyph?: boolean;
}) {
  const [from, to] = artworkFor(id, category);
  const size = typeof height === 'number' ? Math.round(Math.min(64, Math.max(20, height * 0.32))) : 40;

  return (
    <LinearGradient
      colors={[from, to]}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      style={[{ height: height as number, width: '100%', alignItems: 'center', justifyContent: 'center' }, style]}
    >
      {glyph && size >= 20 ? (
        <Icon name={glyphFor(category)} size={size} color="rgba(255,255,255,0.34)" strokeWidth={1.4} />
      ) : null}
    </LinearGradient>
  );
}

/**
 * A photograph of the place, or an honest stand-in.
 *
 * Most imagery is Wikimedia Commons under Creative Commons terms, which oblige
 * us to name the photographer and licence wherever the image appears. The
 * credit is rendered here rather than by each screen, so an image physically
 * cannot be shown without it.
 *
 * Where no licensed photograph exists, a deterministic wash is drawn rather
 * than a stock photo of somewhere else.
 */
export function Photo({
  id,
  uri,
  attribution,
  category,
  height = 150,
  overlay = false,
  children,
  showCredit = true,
  rounded,
}: {
  id: string;
  uri?: string | null;
  attribution?: ImageAttribution;
  category?: string;
  height?: number;
  overlay?: boolean;
  children?: React.ReactNode;
  showCredit?: boolean;
  rounded?: number;
}) {
  const colors = useTheme();
  const [failed, setFailed] = useState(false);
  const usePhoto = !!uri && !failed;
  const credit = [attribution?.creator, attribution?.licence].filter(Boolean).join(' · ');

  return (
    <View style={{ height, width: '100%', borderRadius: rounded, overflow: rounded ? 'hidden' : 'visible' }}>
      {usePhoto ? (
        <Image
          source={{ uri }}
          style={{ width: '100%', height, backgroundColor: colors.background.sunken }}
          resizeMode="cover"
          onError={() => setFailed(true)}
          accessibilityIgnoresInvertColors
        />
      ) : (
        <Artwork id={id} category={category} height={height} />
      )}

      {overlay ? (
        <LinearGradient
          colors={['rgba(13,27,42,0)', 'rgba(13,27,42,0.16)', 'rgba(13,27,42,0.78)']}
          locations={[0, 0.45, 1]}
          style={{ position: 'absolute', left: 0, right: 0, bottom: 0, height: height * 0.75 }}
          pointerEvents="none"
        />
      ) : null}

      {children ? <View style={{ position: 'absolute', inset: 0 as never }}>{children}</View> : null}

      {showCredit && usePhoto && credit ? (
        <View
          style={{
            position: 'absolute',
            right: 0,
            bottom: 0,
            backgroundColor: 'rgba(13,27,42,0.55)',
            paddingHorizontal: 6,
            paddingVertical: 2,
            borderTopLeftRadius: radius.compact,
          }}
        >
          <T variant="caption" color="rgba(255,255,255,0.88)" numberOfLines={1} style={{ fontSize: 10 }}>
            {credit}
          </T>
        </View>
      ) : null}
    </View>
  );
}

/** Small square thumbnail for list rows. */
export function Thumb({
  id,
  uri,
  category,
  size = 84,
}: {
  id: string;
  uri?: string | null;
  category?: string;
  size?: number;
}) {
  const colors = useTheme();
  const [failed, setFailed] = useState(false);

  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: radius.control,
        overflow: 'hidden',
        backgroundColor: colors.background.sunken,
      }}
    >
      {uri && !failed ? (
        <Image
          source={{ uri }}
          style={{ width: size, height: size }}
          resizeMode="cover"
          onError={() => setFailed(true)}
          accessibilityIgnoresInvertColors
        />
      ) : (
        <Artwork id={id} category={category} height={size} />
      )}
    </View>
  );
}

export { space };
