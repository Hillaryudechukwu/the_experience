import React from 'react';
import { Pressable, ScrollView, View } from 'react-native';

import type { Anchor } from '../api/types';
import { clock, windowLabel } from '../lib/format';
import { radius, space, useTheme } from '../theme';
import { Icon } from './Icon';
import { Row, T } from './primitives';

type Slot =
  | { kind: 'anchor'; at: string; title: string }
  | { kind: 'free'; at: string; minutes: number };

/**
 * Anchor strip (Figma: 16, 25).
 *
 * Fixed commitments are drawn as solid, locked markers; the gaps between them
 * are drawn as invitations. Making the free window the visually appealing part
 * is the entire point — that is the thing the traveller can actually act on.
 */
export function AnchorStrip({
  anchors,
  timezone,
  onPressFree,
}: {
  anchors: Anchor[];
  timezone?: string;
  onPressFree?: (minutes: number) => void;
}) {
  const colors = useTheme();
  const slots = buildSlots(anchors);

  if (slots.length === 0) return null;

  return (
    <ScrollView
      horizontal
      showsHorizontalScrollIndicator={false}
      contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.xs }}
    >
      {slots.map((slot, index) =>
        slot.kind === 'anchor' ? (
          <View
            key={`${slot.at}-${index}`}
            style={{
              paddingVertical: space.xs,
              paddingHorizontal: space.sm,
              borderRadius: radius.control,
              backgroundColor: colors.background.elevated,
              borderWidth: 1,
              borderColor: colors.border.strong,
              gap: 2,
              minWidth: 116,
            }}
          >
            <Row gap={5}>
              <Icon name="lock" size={12} color={colors.text.tertiary} strokeWidth={1.9} />
              <T variant="caption" color={colors.text.tertiary}>
                {clock(slot.at, timezone)}
              </T>
            </Row>
            <T variant="smallStrong" numberOfLines={1}>
              {slot.title}
            </T>
          </View>
        ) : (
          <Pressable
            key={`${slot.at}-${index}`}
            accessibilityRole="button"
            onPress={() => onPressFree?.(slot.minutes)}
            style={({ pressed }) => ({
              paddingVertical: space.xs,
              paddingHorizontal: space.sm,
              borderRadius: radius.control,
              backgroundColor: colors.action.soft,
              gap: 2,
              minWidth: 116,
              opacity: pressed ? 0.85 : 1,
            })}
          >
            <Row gap={5}>
              <Icon name="clock" size={12} color={colors.action.onSoft} strokeWidth={1.9} />
              <T variant="caption" color={colors.action.onSoft}>
                {clock(slot.at, timezone)}
              </T>
            </Row>
            <T variant="smallStrong" color={colors.action.onSoft}>
              {windowLabel(slot.minutes)} free
            </T>
          </Pressable>
        ),
      )}
    </ScrollView>
  );
}

function buildSlots(anchors: Anchor[]): Slot[] {
  const sorted = [...anchors].sort((a, b) => a.starts_at.localeCompare(b.starts_at));
  const slots: Slot[] = [];

  sorted.forEach((anchor, index) => {
    slots.push({ kind: 'anchor', at: anchor.starts_at, title: anchor.title });

    const next = sorted[index + 1];
    if (!next) return;

    /* The gap is measured against the next anchor's protected window, not its
       start time, so the strip never offers a window that is really buffer. */
    const gap = (new Date(next.protected_from).getTime() - new Date(anchor.ends_at).getTime()) / 60000;

    if (gap >= 45) {
      slots.push({ kind: 'free', at: anchor.ends_at, minutes: Math.round(gap) });
    }
  });

  return slots;
}
