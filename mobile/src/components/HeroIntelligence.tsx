import React from 'react';
import { View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';

import { elevation, radius, space, useTheme } from '../theme';
import { Icon } from './Icon';
import { Button, Row, T } from './primitives';

/**
 * Hero Intelligence Card (Figma: 16).
 *
 * The screen's one clear priority. It states the window the traveller actually
 * has, what it found, and offers a single strong action — the difference
 * between an app that lists places and one that understands that this person
 * has until eight o'clock.
 */
export function HeroIntelligence({
  headline,
  detail,
  primaryLabel,
  onPrimary,
  secondaryLabel,
  onSecondary,
  meta,
}: {
  headline: string;
  detail?: string;
  primaryLabel: string;
  onPrimary: () => void;
  secondaryLabel?: string;
  onSecondary?: () => void;
  meta?: string;
}) {
  const colors = useTheme();

  return (
    <View style={{ borderRadius: radius.feature, overflow: 'hidden', ...elevation.feature }}>
      <LinearGradient
        colors={['#1B3FD4', '#2856F6', '#4C78FF']}
        start={{ x: 0, y: 0 }}
        end={{ x: 1, y: 1 }}
        style={{ padding: space.lg, gap: space.md }}
      >
        {meta ? (
          <Row gap={6}>
            <Icon name="sparkle" size={15} color="rgba(255,255,255,0.86)" />
            <T variant="label" color="rgba(255,255,255,0.86)">
              {meta}
            </T>
          </Row>
        ) : null}

        <View style={{ gap: 6 }}>
          <T variant="h1" color="#FFFFFF">
            {headline}
          </T>
          {detail ? (
            <T variant="bodyL" color="rgba(255,255,255,0.88)">
              {detail}
            </T>
          ) : null}
        </View>

        <Row gap={space.xs}>
          <Button
            label={primaryLabel}
            onPress={onPrimary}
            tone="secondary"
            size="medium"
            haptic="medium"
            style={{ flex: 1, backgroundColor: '#FFFFFF', borderColor: 'transparent' }}
          />
          {secondaryLabel && onSecondary ? (
            <Button
              label={secondaryLabel}
              onPress={onSecondary}
              tone="tertiary"
              size="medium"
              style={{ paddingHorizontal: space.md }}
            />
          ) : null}
        </Row>
      </LinearGradient>
    </View>
  );
}
