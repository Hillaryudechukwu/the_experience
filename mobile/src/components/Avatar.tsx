import React from 'react';
import { View } from 'react-native';

import { Icon } from './Icon';
import { T } from './primitives';
import { useTheme } from '../theme';

/**
 * The traveller's initials in a circle, or a silhouette for a guest.
 *
 * The header button used to be the silhouette for everyone, so a signed-in
 * traveller had nothing on screen saying which account they were in.
 */
export function Avatar({
  initials,
  size = 44,
  onDark = false,
}: {
  initials?: string | null;
  size?: number;
  /** Over the city photo: translucent white rather than the brand fill. */
  onDark?: boolean;
}) {
  const colors = useTheme();
  const background = onDark ? 'rgba(255,255,255,0.18)' : colors.action.primary;
  const ink = '#FFFFFF';

  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: size / 2,
        backgroundColor: background,
        borderWidth: onDark ? 1 : 0,
        borderColor: 'rgba(255,255,255,0.4)',
        alignItems: 'center',
        justifyContent: 'center',
      }}
    >
      {initials ? (
        <T variant={size >= 56 ? 'h2' : 'bodyStrong'} color={ink} style={{ letterSpacing: 0.5 }}>
          {initials}
        </T>
      ) : (
        <Icon name="person" size={Math.round(size * 0.45)} color={ink} strokeWidth={1.8} />
      )}
    </View>
  );
}
