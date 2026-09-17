import React from 'react';
import { View } from 'react-native';
import { useRouter } from 'expo-router';

import type { ExperienceCard as Card } from '../api/types';
import { radius, space, useTheme } from '../theme';
import { Artwork, Card as Surface, Row, ScoreBadge, T } from './primitives';
import { recordEvents } from '../api/hooks';

function priceLabel(card: Card): string {
  if (card.is_free) return 'Free';
  if (!card.price_from) return 'Price not verified';

  return `From ${card.price_from.formatted}`;
}

function durationLabel(minutes: number): string {
  if (minutes < 60) return `${minutes} min`;
  const hours = minutes / 60;

  return `${Number.isInteger(hours) ? hours : hours.toFixed(1)} hr`;
}

export function ExperienceCardView({
  card,
  recommendationSetId,
  surface,
  compact,
}: {
  card: Card;
  recommendationSetId?: string;
  surface?: string;
  compact?: boolean;
}) {
  const colors = useTheme();
  const router = useRouter();
  const primary = card.categories[0];

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
    router.push(`/experience/${card.id}`);
  };

  return (
    <Surface onPress={open} style={{ marginBottom: space.md }}>
      {!compact && <Artwork id={card.id} category={primary?.key} height={104} label={primary?.label} />}

      <View style={{ padding: space.lg, gap: space.sm }}>
        <Row style={{ alignItems: 'flex-start', gap: space.md }}>
          <View style={{ flex: 1, gap: 2 }}>
            <T variant="heading" numberOfLines={2}>
              {card.title}
            </T>
            <T variant="small" color={colors.inkMuted} numberOfLines={2}>
              {card.summary}
            </T>
          </View>
          {card.experience_score !== null && <ScoreBadge score={card.experience_score} size={compact ? 'sm' : 'md'} />}
        </Row>

        <Row gap={space.sm} wrap>
          <T variant="small" color={colors.inkMuted}>
            {priceLabel(card)}
          </T>
          <T variant="small" color={colors.inkFaint}>
            ·
          </T>
          <T variant="small" color={colors.inkMuted}>
            {durationLabel(card.duration_minutes)}
          </T>
          {card.travel && (
            <>
              <T variant="small" color={colors.inkFaint}>
                ·
              </T>
              <T variant="small" color={colors.inkMuted}>
                {card.travel.minutes} min {card.travel.mode === 'walk' ? 'walk' : 'away'}
              </T>
            </>
          )}
          {card.rating && (
            <>
              <T variant="small" color={colors.inkFaint}>
                ·
              </T>
              <T variant="small" color={colors.inkMuted}>
                {card.rating.value.toFixed(1)} ({formatCount(card.rating.count)})
              </T>
            </>
          )}
        </Row>

        {card.why.length > 0 && (
          <View
            style={{
              backgroundColor: colors.surfaceAlt,
              borderRadius: radius.md,
              padding: space.md,
              gap: 4,
            }}
          >
            {card.why.slice(0, 2).map((reason) => (
              <T key={reason} variant="small" color={colors.ink}>
                {reason}
              </T>
            ))}
            {card.caveats.slice(0, 1).map((caveat) => (
              <T key={caveat} variant="small" color={colors.inkMuted}>
                But: {lowerFirst(caveat)}
              </T>
            ))}
          </View>
        )}
      </View>
    </Surface>
  );
}

function formatCount(count: number): string {
  if (count >= 1000) return `${Math.round(count / 1000)}k`;

  return String(count);
}

function lowerFirst(value: string): string {
  return value.charAt(0).toLowerCase() + value.slice(1);
}
