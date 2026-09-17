import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
  type PressableProps,
  type StyleProp,
  type TextStyle,
  type ViewStyle,
} from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { SafeAreaView } from 'react-native-safe-area-context';

import { artworkFor, radius, scoreBand, shadow, space, type as typography, useTheme } from '../theme';

export function Screen({
  children,
  scroll = true,
  contentStyle,
  refreshControl,
}: {
  children: React.ReactNode;
  scroll?: boolean;
  contentStyle?: StyleProp<ViewStyle>;
  refreshControl?: React.ReactElement<import('react-native').RefreshControlProps>;
}) {
  const colors = useTheme();

  const inner = scroll ? (
    <ScrollView
      contentContainerStyle={[{ padding: space.lg, paddingBottom: space.xxl * 2 }, contentStyle]}
      keyboardShouldPersistTaps="handled"
      showsVerticalScrollIndicator={false}
      refreshControl={refreshControl}
    >
      {children}
    </ScrollView>
  ) : (
    <View style={[{ flex: 1 }, contentStyle]}>{children}</View>
  );

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.bg }} edges={['top', 'left', 'right']}>
      {inner}
    </SafeAreaView>
  );
}

type TextVariant = keyof typeof typography;

export function T({
  variant = 'body',
  color,
  style,
  children,
  numberOfLines,
}: {
  variant?: TextVariant;
  color?: string;
  style?: StyleProp<TextStyle>;
  children: React.ReactNode;
  numberOfLines?: number;
}) {
  const colors = useTheme();

  return (
    <Text
      numberOfLines={numberOfLines}
      style={[typography[variant], { color: color ?? colors.ink }, style]}
    >
      {children}
    </Text>
  );
}

export function Card({
  children,
  style,
  onPress,
}: {
  children: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  onPress?: () => void;
}) {
  const colors = useTheme();
  const base: ViewStyle = {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.line,
    overflow: 'hidden',
    ...shadow(colors),
  };

  if (!onPress) return <View style={[base, style]}>{children}</View>;

  return (
    <Pressable style={({ pressed }) => [base, style, pressed && { opacity: 0.85 }]} onPress={onPress}>
      {children}
    </Pressable>
  );
}

export function Button({
  label,
  onPress,
  tone = 'primary',
  disabled,
  loading,
  style,
}: {
  label: string;
  onPress?: () => void;
  tone?: 'primary' | 'secondary' | 'ghost';
  disabled?: boolean;
  loading?: boolean;
  style?: StyleProp<ViewStyle>;
}) {
  const colors = useTheme();

  const tones: Record<string, { bg: string; fg: string; border: string }> = {
    primary: { bg: colors.accent, fg: '#FFFFFF', border: colors.accent },
    secondary: { bg: colors.surface, fg: colors.ink, border: colors.line },
    ghost: { bg: 'transparent', fg: colors.accent, border: 'transparent' },
  };
  const t = tones[tone];

  return (
    <Pressable
      onPress={onPress}
      disabled={disabled || loading}
      style={({ pressed }) => [
        {
          backgroundColor: t.bg,
          borderColor: t.border,
          borderWidth: StyleSheet.hairlineWidth,
          borderRadius: radius.pill,
          paddingVertical: 14,
          paddingHorizontal: space.xl,
          alignItems: 'center',
          justifyContent: 'center',
          opacity: disabled ? 0.45 : pressed ? 0.85 : 1,
        },
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={t.fg} />
      ) : (
        <Text style={[typography.bodyStrong, { color: t.fg }]}>{label}</Text>
      )}
    </Pressable>
  );
}

export function Chip({
  label,
  selected,
  onPress,
  tone,
}: {
  label: string;
  selected?: boolean;
  onPress?: () => void;
  tone?: 'accent' | 'neutral';
}) {
  const colors = useTheme();
  const activeBg = tone === 'accent' ? colors.accent : colors.ink;

  const Wrapper: React.ComponentType<PressableProps> = onPress ? Pressable : (View as never);

  return (
    <Wrapper
      onPress={onPress}
      style={({ pressed }: { pressed?: boolean }) => ({
        paddingVertical: 8,
        paddingHorizontal: 14,
        borderRadius: radius.pill,
        backgroundColor: selected ? activeBg : colors.surface,
        borderWidth: StyleSheet.hairlineWidth,
        borderColor: selected ? activeBg : colors.line,
        opacity: pressed ? 0.8 : 1,
      })}
    >
      <Text
        style={[
          typography.small,
          { color: selected ? colors.surface : colors.inkMuted, fontWeight: '600' },
        ]}
      >
        {label}
      </Text>
    </Wrapper>
  );
}

export function Row({
  children,
  gap = space.sm,
  wrap,
  style,
}: {
  children: React.ReactNode;
  gap?: number;
  wrap?: boolean;
  style?: StyleProp<ViewStyle>;
}) {
  return (
    <View
      style={[
        { flexDirection: 'row', alignItems: 'center', gap, flexWrap: wrap ? 'wrap' : 'nowrap' },
        style,
      ]}
    >
      {children}
    </View>
  );
}

export function SectionHeader({ title, action, onAction }: { title: string; action?: string; onAction?: () => void }) {
  const colors = useTheme();

  return (
    <Row style={{ justifyContent: 'space-between', marginTop: space.xl, marginBottom: space.md }}>
      <Text style={[typography.label, { color: colors.inkFaint }]}>{title}</Text>
      {action && (
        <Pressable onPress={onAction} hitSlop={8}>
          <Text style={[typography.small, { color: colors.accent, fontWeight: '600' }]}>{action}</Text>
        </Pressable>
      )}
    </Row>
  );
}

export function ScoreBadge({ score, size = 'md' }: { score: number; size?: 'sm' | 'md' }) {
  const colors = useTheme();
  const band = scoreBand(score, colors);
  const dimension = size === 'sm' ? 38 : 46;

  return (
    <View
      style={{
        width: dimension,
        height: dimension,
        borderRadius: dimension / 2,
        backgroundColor: band.bg,
        alignItems: 'center',
        justifyContent: 'center',
      }}
    >
      <Text style={{ color: band.fg, fontWeight: '800', fontSize: size === 'sm' ? 14 : 17 }}>{score}</Text>
    </View>
  );
}

/** Stand-in artwork: a stable wash per experience, never a stock photo of somewhere else. */
export function Artwork({
  id,
  category,
  height = 120,
  label,
}: {
  id: string;
  category?: string;
  height?: number;
  label?: string;
}) {
  const [from, to] = artworkFor(id, category);

  return (
    <LinearGradient colors={[from, to]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={{ height, justifyContent: 'flex-end' }}>
      {label ? (
        <Text
          style={[
            typography.label,
            { color: 'rgba(255,255,255,0.86)', padding: space.md },
          ]}
        >
          {label}
        </Text>
      ) : null}
    </LinearGradient>
  );
}

export function Loading({ label }: { label?: string }) {
  const colors = useTheme();

  return (
    <View style={{ paddingVertical: space.xxl, alignItems: 'center', gap: space.sm }}>
      <ActivityIndicator color={colors.accent} />
      {label ? <T variant="small" color={colors.inkMuted}>{label}</T> : null}
    </View>
  );
}

export function Note({ children, tone = 'neutral' }: { children: React.ReactNode; tone?: 'neutral' | 'warn' | 'good' }) {
  const colors = useTheme();
  const tones = {
    neutral: { bg: colors.surfaceAlt, fg: colors.inkMuted },
    warn: { bg: colors.warnSoft, fg: colors.warn },
    good: { bg: colors.positiveSoft, fg: colors.positive },
  } as const;

  return (
    <View style={{ backgroundColor: tones[tone].bg, borderRadius: radius.md, padding: space.md }}>
      <Text style={[typography.small, { color: tones[tone].fg }]}>{children}</Text>
    </View>
  );
}

export function EmptyState({ title, body, action }: { title: string; body: string; action?: React.ReactNode }) {
  const colors = useTheme();

  return (
    <View style={{ paddingVertical: space.xxl, gap: space.sm, alignItems: 'flex-start' }}>
      <T variant="heading">{title}</T>
      <T variant="body" color={colors.inkMuted}>{body}</T>
      {action}
    </View>
  );
}
