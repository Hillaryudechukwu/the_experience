import React from 'react';
import { Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { recordEvents } from '../api/hooks';
import type { ExperienceCard as CardData } from '../api/types';
import { categoryAccent, radius, space, useTheme } from '../theme';
import { Icon } from './Icon';
import { ScorePill, ScoreRing } from './ExperienceScore';
import { Photo, Thumb } from './Photo';
import { Card, Row, T } from './primitives';
import { toFitReasons, WhyThisFits } from './WhyThisFits';

type Variant = 'editorial' | 'compact' | 'recommendation' | 'rail';

function priceLabel(card: CardData): string {
  if (card.is_free) return 'Free';
  if (!card.price_from) return 'Price not verified';

  return `From ${card.price_from.formatted}`;
}

function durationLabel(minutes: number): string {
  if (minutes < 60) return `${minutes} min`;
  const hours = minutes / 60;

  return `${Number.isInteger(hours) ? hours : hours.toFixed(1)} hr`;
}

/**
 * Experience card system (Figma: 19).
 *
 * Information priority is fixed — score, title, why it fits, duration,
 * distance, price, status — but no variant shows all of it. A rail card that
 * tried to carry every field would be unreadable at 180px, and cramming the
 * fields in is what makes a product look like a marketplace grid.
 */
export function ExperienceCardView({
  card,
  variant = 'editorial',
  recommendationSetId,
  surface,
  onPress,
}: {
  card: CardData;
  variant?: Variant;
  recommendationSetId?: string;
  surface?: string;
  onPress?: () => void;
}) {
  const colors = useTheme();
  const router = useRouter();
  const primary = card.categories[0];
  const accent = categoryAccent(primary?.key, colors);

  const open = () => {
    recordEvents([
      {
        type: 'view',
        subject_type: 'experience',
        subject_id: card.id,
        recommendation_set_id: recommendationSetId,
        surface,
      },
    ]);
    onPress ? onPress() : router.push(`/experience/${card.id}`);
  };

  const meta = (
    <Row gap={space.sm} wrap>
      <Row gap={4}>
        <Icon name="clock" size={14} color={colors.text.tertiary} />
        <T variant="small" color={colors.text.secondary}>
          {durationLabel(card.duration_minutes)}
        </T>
      </Row>
      {card.travel ? (
        <Row gap={4}>
          <Icon name={card.travel.mode === 'walk' ? 'walk' : 'transit'} size={14} color={colors.text.tertiary} />
          <T variant="small" color={colors.text.secondary}>
            {card.travel.minutes} min
          </T>
        </Row>
      ) : null}
      <Row gap={4}>
        <Icon name="money" size={14} color={colors.text.tertiary} />
        <T variant="small" color={card.is_free ? colors.status.open : colors.text.secondary}>
          {priceLabel(card)}
        </T>
      </Row>
    </Row>
  );

  /* ── Rail: horizontal scroller, minimal fields ───────────────────────── */
  if (variant === 'rail') {
    return (
      <Pressable onPress={open} style={({ pressed }) => ({ width: 190, opacity: pressed ? 0.9 : 1 })}>
        <View style={{ borderRadius: radius.card, overflow: 'hidden' }}>
          <Photo id={card.id} uri={card.image_url} attribution={card.image_attribution} category={primary?.key} height={124} overlay>
            {card.experience_score !== null ? (
              <View style={{ position: 'absolute', top: space.xs, left: space.xs }}>
                <ScorePill score={card.experience_score} />
              </View>
            ) : null}
          </Photo>
        </View>
        <View style={{ paddingTop: space.xs, gap: 2 }}>
          <T variant="bodyStrong" numberOfLines={2}>
            {card.title}
          </T>
          <T variant="small" color={colors.text.secondary} numberOfLines={1}>
            {card.is_free ? 'Free' : (card.price_from?.formatted ?? '—')}
            {card.travel ? ` · ${card.travel.minutes} min` : ''}
          </T>
        </View>
      </Pressable>
    );
  }

  /* ── Compact: thumbnail row for dense lists ──────────────────────────── */
  if (variant === 'compact') {
    return (
      <Card onPress={open} style={{ marginBottom: space.sm }} level="card">
        <Row align="flex-start" gap={space.sm} style={{ padding: space.sm }}>
          <Thumb id={card.id} uri={card.image_url} category={primary?.key} size={76} />
          <View style={{ flex: 1, gap: 4 }}>
            <T variant="bodyStrong" numberOfLines={2}>
              {card.title}
            </T>
            {meta}
          </View>
          {card.experience_score !== null ? <ScoreRing score={card.experience_score} size="compact" animate={false} /> : null}
        </Row>
      </Card>
    );
  }

  /* ── Recommendation: the full personalised treatment ─────────────────── */
  const showWhy = variant === 'recommendation' && card.why.length > 0;

  return (
    <Card onPress={open} style={{ marginBottom: space.md }} level={variant === 'recommendation' ? 'feature' : 'card'}>
      <Photo
        id={card.id}
        uri={card.image_url}
        attribution={card.image_attribution}
        category={primary?.key}
        height={variant === 'recommendation' ? 176 : 132}
        overlay
      >
        <View style={{ flex: 1, justifyContent: 'space-between', padding: space.sm }}>
          <Row justify="space-between" align="flex-start">
            {primary ? (
              <View
                style={{
                  paddingVertical: 4,
                  paddingHorizontal: 9,
                  borderRadius: radius.pill,
                  backgroundColor: accent,
                }}
              >
                <T variant="caption" color="#FFFFFF">
                  {primary.label}
                </T>
              </View>
            ) : (
              <View />
            )}
          </Row>

          <View>
            <T variant={variant === 'recommendation' ? 'h2' : 'h3'} color="#FFFFFF" numberOfLines={2}>
              {card.title}
            </T>
            {card.location?.neighbourhood ? (
              <T variant="small" color="rgba(255,255,255,0.82)">
                {card.location.neighbourhood}
              </T>
            ) : null}
          </View>
        </View>
      </Photo>

      <View style={{ padding: space.md, gap: space.sm }}>
        <Row justify="space-between" align="flex-start" gap={space.sm}>
          <View style={{ flex: 1, gap: space.xs }}>
            <T variant="small" color={colors.text.secondary} numberOfLines={2}>
              {card.summary}
            </T>
            {meta}
          </View>
          {card.experience_score !== null ? (
            <ScoreRing score={card.experience_score} size={variant === 'recommendation' ? 'medium' : 'compact'} />
          ) : null}
        </Row>

        {showWhy ? <WhyThisFits reasons={toFitReasons(card.why.slice(0, 3))} title="Why this fits" /> : null}

        {!showWhy && card.why.length > 0 ? (
          <Row gap={6} align="flex-start">
            <Icon name="sparkle" size={14} color={colors.action.primary} />
            <T variant="small" color={colors.text.primary} style={{ flex: 1 }} numberOfLines={2}>
              {card.why[0]}
            </T>
          </Row>
        ) : null}

        {card.caveats.length > 0 && variant === 'recommendation' ? (
          <Row gap={6} align="flex-start">
            <Icon name="info" size={14} color={colors.text.tertiary} />
            <T variant="small" color={colors.text.tertiary} style={{ flex: 1 }} numberOfLines={2}>
              {card.caveats[0]}
            </T>
          </Row>
        ) : null}
      </View>
    </Card>
  );
}
