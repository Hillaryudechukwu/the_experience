import React from 'react';
import { Image, Pressable, View, type ImageSourcePropType } from 'react-native';

import { BRAND } from '../brand';

export type WayoaLogoVariant = 'full' | 'mark';
export type WayoaLogoSize = 'xs' | 'sm' | 'md' | 'lg';

/**
 * Official WAYOA artwork.
 *
 * `full` is the horizontal wordmark. `mark` is the symbol alone, for the home
 * header and any other space too narrow for the wordmark. Sizes are the laid-out
 * height of the source file, which already includes its own clear space, so the
 * symbol is never cropped or stretched.
 */
const SOURCES = {
  full: require('../../assets/brand/wayoa-logo.png'),
  mark: require('../../assets/brand/wayoa-app-icon.png'),
} as const satisfies Record<WayoaLogoVariant, ImageSourcePropType>;

/** Canvas aspect of each master. Width follows height; the bitmap is never scaled on one axis. */
const ASPECT: Record<WayoaLogoVariant, number> = {
  full: 1024 / 341,
  mark: 1,
};

/*
 * `xs` exists for the one place the brand sits inside a box of fixed height.
 * Everywhere else the logo can set its own terms; in the city header it has a
 * budget, and 18pt is roughly the caption wordmark it replaced.
 */
const HEIGHT: Record<WayoaLogoVariant, Record<WayoaLogoSize, number>> = {
  full: { xs: 18, sm: 28, md: 48, lg: 72 },
  mark: { xs: 22, sm: 40, md: 56, lg: 88 },
};

export function WayoaLogo({
  variant = 'full',
  size = 'md',
  onPress,
  accessibilityLabel,
}: {
  variant?: WayoaLogoVariant;
  size?: WayoaLogoSize;
  /** When set, the logo is a control. It announces “WAYOA home” unless a label is passed. */
  onPress?: () => void;
  accessibilityLabel?: string;
}) {
  const height = HEIGHT[variant][size];
  const width = Math.round(height * ASPECT[variant]);
  const label = accessibilityLabel ?? (onPress ? `${BRAND.name} home` : BRAND.name);

  const image = (
    <Image
      source={SOURCES[variant]}
      accessibilityIgnoresInvertColors
      resizeMode="contain"
      accessible={false}
      importantForAccessibility="no"
      style={{ width, height, maxWidth: '100%' }}
    />
  );

  if (onPress) {
    return (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={label}
        onPress={onPress}
        hitSlop={8}
        style={({ pressed }) => ({
          minWidth: 44,
          minHeight: 44,
          maxWidth: '100%',
          justifyContent: 'center',
          opacity: pressed ? 0.85 : 1,
        })}
      >
        {image}
      </Pressable>
    );
  }

  return (
    <View accessible accessibilityRole="image" accessibilityLabel={label} style={{ width, height, maxWidth: '100%' }}>
      {image}
    </View>
  );
}
