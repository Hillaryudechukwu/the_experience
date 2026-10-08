import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text as RNText,
  View,
  type StyleProp,
  type TextStyle,
  type ViewStyle,
} from 'react-native';
import * as Haptics from 'expo-haptics';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useChrome } from '../store/chrome';
import { elevation, radius, space, typeScale, type ColorTokens, type TypeVariant, useTheme } from '../theme';

/* ── Layout ────────────────────────────────────────────────────────────── */

export function Screen({
  children,
  scroll = true,
  contentStyle,
  refreshControl,
  edges = ['top', 'left', 'right'],
  tone = 'base',
}: {
  children: React.ReactNode;
  scroll?: boolean;
  contentStyle?: StyleProp<ViewStyle>;
  refreshControl?: React.ReactElement<import('react-native').RefreshControlProps>;
  edges?: ('top' | 'bottom' | 'left' | 'right')[];
  tone?: 'base' | 'warm' | 'elevated';
}) {
  const colors = useTheme();
  const background =
    tone === 'warm' ? colors.background.warm : tone === 'elevated' ? colors.background.elevated : colors.background.base;

  const setGuideHidden = useChrome((state) => state.setGuideHidden);
  const lastOffset = React.useRef(0);

  /* Whatever this screen did to the guide button, undo it on the way out.
     Otherwise a screen left mid-scroll hides the button on the tab you switch
     to, where nothing is scrolling to bring it back. */
  React.useEffect(() => () => setGuideHidden(false), [setGuideHidden]);

  const inner = scroll ? (
    <ScrollView
      /* Deep enough that the floating guide button never sits on top of the
         last card. A control that covers the content it sits beside is worse
         than one that is slightly further away. */
      contentContainerStyle={[{ paddingBottom: 132 }, contentStyle]}
      keyboardShouldPersistTaps="handled"
      showsVerticalScrollIndicator={false}
      refreshControl={refreshControl}
      scrollEventThrottle={16}
      onScroll={(event) => {
        const offset = event.nativeEvent.contentOffset.y;
        const delta = offset - lastOffset.current;

        /* A threshold, because without one the button flickers on the small
           offsets a finger produces while holding still. */
        if (Math.abs(delta) < 8) return;

        lastOffset.current = offset;
        setGuideHidden(delta > 0 && offset > 96);
      }}
    >
      {children}
    </ScrollView>
  ) : (
    <View style={[{ flex: 1 }, contentStyle]}>{children}</View>
  );

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: background }} edges={edges}>
      {inner}
    </SafeAreaView>
  );
}

/** Horizontal page gutter, used everywhere so edges line up across screens. */
export function Gutter({ children, style }: { children: React.ReactNode; style?: StyleProp<ViewStyle> }) {
  return <View style={[{ paddingHorizontal: space.lg }, style]}>{children}</View>;
}

export function Row({
  children,
  gap = space.xs,
  wrap,
  align = 'center',
  justify,
  style,
}: {
  children: React.ReactNode;
  gap?: number;
  wrap?: boolean;
  align?: ViewStyle['alignItems'];
  justify?: ViewStyle['justifyContent'];
  style?: StyleProp<ViewStyle>;
}) {
  return (
    <View
      style={[
        { flexDirection: 'row', alignItems: align, justifyContent: justify, gap, flexWrap: wrap ? 'wrap' : 'nowrap' },
        style,
      ]}
    >
      {children}
    </View>
  );
}

export function Stack({
  children,
  gap = space.sm,
  style,
}: {
  children: React.ReactNode;
  gap?: number;
  style?: StyleProp<ViewStyle>;
}) {
  return <View style={[{ gap }, style]}>{children}</View>;
}

/* ── Text ──────────────────────────────────────────────────────────────── */

export function T({
  variant = 'body',
  color,
  style,
  children,
  numberOfLines,
  align,
}: {
  variant?: TypeVariant;
  color?: string;
  style?: StyleProp<TextStyle>;
  children: React.ReactNode;
  numberOfLines?: number;
  align?: TextStyle['textAlign'];
}) {
  const colors = useTheme();

  return (
    <RNText
      numberOfLines={numberOfLines}
      style={[typeScale[variant], { color: color ?? colors.text.primary, textAlign: align }, style]}
    >
      {children}
    </RNText>
  );
}

/* ── Surfaces ──────────────────────────────────────────────────────────── */

/**
 * A card surface. Deliberately not pressable.
 *
 * It used to take an `onPress` and wrap itself in a button, which reads well
 * at the call site and quietly breaks the moment the card gains a control of
 * its own — a save heart, an overflow menu. A button cannot contain a button:
 * the browser rejects the markup, and the reason it does is that a keyboard or
 * screen-reader user has no way to reach the inner control. That is a defect
 * you cannot see in a screenshot, and it had already shipped once.
 *
 * So the surface and the action are separate. Put a `CardPress` inside for the
 * tappable region, and anything that is its own control goes beside it.
 */
export function Card({
  children,
  style,
  tone = 'elevated',
  level = 'card',
  bordered = true,
}: {
  children: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  tone?: 'elevated' | 'warm' | 'sunken' | 'flat';
  level?: 'card' | 'feature' | 'none';
  bordered?: boolean;
}) {
  const colors = useTheme();

  const background = {
    elevated: colors.background.elevated,
    warm: colors.background.warm,
    sunken: colors.background.sunken,
    flat: 'transparent',
  }[tone];

  const base: ViewStyle = {
    backgroundColor: background,
    borderRadius: level === 'feature' ? radius.feature : radius.card,
    borderWidth: bordered ? StyleSheet.hairlineWidth : 0,
    borderColor: colors.border.subtle,
    overflow: 'hidden',
    ...(level === 'none' ? {} : level === 'feature' ? elevation.feature : elevation.card),
  };

  return <View style={[base, style]}>{children}</View>;
}

/**
 * The tappable region of a card.
 *
 * Sits inside a `Card` and covers whatever opening the card should mean. Any
 * control with its own meaning — save, dismiss, a menu — belongs outside this,
 * as a sibling, so that both are reachable.
 */
export function CardPress({
  children,
  onPress,
  accessibilityLabel,
  disabled,
  style,
}: {
  children: React.ReactNode;
  onPress: () => void;
  accessibilityLabel?: string;
  disabled?: boolean;
  style?: StyleProp<ViewStyle>;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={() => {
        Haptics.selectionAsync().catch(() => {});
        onPress();
      }}
      style={({ pressed }) => [(pressed || disabled) && { opacity: disabled ? 0.6 : 0.92 }, style]}
    >
      {children}
    </Pressable>
  );
}

/* ── Buttons ───────────────────────────────────────────────────────────── */

type ButtonTone = 'primary' | 'secondary' | 'tertiary' | 'inverse' | 'onAccent' | 'onAccentGhost' | 'destructive';
type ButtonSize = 'small' | 'medium' | 'large';

const SIZES: Record<ButtonSize, { paddingVertical: number; paddingHorizontal: number; variant: TypeVariant }> = {
  small: { paddingVertical: 9, paddingHorizontal: space.md, variant: 'smallStrong' },
  medium: { paddingVertical: 13, paddingHorizontal: space.lg, variant: 'bodyStrong' },
  large: { paddingVertical: 16, paddingHorizontal: space.xl, variant: 'bodyStrong' },
};

export function Button({
  label,
  onPress,
  tone = 'primary',
  size = 'medium',
  disabled,
  loading,
  style,
  icon,
  haptic = 'light',
}: {
  label: string;
  onPress?: () => void;
  tone?: ButtonTone;
  size?: ButtonSize;
  disabled?: boolean;
  loading?: boolean;
  style?: StyleProp<ViewStyle>;
  icon?: React.ReactNode;
  haptic?: 'light' | 'medium' | 'none';
}) {
  const colors = useTheme();
  const dimensions = SIZES[size];

  const tones: Record<ButtonTone, { bg: string; fg: string; border: string }> = {
    primary: { bg: colors.action.primary, fg: colors.text.onAccent, border: 'transparent' },
    secondary: { bg: colors.background.elevated, fg: colors.text.primary, border: colors.border.strong },
    tertiary: { bg: 'transparent', fg: colors.action.primary, border: 'transparent' },
    inverse: { bg: colors.text.primary, fg: colors.text.inverse, border: 'transparent' },
    /*
     * For the brand gradient, whose colour does not follow the theme. Screens
     * used to reach this look by passing tone="secondary" and overriding the
     * background in `style` — which left the foreground on the theme token and
     * produced white-on-white in dark mode. Making it a tone means the pair
     * always travels together.
     */
    onAccent: { bg: '#FFFFFF', fg: '#0D1B2A', border: 'transparent' },
    onAccentGhost: { bg: 'transparent', fg: '#FFFFFF', border: 'rgba(255,255,255,0.45)' },
    /*
     * Irreversible actions. A tone rather than a flag, so the label colour can
     * never be set independently of the background — the mistake that produced
     * the white-on-white above.
     */
    destructive: { bg: colors.status.error, fg: '#FFFFFF', border: 'transparent' },
  };
  const t = tones[tone];

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: disabled || loading, busy: loading }}
      onPress={() => {
        if (haptic !== 'none') {
          const style = haptic === 'medium' ? Haptics.ImpactFeedbackStyle.Medium : Haptics.ImpactFeedbackStyle.Light;
          Haptics.impactAsync(style).catch(() => {});
        }
        onPress?.();
      }}
      disabled={disabled || loading}
      style={({ pressed }) => [
        {
          backgroundColor: pressed && tone === 'primary' ? colors.action.primaryPressed : t.bg,
          borderColor: t.border,
          borderWidth: tone === 'secondary' || tone === 'onAccentGhost' ? StyleSheet.hairlineWidth : 0,
          borderRadius: radius.pill,
          paddingVertical: dimensions.paddingVertical,
          paddingHorizontal: dimensions.paddingHorizontal,
          minHeight: 44,
          flexDirection: 'row',
          alignItems: 'center',
          justifyContent: 'center',
          gap: space.xs,
          opacity: disabled ? 0.4 : pressed ? 0.94 : 1,
        },
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={t.fg} size="small" />
      ) : (
        <>
          {icon}
          <RNText style={[typeScale[dimensions.variant], { color: t.fg }]}>{label}</RNText>
        </>
      )}
    </Pressable>
  );
}

/* ── Chips ─────────────────────────────────────────────────────────────── */

export function Chip({
  label,
  selected,
  onPress,
  tone = 'neutral',
  size = 'medium',
  icon,
}: {
  label: string;
  selected?: boolean;
  onPress?: () => void;
  tone?: 'neutral' | 'accent';
  size?: 'small' | 'medium';
  icon?: React.ReactNode;
}) {
  const colors = useTheme();
  const activeBg = tone === 'accent' ? colors.action.primary : colors.text.primary;

  const body = (
    <Row gap={6}>
      {icon}
      <RNText
        style={[
          typeScale[size === 'small' ? 'caption' : 'smallStrong'],
          /* A neutral chip fills with text.primary, which is near-white in dark
             mode — so its label has to be the inverse, not onAccent. Getting
             this wrong rendered white on white. */
          { color: selected ? (tone === 'accent' ? colors.text.onAccent : colors.text.inverse) : colors.text.secondary },
        ]}
      >
        {label}
      </RNText>
    </Row>
  );

  const styleFor = (pressed: boolean): ViewStyle => ({
    paddingVertical: size === 'small' ? 7 : 10,
    paddingHorizontal: size === 'small' ? space.sm : space.md,
    borderRadius: radius.pill,
    backgroundColor: selected ? activeBg : colors.background.elevated,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: selected ? activeBg : colors.border.subtle,
    opacity: pressed ? 0.85 : 1,
    minHeight: size === 'small' ? 34 : 40,
    justifyContent: 'center',
  });

  if (!onPress) return <View style={styleFor(false)}>{body}</View>;

  /* A 34px pill looks right but misses the 44px target, so the difference is
     made up in hitSlop rather than by inflating the design. */
  const slop = size === 'small' ? 6 : 3;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected: !!selected }}
      hitSlop={{ top: slop, bottom: slop, left: 4, right: 4 }}
      onPress={() => {
        Haptics.selectionAsync().catch(() => {});
        onPress();
      }}
      style={({ pressed }) => styleFor(pressed)}
    >
      {body}
    </Pressable>
  );
}

/* ── Section header ────────────────────────────────────────────────────── */

export function SectionHeader({
  title,
  caption,
  action,
  onAction,
  style,
}: {
  title: string;
  caption?: string;
  action?: string;
  onAction?: () => void;
  style?: StyleProp<ViewStyle>;
}) {
  const colors = useTheme();

  return (
    <Row justify="space-between" align="flex-end" style={[{ marginTop: space.xxl, marginBottom: space.sm }, style]}>
      <View style={{ flex: 1, gap: 2 }}>
        <T variant="h3">{title}</T>
        {caption ? (
          <T variant="small" color={colors.text.secondary}>
            {caption}
          </T>
        ) : null}
      </View>
      {action ? (
        <Pressable onPress={onAction} hitSlop={10} accessibilityRole="button">
          <T variant="smallStrong" color={colors.action.primary}>
            {action}
          </T>
        </Pressable>
      ) : null}
    </Row>
  );
}

/* ── Feedback ──────────────────────────────────────────────────────────── */

export function Note({
  children,
  tone = 'neutral',
  icon,
}: {
  children: React.ReactNode;
  tone?: 'neutral' | 'warning' | 'success' | 'info';
  icon?: React.ReactNode;
}) {
  const colors = useTheme();

  const tones = {
    neutral: { bg: colors.background.sunken, fg: colors.text.secondary },
    warning: { bg: colors.statusSoft.warning, fg: colors.status.warning },
    success: { bg: colors.statusSoft.open, fg: colors.status.open },
    info: { bg: colors.action.soft, fg: colors.action.onSoft },
  } as const;

  return (
    <Row
      align="flex-start"
      gap={space.xs}
      style={{ backgroundColor: tones[tone].bg, borderRadius: radius.control, padding: space.sm }}
    >
      {icon}
      <RNText style={[typeScale.small, { color: tones[tone].fg, flex: 1 }]}>{children}</RNText>
    </Row>
  );
}

export function Loading({ label }: { label?: string }) {
  const colors = useTheme();

  return (
    <View style={{ paddingVertical: space.xxxl, alignItems: 'center', gap: space.sm }}>
      <ActivityIndicator color={colors.action.primary} />
      {label ? (
        <T variant="small" color={colors.text.secondary}>
          {label}
        </T>
      ) : null}
    </View>
  );
}

/** Skeleton block for loading states (Figma: 51). */
export function Skeleton({ height = 16, width, style }: { height?: number; width?: number | string; style?: StyleProp<ViewStyle> }) {
  const colors = useTheme();

  return (
    <View
      style={[
        {
          height,
          width: (width as number) ?? '100%',
          borderRadius: radius.compact,
          backgroundColor: colors.background.sunken,
        },
        style,
      ]}
    />
  );
}

/**
 * A request that did not arrive.
 *
 * Several screens guarded with `if (isLoading || !data) return <Loading />`,
 * which is a spinner that never stops: when a request fails, isLoading goes
 * false and data stays undefined, so the condition still holds. A traveller on
 * a poor connection, or one whose request was rate limited, watched it turn
 * forever with no error, no retry and — before the back controls were fixed —
 * no way off the screen either.
 *
 * "Loading" and "did not load" are different states and have to look
 * different. This is the second, and it always offers the way out.
 */
export function LoadFailure({
  title = 'That did not load',
  body = 'The connection dropped or the server was busy. Nothing is lost — try again.',
  onRetry,
}: {
  title?: string;
  body?: string;
  onRetry?: () => void;
}) {
  return (
    <EmptyState
      title={title}
      body={body}
      action={onRetry ? <Button label="Try again" tone="secondary" onPress={onRetry} /> : undefined}
    />
  );
}

export function EmptyState({
  title,
  body,
  action,
}: {
  title: string;
  body: string;
  action?: React.ReactNode;
}) {
  const colors = useTheme();

  return (
    <View style={{ paddingVertical: space.xxxl, gap: space.xs }}>
      <T variant="h3">{title}</T>
      <T variant="body" color={colors.text.secondary}>
        {body}
      </T>
      {action ? <View style={{ marginTop: space.sm, alignSelf: 'flex-start' }}>{action}</View> : null}
    </View>
  );
}

/** Thin divider used inside cards rather than between them. */
export function Divider({ style }: { style?: StyleProp<ViewStyle> }) {
  const colors = useTheme();

  return <View style={[{ height: StyleSheet.hairlineWidth, backgroundColor: colors.border.subtle }, style]} />;
}

export type { ColorTokens };
