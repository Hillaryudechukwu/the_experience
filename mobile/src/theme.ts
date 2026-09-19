import { Platform, useColorScheme, type ViewStyle } from 'react-native';

import {
  type ColorTokens,
  colorModes,
  elevation,
  fonts,
  motion,
  radius,
  space,
  typeScale,
  type TypeVariant,
} from './design/tokens';

export { elevation, fonts, motion, radius, space, typeScale };
export type { ColorTokens, TypeVariant };

/** Kept for call sites that still pass `type.body` style objects. */
export const type = typeScale;

export type Palette = ColorTokens;

export function useTheme(): ColorTokens {
  return colorModes[useColorScheme() === 'dark' ? 'dark' : 'light'];
}

export function useIsDark(): boolean {
  return useColorScheme() === 'dark';
}

/** Legacy helper kept for any call site not yet migrated to `elevation`. */
export const shadow = (_colors: ColorTokens, level: 1 | 2 = 1): ViewStyle => {
  if (Platform.OS === 'android') return { elevation: level === 1 ? 2 : 6 };

  return (level === 1 ? elevation.card : elevation.feature) as ViewStyle;
};

/**
 * The Experience Score band.
 *
 * The score is about fit for this traveller right now, never a verdict on the
 * place, so the language stays on the traveller's side of that line: the worst
 * band says better alternatives exist nearby, not that somewhere is bad.
 */
export type ScoreBand = {
  label: string;
  short: string;
  fg: string;
  bg: string;
  ring: string;
};

export function scoreBand(score: number, colors: ColorTokens): ScoreBand {
  if (score >= 85) {
    return {
      label: 'Exceptional fit for you',
      short: 'Exceptional fit',
      fg: '#8A5D00',
      bg: '#FDF3E0',
      ring: '#F4B63F',
    };
  }
  if (score >= 70) {
    return {
      label: 'Great fit for you',
      short: 'Great fit',
      fg: colors.action.onSoft,
      bg: colors.action.soft,
      ring: colors.action.primary,
    };
  }
  if (score >= 55) {
    return {
      label: 'Worth considering',
      short: 'Worth considering',
      fg: colors.text.secondary,
      bg: colors.background.sunken,
      ring: colors.text.secondary,
    };
  }

  return {
    label: 'Better alternatives nearby',
    short: 'Alternatives nearby',
    fg: colors.text.tertiary,
    bg: colors.background.sunken,
    ring: colors.text.tertiary,
  };
}

/** Category accent, drawn from the experience colour tokens. */
export function categoryAccent(key: string | undefined, colors: ColorTokens): string {
  switch (key) {
    case 'iconic':
    case 'must_experience':
      return colors.experience.iconic;
    case 'hidden_gem':
    case 'local_favourite':
      return colors.experience.hiddenGem;
    case 'free':
      return colors.experience.free;
    case 'food_experience':
      return colors.experience.food;
    case 'family':
      return colors.experience.family;
    case 'nature':
      return colors.status.open;
    case 'nightlife':
      return '#7B5AE0';
    default:
      return colors.action.primary;
  }
}

/**
 * Deterministic stand-in artwork.
 *
 * Used only where no licensed photograph exists. Hues are drawn towards the
 * brand's blues and sands rather than being arbitrary, so a screen of mixed
 * real and generated imagery still reads as one product.
 */
export function artworkFor(id: string, category?: string): [string, string] {
  const families: Record<string, [number, number]> = {
    iconic: [222, 248],
    must_experience: [222, 248],
    culture: [258, 226],
    hidden_gem: [268, 232],
    local_favourite: [268, 232],
    food_experience: [12, 32],
    nightlife: [280, 310],
    nature: [156, 122],
    romantic: [340, 8],
    family: [196, 172],
    free: [162, 138],
  };

  let hash = 0;
  for (let i = 0; i < id.length; i++) hash = (hash * 31 + id.charCodeAt(i)) % 100000;

  const [from, to] = families[category ?? ''] ?? [212 + (hash % 40), 236 + (hash % 30)];
  const drift = hash % 14;

  return [
    `hsl(${(from + drift) % 360}, 42%, 38%)`,
    `hsl(${(to + drift) % 360}, 48%, 20%)`,
  ];
}
