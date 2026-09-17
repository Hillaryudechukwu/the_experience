import React, { useState } from 'react';
import { Image, View } from 'react-native';

import { space, useTheme } from '../theme';
import { Artwork, T } from './primitives';

export type ImageAttribution = {
  creator: string | null;
  licence: string | null;
  licence_url: string | null;
  source_url: string | null;
} | null;

/**
 * A photograph of the place, or an honest stand-in.
 *
 * Most imagery comes from Wikimedia Commons under Creative Commons terms,
 * which oblige us to name the photographer and the licence wherever the image
 * appears. The credit is part of the component rather than something each
 * screen has to remember, so an image can never be rendered without it.
 *
 * Where we have no licensed photograph, a deterministic wash is drawn instead
 * of a stock photo of somewhere else.
 */
export function Photo({
  id,
  uri,
  attribution,
  category,
  height = 150,
  label,
  showCredit = true,
}: {
  id: string;
  uri?: string | null;
  attribution?: ImageAttribution;
  category?: string;
  height?: number;
  label?: string;
  showCredit?: boolean;
}) {
  const colors = useTheme();
  const [failed, setFailed] = useState(false);

  if (!uri || failed) {
    return <Artwork id={id} category={category} height={height} label={label} />;
  }

  const credit = [attribution?.creator, attribution?.licence].filter(Boolean).join(' · ');

  return (
    <View>
      <Image
        source={{ uri }}
        style={{ width: '100%', height, backgroundColor: colors.surfaceAlt }}
        resizeMode="cover"
        onError={() => setFailed(true)}
        accessibilityIgnoresInvertColors
      />

      {label ? (
        <View
          style={{
            position: 'absolute',
            left: space.md,
            top: space.md,
            backgroundColor: 'rgba(0,0,0,0.55)',
            borderRadius: 6,
            paddingHorizontal: 8,
            paddingVertical: 3,
          }}
        >
          <T variant="label" color="#FFFFFF">
            {label}
          </T>
        </View>
      ) : null}

      {showCredit && credit ? (
        <View
          style={{
            position: 'absolute',
            right: 0,
            bottom: 0,
            backgroundColor: 'rgba(0,0,0,0.5)',
            paddingHorizontal: 6,
            paddingVertical: 2,
          }}
        >
          <T variant="small" color="rgba(255,255,255,0.9)" numberOfLines={1} style={{ fontSize: 10 }}>
            {credit}
          </T>
        </View>
      ) : null}
    </View>
  );
}
