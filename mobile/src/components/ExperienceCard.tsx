import React from 'react';
import { Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { recordEvents, useToggleSave } from '../api/hooks';
import type { ExperienceCard as CardData } from '../api/types';
import { categoryAccent, radius, space, useTheme } from '../theme';
import { Icon } from './Icon';
import { ScoreMedallion, ScorePill, ScoreRing } from './ExperienceScore';
import { Photo, Thumb } from './Photo';
import { Card, CardPress, Row, T } from './primitives';
import { toFitReasons, WhyThisFits } from './WhyThisFits';

type Variant = 'editorial' | 'compact' | 'recommendation' | 'rail' | 'feature';

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

  /* ── Feature: the top-pick card the home rail is built around ────────── */
  if (variant === 'feature') {
    const fit = card.why[0] ?? null;

    return (
      /*
       * The card is not one big button with a heart inside it.
       * Saving and opening are two different actions, and nesting one
       * control inside another gives a keyboard or screen-reader user no way
       * to reach the inner one — the browser rejects the markup outright.
       * So the card body is the button, and the heart sits beside it.
       */
      <Card level="feature" style={{ width: 272 }}>
        <CardPress
          onPress={open}
          accessibilityLabel={`${card.title}. ${card.experience_score !== null ? `Experience score ${card.experience_score}. ` : ''}Open details.`}
        >
          <Photo
            id={card.id}
            uri={card.image_url}
            attribution={card.image_attribution}
            category={primary?.key}
            height={152}
            creditAlign="top"
          />

          <View style={{ padding: space.md, gap: 6 }}>
            <T variant="h3" numberOfLines={1} style={{ paddingRight: 46 }}>
              {card.title}
            </T>

            {card.categories.length > 0 ? (
              <T variant="small" color={colors.text.secondary} numberOfLines={1}>
                {card.categories.slice(0, 3).map((c) => c.label).join(' · ')}
              </T>
            ) : null}

            {fit ? (
              <Row gap={5} align="flex-start" style={{ marginTop: 2 }}>
                <Icon name="check" size={14} color={colors.status.open} strokeWidth={2.2} />
                <T variant="small" color={colors.status.open} style={{ flex: 1 }} numberOfLines={1}>
                  {fit}
                </T>
              </Row>
            ) : null}

            {/* Right padding keeps the meta line clear of the heart, which is
                laid over this row rather than inside it. */}
            <T
              variant="small"
              color={colors.text.secondary}
              numberOfLines={1}
              style={{ marginTop: 2, paddingRight: 36 }}
            >
              {[
                card.travel ? `${card.travel.minutes} min away` : null,
                durationLabel(card.duration_minutes),
                priceLabel(card),
              ]
                .filter(Boolean)
                .join(' · ')}
            </T>
          </View>
        </CardPress>

        {/* The medallion straddles the photograph and the body. Sitting it on
            the seam is what makes the score read as belonging to the card
            rather than being stamped onto the picture. */}
        {card.experience_score !== null ? (
          <View style={{ position: 'absolute', top: 152 - 34, right: space.sm }} pointerEvents="none">
            <ScoreMedallion score={card.experience_score} size="compact" />
          </View>
        ) : null}

        <View style={{ position: 'absolute', right: space.sm, bottom: space.sm }}>
          <SaveHeart id={card.id} saved={false} />
        </View>
      </Card>
    );
  }

  /* ── Rail: horizontal scroller, minimal fields ───────────────────────── */
  if (variant === 'rail') {
    return (
      <Pressable onPress={open} style={({ pressed }) => ({ width: 190, opacity: pressed ? 0.9 : 1 })}>
        <View style={{ borderRadius: radius.card, overflow: 'hidden' }}>
          <Photo
            id={card.id}
            uri={card.image_url}
            attribution={card.image_attribution}
            category={primary?.key}
            height={124}
            overlay
            creditAlign="top"
          >
            {card.experience_score !== null ? (
              <View style={{ position: 'absolute', top: space.xs, left: space.xs }}>
                <ScorePill score={card.experience_score} compact />
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
      <Card style={{ marginBottom: space.sm }} level="card">
        <CardPress onPress={open} accessibilityLabel={`${card.title}. Open details.`}>
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
        </CardPress>
      </Card>
    );
  }

  /* ── Recommendation: the full personalised treatment ─────────────────── */
  const showWhy = variant === 'recommendation' && card.why.length > 0;

  return (
    <Card style={{ marginBottom: space.md }} level={variant === 'recommendation' ? 'feature' : 'card'}>
      <CardPress onPress={open} accessibilityLabel={`${card.title}. Open details.`}>
        <Photo
          id={card.id}
          uri={card.image_url}
          attribution={card.image_attribution}
          category={primary?.key}
          height={variant === 'recommendation' ? 176 : 132}
          overlay
          creditAlign="top"
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
      </CardPress>
    </Card>
  );
}

/**
 * The save control that sits on a card.
 *
 * Optimistic on purpose: a heart that waits for a round trip before filling in
 * feels broken on a slow connection, and the worst case of being wrong is that
 * it flips back.
 */
function SaveHeart({ id, saved }: { id: string; saved: boolean }) {
  const colors = useTheme();
  const toggle = useToggleSave(id);
  const [on, setOn] = React.useState(saved);

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={on ? 'Remove from saved' : 'Save for later'}
      accessibilityState={{ selected: on }}
      hitSlop={12}
      onPress={() => {
        const next = !on;
        setOn(next);
        toggle.mutate(on, { onError: () => setOn(!next) });
      }}
      style={({ pressed }) => ({ padding: 4, opacity: pressed ? 0.6 : 1 })}
    >
      <Icon
        name={on ? 'saved' : 'save'}
        size={19}
        color={on ? colors.experience.food : colors.text.tertiary}
        strokeWidth={1.9}
      />
    </Pressable>
  );
}
