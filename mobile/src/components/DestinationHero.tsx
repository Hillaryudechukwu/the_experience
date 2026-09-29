import React from 'react';
import { Image, Pressable, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { ImageAttribution } from '../api/types';
import { Icon } from './Icon';
import { T } from './primitives';
import { radius, space, TOUCH_TARGET, useTheme } from '../theme';

/**
 * The city header (Figma: 3, 12).
 *
 * The design puts a photograph of where you actually are behind the greeting,
 * and everything that sits on it — the city switch, the weather, the time you
 * have left — is written in white over a scrim rather than in a card. That is
 * the whole point of the frame: the first thing on screen should be the place,
 * not the chrome.
 *
 * Two details this has to get right. The scrim is a three-stop gradient rather
 * than a flat tint, because a flat one either washes the photograph out at the
 * top or leaves the text illegible at the bottom. And the sheet below overlaps
 * the image, which is what makes the header read as a layer of the page rather
 * than a banner pasted above it.
 */
export function DestinationHero({
  city,
  imageUri,
  attribution,
  greeting,
  headline,
  detail,
  timeLabel,
  dateLabel,
  temperature,
  condition,
  onPressCity,
  onPressProfile,
  onPressSearch,
  height = 320,
}: {
  city: string;
  imageUri?: string | null;
  attribution?: ImageAttribution;
  greeting: string;
  headline?: string | null;
  detail?: string | null;
  timeLabel?: string | null;
  dateLabel?: string | null;
  temperature?: number | null;
  condition?: string | null;
  onPressCity?: () => void;
  onPressProfile?: () => void;
  onPressSearch?: () => void;
  height?: number;
}) {
  const colors = useTheme();
  const insets = useSafeAreaInsets();
  const total = height + insets.top;
  const credit = imageUri
    ? [attribution?.creator, attribution?.licence].filter(Boolean).join(' · ') || null
    : null;

  return (
    <View style={{ height: total, width: '100%', backgroundColor: colors.brand.midnight }}>
      {imageUri ? (
        <Image
          source={{ uri: imageUri }}
          style={{ position: 'absolute', inset: 0 as never, width: '100%', height: total }}
          resizeMode="cover"
          accessibilityIgnoresInvertColors
        />
      ) : null}

      {/* Two scrims: one from the top so the status bar and controls stay
          legible over a bright sky, one from the bottom for the greeting. */}
      <LinearGradient
        colors={['rgba(13,27,42,0.62)', 'rgba(13,27,42,0.10)', 'rgba(13,27,42,0.20)', 'rgba(13,27,42,0.88)']}
        locations={[0, 0.3, 0.55, 1]}
        style={{ position: 'absolute', inset: 0 as never }}
        pointerEvents="none"
      />

      <View style={{ flex: 1, paddingTop: insets.top + space.sm, paddingHorizontal: space.lg, paddingBottom: space.lg }}>
        {/* ── Wordmark, city switch, profile ───────────────────────────── */}
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.sm }}>
          <View style={{ flex: 1 }}>
            <T variant="caption" color="rgba(255,255,255,0.72)" style={{ letterSpacing: 0.6 }}>
              Interlude
            </T>
            <Pressable
              onPress={onPressCity}
              accessibilityRole="button"
              accessibilityLabel={`Change city, currently ${city}`}
              hitSlop={8}
              style={({ pressed }) => ({
                flexDirection: 'row',
                alignItems: 'center',
                gap: 4,
                alignSelf: 'flex-start',
                marginTop: 1,
                opacity: pressed ? 0.8 : 1,
              })}
            >
              <T variant="h2" color="#FFFFFF">
                {city}
              </T>
              <View style={{ transform: [{ rotate: '90deg' }], marginTop: 3 }}>
                <Icon name="chevron" size={16} color="rgba(255,255,255,0.85)" strokeWidth={2} />
              </View>
            </Pressable>
          </View>

          <Pressable
            onPress={onPressProfile}
            accessibilityRole="button"
            accessibilityLabel="Your profile"
            hitSlop={8}
            style={({ pressed }) => ({
              width: TOUCH_TARGET,
              height: TOUCH_TARGET,
              borderRadius: TOUCH_TARGET / 2,
              backgroundColor: 'rgba(255,255,255,0.18)',
              borderWidth: 1,
              borderColor: 'rgba(255,255,255,0.4)',
              alignItems: 'center',
              justifyContent: 'center',
              opacity: pressed ? 0.85 : 1,
            })}
          >
            <Icon name="person" size={20} color="#FFFFFF" strokeWidth={1.8} />
          </Pressable>
        </View>

        {/* ── Weather and local time ───────────────────────────────────── */}
        {temperature != null || timeLabel ? (
          <View style={{ flexDirection: 'row', alignItems: 'flex-start', marginTop: space.md }}>
            {temperature != null ? (
              <View style={{ flex: 1, flexDirection: 'row', alignItems: 'center', gap: 7 }}>
                <Icon name="weather" size={20} color="rgba(255,255,255,0.9)" strokeWidth={1.7} />
                <View>
                  <T variant="bodyStrong" color="#FFFFFF">
                    {Math.round(temperature)}°C
                  </T>
                  {condition ? (
                    <T variant="caption" color="rgba(255,255,255,0.78)">
                      {condition}
                    </T>
                  ) : null}
                </View>
              </View>
            ) : (
              <View style={{ flex: 1 }} />
            )}

            {timeLabel ? (
              <View style={{ alignItems: 'flex-end' }}>
                {dateLabel ? (
                  <T variant="caption" color="rgba(255,255,255,0.78)">
                    {dateLabel}
                  </T>
                ) : null}
                <T variant="bodyStrong" color="#FFFFFF">
                  {timeLabel}
                </T>
              </View>
            ) : null}
          </View>
        ) : null}

        <View style={{ flex: 1 }} />

        {/* Most of this photography is Creative Commons, where naming the
            photographer is a condition of publishing it, not a courtesy. It is
            set small and right-aligned above the greeting — the one strip of
            the frame nothing else occupies. */}
        {credit ? (
          <T
            variant="caption"
            color="rgba(255,255,255,0.62)"
            align="right"
            numberOfLines={1}
            style={{ fontSize: 10, marginBottom: 6 }}
          >
            {credit}
          </T>
        ) : null}

        {/* ── The greeting, and what the time you have is good for ─────── */}
        <T variant="displayL" color="#FFFFFF">
          {greeting}
        </T>
        {headline ? (
          <T variant="body" color="rgba(255,255,255,0.92)" style={{ marginTop: 6 }}>
            {headline}
            {detail ? ` ${detail}` : ''}
          </T>
        ) : null}

        {onPressSearch ? (
          <Pressable
            onPress={onPressSearch}
            accessibilityRole="search"
            accessibilityLabel="Search places, areas or experiences"
            style={({ pressed }) => ({
              marginTop: space.md,
              flexDirection: 'row',
              alignItems: 'center',
              gap: space.xs,
              backgroundColor: 'rgba(255,255,255,0.94)',
              borderRadius: radius.pill,
              paddingHorizontal: space.md,
              minHeight: TOUCH_TARGET,
              opacity: pressed ? 0.9 : 1,
            })}
          >
            <Icon name="search" size={18} color={colors.text.tertiary} />
            <T variant="body" color={colors.text.tertiary}>
              Search places, areas or experiences
            </T>
          </Pressable>
        ) : null}
      </View>
    </View>
  );
}
