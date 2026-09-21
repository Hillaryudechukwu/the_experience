import React from 'react';
import { View } from 'react-native';

import { radius, space, useTheme } from '../theme';
import { Icon, type IconName } from './Icon';
import { Row, T } from './primitives';

export type FitReason = { icon: IconName; text: string };

/**
 * "Why This Fits Your Trip" (Figma: 13).
 *
 * The same panel appears in recommendations, the itinerary and the detail
 * screen, because the reasoning is the product. Each line ties the suggestion
 * to something concrete the traveller told us or we can observe — a fixed
 * commitment, the window, the travel time — rather than restating that the
 * place is nice.
 */
export function WhyThisFits({
  reasons,
  title = 'Why this fits your trip',
  tone = 'sunken',
}: {
  reasons: FitReason[];
  title?: string;
  tone?: 'sunken' | 'warm' | 'plain';
}) {
  const colors = useTheme();

  if (reasons.length === 0) return null;

  const background =
    tone === 'warm' ? colors.background.warm : tone === 'plain' ? 'transparent' : colors.background.sunken;

  return (
    <View
      style={{
        backgroundColor: background,
        borderRadius: radius.card,
        padding: tone === 'plain' ? 0 : space.md,
        gap: space.sm,
      }}
    >
      <T variant="label" color={colors.text.tertiary}>
        {title}
      </T>

      <View style={{ gap: space.xs }}>
        {/* Keyed by position, not by the sentence. The scorer composes these
            lines from separate components and can legitimately produce the
            same wording twice — "fits the time you have" can come from both
            the window and the anchor check — and two identical keys make React
            reuse the wrong node rather than render the second line. */}
        {reasons.map((reason, index) => (
          <Row key={`${index}:${reason.text}`} align="flex-start" gap={space.xs}>
            <View style={{ paddingTop: 1 }}>
              <Icon name={reason.icon} size={16} color={colors.text.secondary} />
            </View>
            <T variant="small" color={colors.text.primary} style={{ flex: 1 }}>
              {reason.text}
            </T>
          </Row>
        ))}
      </View>
    </View>
  );
}

/**
 * Turns the API's free-text reasons into icon-led lines.
 *
 * The backend explains itself in sentences; the design needs a glyph per line.
 * Matching on the phrasing the scorer actually produces keeps that mapping in
 * one place rather than scattered through screens.
 */
export function toFitReasons(messages: string[]): FitReason[] {
  return messages.map((text) => ({ icon: iconFor(text), text }));
}

function iconFor(text: string): IconName {
  const t = text.toLowerCase();

  if (/(minute|min away|walk|walkable)/.test(t)) return 'walk';
  if (/(open|clos|hour|time of day|window|fits)/.test(t)) return 'clock';
  if (/(ticket|book|availab)/.test(t)) return 'ticket';
  if (/(free|price|budget|expensive|value|cost)/.test(t)) return 'money';
  if (/(weather|rain|indoor|outdoor|daylight)/.test(t)) return 'weather';
  if (/(child|family|toilet|pushchair)/.test(t)) return 'family';
  if (/(step-free|accessib|wheelchair)/.test(t)) return 'accessibility';
  if (/(food|eat|market|dining)/.test(t)) return 'food';
  if (/(queue|crowd|busy)/.test(t)) return 'person';
  if (/(rated|review)/.test(t)) return 'info';
  if (/(first visit|iconic|defining)/.test(t)) return 'compass';

  return 'sparkle';
}
