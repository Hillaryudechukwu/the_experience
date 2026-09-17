/**
 * One place for the visual language.
 *
 * Warm paper and ink rather than the usual travel-app blue: the product is
 * about judgement and editorial voice, so it should read like something written
 * rather than something advertised.
 */
import { Platform, useColorScheme } from 'react-native';

const palette = {
  light: {
    bg: '#FBF8F3',
    surface: '#FFFFFF',
    surfaceAlt: '#F4EFE7',
    ink: '#1A1712',
    inkMuted: '#6E675C',
    inkFaint: '#9A9287',
    line: '#E7E0D4',
    accent: '#B4531F',
    accentSoft: '#FBEDE4',
    positive: '#2F6B4F',
    positiveSoft: '#E6F1EA',
    warn: '#9A6B12',
    warnSoft: '#FBF0DA',
    danger: '#A33A2C',
    scrim: 'rgba(26,23,18,0.45)',
  },
  dark: {
    bg: '#121110',
    surface: '#1C1A18',
    surfaceAlt: '#262320',
    ink: '#F6F2EB',
    inkMuted: '#A9A196',
    inkFaint: '#7C7568',
    line: '#302C27',
    accent: '#E88B52',
    accentSoft: '#2E2018',
    positive: '#7FC5A1',
    positiveSoft: '#1B2A22',
    warn: '#E0B061',
    warnSoft: '#2B2415',
    danger: '#E58C7D',
    scrim: 'rgba(0,0,0,0.6)',
  },
};

export type Palette = typeof palette.light;

export const radius = { sm: 8, md: 14, lg: 20, xl: 28, pill: 999 };
export const space = { xs: 4, sm: 8, md: 12, lg: 16, xl: 24, xxl: 32 };

export const type = {
  display: { fontSize: 30, lineHeight: 36, fontWeight: '700' as const, letterSpacing: -0.6 },
  title: { fontSize: 22, lineHeight: 28, fontWeight: '700' as const, letterSpacing: -0.4 },
  heading: { fontSize: 17, lineHeight: 23, fontWeight: '700' as const, letterSpacing: -0.2 },
  body: { fontSize: 15, lineHeight: 22, fontWeight: '400' as const },
  bodyStrong: { fontSize: 15, lineHeight: 22, fontWeight: '600' as const },
  small: { fontSize: 13, lineHeight: 18, fontWeight: '400' as const },
  label: {
    fontSize: 11,
    lineHeight: 14,
    fontWeight: '700' as const,
    letterSpacing: 0.8,
    textTransform: 'uppercase' as const,
  },
};

export const shadow = (colors: Palette, level: 1 | 2 = 1) =>
  Platform.select({
    ios: {
      shadowColor: '#000',
      shadowOpacity: level === 1 ? 0.06 : 0.12,
      shadowRadius: level === 1 ? 10 : 22,
      shadowOffset: { width: 0, height: level === 1 ? 3 : 10 },
    },
    android: { elevation: level === 1 ? 2 : 6 },
    default: {},
  })!;

export function useTheme(): Palette {
  return palette[useColorScheme() === 'dark' ? 'dark' : 'light'];
}

/** Experience Score bands. A number alone means little; the band gives it shape. */
export function scoreBand(score: number, colors: Palette) {
  if (score >= 85) return { label: 'Outstanding', fg: colors.positive, bg: colors.positiveSoft };
  if (score >= 70) return { label: 'Strong', fg: colors.accent, bg: colors.accentSoft };
  if (score >= 55) return { label: 'Worth it', fg: colors.warn, bg: colors.warnSoft };
  return { label: 'Situational', fg: colors.inkMuted, bg: colors.surfaceAlt };
}

/**
 * Deterministic artwork for an experience.
 *
 * The seed catalogue deliberately carries no photography (we do not have
 * licensed images), so each card gets a stable two-tone wash derived from its
 * id instead of a stock photo that is not of the place.
 */
export function artworkFor(id: string, category?: string): [string, string] {
  const hues = {
    culture: [268, 232],
    food_experience: [18, 40],
    nightlife: [288, 320],
    nature: [140, 96],
    romantic: [340, 12],
    family: [200, 168],
    shopping: [44, 22],
    adventure: [12, 340],
    iconic: [212, 250],
  } as Record<string, number[]>;

  let hash = 0;
  for (let i = 0; i < id.length; i++) hash = (hash * 31 + id.charCodeAt(i)) % 100000;

  const base = hues[category ?? ''] ?? [(hash % 360), ((hash % 360) + 40) % 360];
  const shift = hash % 24;

  return [
    `hsl(${(base[0] + shift) % 360}, 46%, 42%)`,
    `hsl(${(base[1] + shift) % 360}, 52%, 26%)`,
  ];
}
