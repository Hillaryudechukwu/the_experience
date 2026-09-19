import React from 'react';
import { View } from 'react-native';

import { radius, useTheme } from '../theme';
import { T } from './primitives';

export type StatusKind =
  | 'open'
  | 'closing-soon'
  | 'closed'
  | 'unknown'
  | 'available'
  | 'sold-out'
  | 'booked'
  | 'saved'
  | 'completed'
  | 'sponsored';

/**
 * Status pill (Figma: 56).
 *
 * Colour never carries the meaning alone — every state is also spelled out,
 * which is both an accessibility requirement and the only way "we don't know"
 * can be told apart from "closed".
 */
export function StatusPill({ kind, label }: { kind: StatusKind; label?: string }) {
  const colors = useTheme();

  const styles: Record<StatusKind, { fg: string; bg: string; text: string }> = {
    open: { fg: colors.status.open, bg: colors.statusSoft.open, text: 'Open now' },
    'closing-soon': { fg: colors.status.closingSoon, bg: colors.statusSoft.closingSoon, text: 'Closing soon' },
    closed: { fg: colors.status.closed, bg: colors.statusSoft.closed, text: 'Closed' },
    unknown: { fg: colors.text.tertiary, bg: colors.background.sunken, text: 'Hours not verified' },
    available: { fg: colors.status.open, bg: colors.statusSoft.open, text: 'Tickets available' },
    'sold-out': { fg: colors.text.tertiary, bg: colors.background.sunken, text: 'Sold out' },
    booked: { fg: colors.action.onSoft, bg: colors.action.soft, text: 'Booked' },
    saved: { fg: colors.action.onSoft, bg: colors.action.soft, text: 'Saved' },
    completed: { fg: colors.status.open, bg: colors.statusSoft.open, text: 'Done' },
    sponsored: { fg: colors.text.secondary, bg: colors.background.sunken, text: 'Sponsored' },
  };

  const style = styles[kind];

  return (
    <View
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        gap: 5,
        alignSelf: 'flex-start',
        paddingVertical: 4,
        paddingHorizontal: 9,
        borderRadius: radius.pill,
        backgroundColor: style.bg,
      }}
    >
      <View style={{ width: 6, height: 6, borderRadius: 3, backgroundColor: style.fg }} />
      <T variant="caption" color={style.fg}>
        {label ?? style.text}
      </T>
    </View>
  );
}

/** Derives the pill from opening-hours data, keeping unknown honest. */
export function openingStatus(known: boolean, openNow: boolean | null, closesAt: string | null): StatusKind {
  if (!known || openNow === null) return 'unknown';
  if (!openNow) return 'closed';

  if (closesAt) {
    const minutes = (new Date(closesAt).getTime() - Date.now()) / 60000;
    if (minutes > 0 && minutes <= 90) return 'closing-soon';
  }

  return 'open';
}
