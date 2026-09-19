/**
 * Design tokens for The Experience.
 *
 * Mirrors the Figma variable structure one-to-one, so a token renamed in the
 * design file has an obvious counterpart here. Nothing in the app reaches for a
 * raw hex value: screens ask for `colors.text.primary`, not `#0D1B2A`, which is
 * what makes the light and dark modes a single switch rather than a sweep.
 */

/* ── Core palette (Figma: 6.1) ─────────────────────────────────────────── */
const palette = {
  midnightInk: '#0D1B2A',
  experienceBlue: '#2856F6',
  skyMist: '#EAF1FF',
  journeySand: '#F5EFE4',
  discoveryGreen: '#27A875',
  sunsetCoral: '#FF6B5E',
  goldenHour: '#F4B63F',
  cloud: '#F7F8FA',
  slate: '#667085',
  white: '#FFFFFF',

  night: '#08111F',
  nightSurface: '#101C2D',
  nightElevated: '#16263A',
} as const;

/* ── Semantic colour, per mode (Figma: 7) ──────────────────────────────── */
export type ColorTokens = {
  background: { base: string; elevated: string; warm: string; sunken: string };
  text: { primary: string; secondary: string; tertiary: string; inverse: string; onAccent: string };
  action: { primary: string; primaryPressed: string; secondary: string; soft: string; onSoft: string };
  status: { open: string; closingSoon: string; closed: string; warning: string; error: string; info: string };
  statusSoft: { open: string; closingSoon: string; closed: string; warning: string };
  border: { subtle: string; strong: string; focus: string };
  experience: { iconic: string; hiddenGem: string; free: string; food: string; family: string };
  scrim: string;
  overlay: string;
};

const light: ColorTokens = {
  background: {
    base: palette.cloud,
    elevated: palette.white,
    warm: palette.journeySand,
    sunken: palette.skyMist,
  },
  text: {
    primary: palette.midnightInk,
    secondary: palette.slate,
    tertiary: '#93A0B4',
    inverse: palette.white,
    onAccent: palette.white,
  },
  action: {
    primary: palette.experienceBlue,
    primaryPressed: '#1E44CC',
    secondary: palette.midnightInk,
    soft: palette.skyMist,
    onSoft: palette.experienceBlue,
  },
  status: {
    open: palette.discoveryGreen,
    closingSoon: palette.goldenHour,
    closed: palette.slate,
    warning: palette.goldenHour,
    error: palette.sunsetCoral,
    info: palette.experienceBlue,
  },
  statusSoft: {
    open: '#E4F5ED',
    closingSoon: '#FDF3E0',
    closed: '#EEF0F4',
    warning: '#FDF3E0',
  },
  border: { subtle: '#E4E8EF', strong: '#CBD3E1', focus: palette.experienceBlue },
  experience: {
    iconic: palette.experienceBlue,
    hiddenGem: '#7B5AE0',
    free: palette.discoveryGreen,
    food: palette.sunsetCoral,
    family: '#1C9BD1',
  },
  scrim: 'rgba(13,27,42,0.55)',
  overlay: 'rgba(13,27,42,0.08)',
};

const dark: ColorTokens = {
  background: {
    base: palette.night,
    elevated: palette.nightSurface,
    warm: '#1B2433',
    sunken: '#0B1524',
  },
  text: {
    primary: '#F2F6FC',
    secondary: '#94A3B8',
    tertiary: '#64748B',
    inverse: palette.midnightInk,
    onAccent: palette.white,
  },
  action: {
    primary: '#4C78FF',
    primaryPressed: '#3A63E6',
    secondary: '#F2F6FC',
    soft: '#17284A',
    onSoft: '#8FAEFF',
  },
  status: {
    open: '#3FC793',
    closingSoon: '#F6C35C',
    closed: '#64748B',
    warning: '#F6C35C',
    error: '#FF8579',
    info: '#4C78FF',
  },
  statusSoft: {
    open: '#102A22',
    closingSoon: '#2A2315',
    closed: '#1A2334',
    warning: '#2A2315',
  },
  border: { subtle: '#1F2D42', strong: '#2E3F58', focus: '#4C78FF' },
  experience: {
    iconic: '#4C78FF',
    hiddenGem: '#9B7BF0',
    free: '#3FC793',
    food: '#FF8579',
    family: '#42B4E4',
  },
  scrim: 'rgba(0,0,0,0.65)',
  overlay: 'rgba(255,255,255,0.06)',
};

export const colorModes = { light, dark };

/* ── Spacing: 4px base (Figma: 9) ──────────────────────────────────────── */
export const space = {
  xxs: 4,
  xs: 8,
  sm: 12,
  md: 16,
  lg: 20,
  xl: 24,
  xxl: 32,
  xxxl: 40,
  huge: 48,
  vast: 64,
  colossal: 80,
} as const;

/* ── Radius (Figma: 9) ─────────────────────────────────────────────────── */
export const radius = {
  compact: 8,
  control: 12,
  card: 16,
  feature: 20,
  sheet: 24,
  pill: 999,
} as const;

/* ── Elevation: restrained, never marketing-page ───────────────────────── */
export const elevation = {
  card: {
    shadowColor: '#0D1B2A',
    shadowOpacity: 0.05,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 2 },
    elevation: 2,
  },
  feature: {
    shadowColor: '#0D1B2A',
    shadowOpacity: 0.09,
    shadowRadius: 24,
    shadowOffset: { width: 0, height: 8 },
    elevation: 6,
  },
  sheet: {
    shadowColor: '#0D1B2A',
    shadowOpacity: 0.16,
    shadowRadius: 32,
    shadowOffset: { width: 0, height: -6 },
    elevation: 12,
  },
  floating: {
    shadowColor: '#0D1B2A',
    shadowOpacity: 0.18,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 6 },
    elevation: 8,
  },
} as const;

/* ── Motion (Figma: 52) ────────────────────────────────────────────────── */
export const motion = {
  instant: 120,
  quick: 180,
  standard: 240,
  considered: 320,
  reveal: 480,
} as const;

/* ── Type scale (Figma: 8) ─────────────────────────────────────────────── */
export const fonts = {
  display: 'Sora_700Bold',
  displayMedium: 'Sora_600SemiBold',
  body: 'Inter_400Regular',
  bodyMedium: 'Inter_500Medium',
  bodySemi: 'Inter_600SemiBold',
  bodyBold: 'Inter_700Bold',
} as const;

export const typeScale = {
  displayXl: { fontFamily: fonts.display, fontSize: 44, lineHeight: 48, letterSpacing: -1.2 },
  displayL: { fontFamily: fonts.display, fontSize: 34, lineHeight: 40, letterSpacing: -0.9 },
  h1: { fontFamily: fonts.display, fontSize: 28, lineHeight: 34, letterSpacing: -0.6 },
  h2: { fontFamily: fonts.displayMedium, fontSize: 24, lineHeight: 30, letterSpacing: -0.4 },
  h3: { fontFamily: fonts.displayMedium, fontSize: 20, lineHeight: 26, letterSpacing: -0.3 },
  bodyL: { fontFamily: fonts.body, fontSize: 17, lineHeight: 26 },
  body: { fontFamily: fonts.body, fontSize: 15, lineHeight: 23 },
  bodyStrong: { fontFamily: fonts.bodySemi, fontSize: 15, lineHeight: 23 },
  small: { fontFamily: fonts.body, fontSize: 13, lineHeight: 19 },
  smallStrong: { fontFamily: fonts.bodySemi, fontSize: 13, lineHeight: 19 },
  caption: { fontFamily: fonts.body, fontSize: 11, lineHeight: 15 },
  label: {
    fontFamily: fonts.bodySemi,
    fontSize: 11,
    lineHeight: 14,
    letterSpacing: 1.1,
    textTransform: 'uppercase' as const,
  },
  score: { fontFamily: fonts.display, fontSize: 26, lineHeight: 28, letterSpacing: -0.8 },
  scoreSmall: { fontFamily: fonts.display, fontSize: 16, lineHeight: 18, letterSpacing: -0.4 },
} as const;

export type TypeVariant = keyof typeof typeScale;
