import React, { useEffect, useRef } from 'react';
import { Animated, Easing, Pressable, View } from 'react-native';
import Svg, { Circle } from 'react-native-svg';

import { elevation, motion, radius, scoreBand, space, typeScale, useTheme } from '../theme';
import { Row, T } from './primitives';

const AnimatedCircle = Animated.createAnimatedComponent(Circle);

type Size = 'compact' | 'medium' | 'large';

const DIMENSIONS: Record<Size, { box: number; stroke: number; variant: 'scoreSmall' | 'score' }> = {
  compact: { box: 40, stroke: 3, variant: 'scoreSmall' },
  medium: { box: 54, stroke: 3.5, variant: 'score' },
  large: { box: 84, stroke: 5, variant: 'score' },
};

/**
 * The Experience Score (Figma: 12).
 *
 * A ring, never stars. This is a personalised fit score, not a public quality
 * rating, and it has to be visually distinct from the external review number
 * that often sits beside it — otherwise travellers read our "72" as a verdict
 * on the place rather than an answer about their afternoon.
 *
 * The arc animates once on mount and then holds. Scores that keep moving read
 * as decoration.
 */
export function ScoreRing({
  score,
  size = 'medium',
  animate = true,
}: {
  score: number;
  size?: Size;
  animate?: boolean;
}) {
  const colors = useTheme();
  const band = scoreBand(score, colors);
  const { box, stroke, variant } = DIMENSIONS[size];

  const r = (box - stroke) / 2 - 1;
  const circumference = 2 * Math.PI * r;
  const progress = useRef(new Animated.Value(animate ? 0 : 1)).current;

  useEffect(() => {
    if (!animate) return;

    const animation = Animated.timing(progress, {
      toValue: 1,
      duration: motion.reveal,
      easing: Easing.out(Easing.cubic),
      useNativeDriver: false,
    });

    animation.start();

    return () => animation.stop();
  }, [animate, progress, score]);

  const offset = progress.interpolate({
    inputRange: [0, 1],
    outputRange: [circumference, circumference * (1 - Math.max(0, Math.min(100, score)) / 100)],
  });

  return (
    <View style={{ width: box, height: box, alignItems: 'center', justifyContent: 'center' }}>
      <Svg width={box} height={box} style={{ position: 'absolute', transform: [{ rotate: '-90deg' }] }}>
        <Circle cx={box / 2} cy={box / 2} r={r} stroke={colors.border.subtle} strokeWidth={stroke} fill="none" />
        <AnimatedCircle
          cx={box / 2}
          cy={box / 2}
          r={r}
          stroke={band.ring}
          strokeWidth={stroke}
          strokeLinecap="round"
          fill="none"
          strokeDasharray={circumference}
          strokeDashoffset={offset as unknown as number}
        />
      </Svg>
      <T variant={variant} color={colors.text.primary}>
        {score}
      </T>
    </View>
  );
}

/**
 * The score as it appears over photography (Figma: 3, 5, 12).
 *
 * The plain ring is drawn on whatever is behind it, which is fine on a card
 * but unreadable over a photograph. This puts it on an opaque disc with the
 * words "EXPERIENCE SCORE" set small beneath the number — the label is what
 * stops a traveller reading 94 as a review score out of a hundred, and it is
 * the one place in the product where that distinction has to survive being
 * glanced at from across a hero image.
 */
export function ScoreMedallion({
  score,
  size = 'medium',
  onPress,
}: {
  score: number;
  size?: Size;
  onPress?: () => void;
}) {
  const colors = useTheme();
  const band = scoreBand(score, colors);
  const box = DIMENSIONS[size].box + (size === 'large' ? 22 : 18);

  const body = (
    <View
      style={{
        width: box,
        height: box,
        borderRadius: box / 2,
        backgroundColor: colors.background.elevated,
        alignItems: 'center',
        justifyContent: 'center',
        ...elevation.feature,
      }}
    >
      <Svg width={box - 6} height={box - 6} style={{ position: 'absolute', transform: [{ rotate: '-90deg' }] }}>
        <Circle
          cx={(box - 6) / 2}
          cy={(box - 6) / 2}
          r={(box - 6) / 2 - 3}
          stroke={colors.border.subtle}
          strokeWidth={3}
          fill="none"
        />
        <Circle
          cx={(box - 6) / 2}
          cy={(box - 6) / 2}
          r={(box - 6) / 2 - 3}
          stroke={band.ring}
          strokeWidth={3}
          strokeLinecap="round"
          fill="none"
          strokeDasharray={2 * Math.PI * ((box - 6) / 2 - 3)}
          strokeDashoffset={2 * Math.PI * ((box - 6) / 2 - 3) * (1 - Math.max(0, Math.min(100, score)) / 100)}
        />
      </Svg>
      <T variant={size === 'compact' ? 'scoreSmall' : 'score'} color={colors.text.primary}>
        {score}
      </T>
      {size !== 'compact' ? (
        <T
          variant="caption"
          color={colors.text.tertiary}
          style={{ fontSize: 6.5, letterSpacing: 0.35, marginTop: -2 }}
          numberOfLines={1}
        >
          EXPERIENCE SCORE
        </T>
      ) : null}
    </View>
  );

  if (!onPress) return body;

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`Experience score ${score} out of 100. ${band.label}. Tap to see why.`}
      hitSlop={8}
      style={({ pressed }) => ({ opacity: pressed ? 0.85 : 1 })}
    >
      {body}
    </Pressable>
  );
}

/** Ring plus the fit language, for hero and detail placements. */
export function ScoreBadge({
  score,
  size = 'large',
  onExplain,
}: {
  score: number;
  size?: Size;
  onExplain?: () => void;
}) {
  const colors = useTheme();
  const band = scoreBand(score, colors);

  const content = (
    <Row gap={space.sm}>
      <ScoreRing score={score} size={size} />
      <View style={{ gap: 2, flexShrink: 1 }}>
        <T variant="label" color={colors.text.tertiary}>
          Experience Score
        </T>
        <T variant="bodyStrong" color={colors.text.primary}>
          {band.label}
        </T>
        {onExplain ? (
          <T variant="small" color={colors.action.primary}>
            Why {score}?
          </T>
        ) : null}
      </View>
    </Row>
  );

  if (!onExplain) return content;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`Experience Score ${score}. ${band.label}. Tap to see why.`}
      onPress={onExplain}
      style={({ pressed }) => ({ opacity: pressed ? 0.8 : 1 })}
    >
      {content}
    </Pressable>
  );
}

/**
 * Compact pill for dense lists and map sheets.
 *
 * `compact` drops the band label. On a 190px rail card the full form truncated
 * to "69 · Worth consider…", which reads as a rendering fault rather than a
 * score — the number alone is better than a broken sentence.
 */
export function ScorePill({ score, compact = false }: { score: number; compact?: boolean }) {
  const colors = useTheme();
  const band = scoreBand(score, colors);

  return (
    <View
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        gap: 6,
        paddingVertical: 5,
        paddingHorizontal: compact ? 8 : 10,
        borderRadius: radius.pill,
        backgroundColor: band.bg,
      }}
    >
      <View style={{ width: 7, height: 7, borderRadius: 4, backgroundColor: band.ring }} />
      <T variant="smallStrong" color={band.fg} numberOfLines={1}>
        {compact ? score : `${score} · ${band.short}`}
      </T>
    </View>
  );
}

/**
 * "Why 94?" (Figma: 12).
 *
 * Positives and considerations are shown in the same panel on purpose. A score
 * the traveller cannot interrogate is a number to be distrusted, and hiding the
 * downsides is how a recommendation engine loses the benefit of the doubt.
 */
export function ScoreExplanation({
  score,
  positives,
  considerations,
}: {
  score: number;
  positives: string[];
  considerations: string[];
}) {
  const colors = useTheme();
  const band = scoreBand(score, colors);

  return (
    <View style={{ gap: space.lg }}>
      <Row gap={space.md}>
        <ScoreRing score={score} size="large" />
        <View style={{ flex: 1, gap: 2 }}>
          <T variant="h2">Why {score}?</T>
          <T variant="body" color={colors.text.secondary}>
            {band.label}
          </T>
        </View>
      </Row>

      {positives.length > 0 ? (
        <View style={{ gap: space.xs }}>
          {positives.map((reason) => (
            <Row key={reason} align="flex-start" gap={space.xs}>
              <T variant="bodyStrong" color={colors.status.open} style={{ width: 14 }}>
                +
              </T>
              <T variant="body" style={{ flex: 1 }}>
                {reason}
              </T>
            </Row>
          ))}
        </View>
      ) : null}

      {considerations.length > 0 ? (
        <View style={{ gap: space.xs }}>
          <T variant="label" color={colors.text.tertiary}>
            Consider
          </T>
          {considerations.map((reason) => (
            <Row key={reason} align="flex-start" gap={space.xs}>
              <T variant="body" color={colors.text.tertiary} style={{ width: 14 }}>
                •
              </T>
              <T variant="body" color={colors.text.secondary} style={{ flex: 1 }}>
                {reason}
              </T>
            </Row>
          ))}
        </View>
      ) : null}

      <T variant="caption" color={colors.text.tertiary}>
        The Experience Score measures fit for you, right now — not how good a place is in general.
      </T>
    </View>
  );
}

export { scoreBand, typeScale };
