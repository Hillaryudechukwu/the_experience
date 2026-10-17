import React, { useState } from 'react';
import { Image, Pressable, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import type { ImageAttribution } from '../api/types';
import { BRAND } from '../brand';
import { Icon } from './Icon';
import { Artwork } from './Photo';
import { remoteImage } from '../lib/images';
import { WayoaLogo } from './WayoaLogo';
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
  clothing,
  onPressCity,
  onPressProfile,
  onPressSearch,
  height = 320,
  overlapBelow = 0,
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
  clothing?: string | null;
  onPressCity?: () => void;
  onPressProfile?: () => void;
  onPressSearch?: () => void;
  height?: number;
  /**
   * How far the next element rides up over this one.
   *
   * The card below overlaps the header on purpose — that is what makes the
   * header read as the top of a page rather than a banner above one. But it
   * has to overlap the photograph, not the search field sitting at the foot of
   * it, so the header reserves the same distance at its own bottom. Left at 0
   * the two collide, which is exactly what happened.
   */
  overlapBelow?: number;
}) {
  const colors = useTheme();
  const insets = useSafeAreaInsets();
  /* Every other remote image in the app falls back when it fails; this one
     did not, so when Wikimedia refused the request the header simply went
     dark and nothing said why. Photo has done it this way all along. */
  const [imageFailed, setImageFailed] = useState(false);
  /*
   * The reserved strip is extra photograph, not less room for the text.
   *
   * Padding the foot alone would have taken the overlap out of the content
   * box, and the first thing to give way in a fixed-height flex column is
   * whichever child has no height of its own — here the photo credit, which
   * collapsed to two pixels and vanished while still being present in the DOM.
   * Growing the box by the same amount it is padded leaves the content exactly
   * as it was and gives the card below more picture to sit on.
   */
  const total = height + insets.top + overlapBelow;
  const credit = imageUri
    ? [attribution?.creator, attribution?.licence].filter(Boolean).join(' · ') || null
    : null;

  return (
    /*
     * A floor, not a ceiling.
     *
     * This was a fixed height, and the column inside it had no room left: with
     * a two-line headline the content came to exactly the 288pt available, so
     * the next thing added — anything at all — pushed the search field past the
     * bottom edge. Android clips an overflowing child where iOS lets it hang,
     * so the field simply disappeared there while looking merely tight on iOS.
     *
     * minHeight keeps the designed 320pt whenever the content fits and lets the
     * header grow the rare time it does not. The overlap strip is reserved as
     * padding, so the card below still rides up over photograph either way.
     */
    <View style={{ minHeight: total, width: '100%', backgroundColor: colors.brand.midnight }}>
      {imageUri && !imageFailed ? (
        <Image
          source={remoteImage(imageUri)}
          /* Pinned to all four edges, so it follows the header when the
             content makes it taller than `total`. A percentage height would be
             measured against a parent that no longer has a fixed one. */
          style={{ position: 'absolute', inset: 0 as never }}
          resizeMode="cover"
          onError={() => setImageFailed(true)}
          accessibilityIgnoresInvertColors
        />
      ) : (
        /* The wash needs a box with a height of its own to fill. Handing the
           absolute position to Artwork instead would leave its own `100%`
           measuring against a header that is min-height, not fixed. */
        <View style={{ position: 'absolute', inset: 0 as never }}>
          <Artwork id={city} height="100%" glyph={false} />
        </View>
      )}

      {/* Two scrims: one from the top so the status bar and controls stay
          legible over a bright sky, one from the bottom for the greeting. */}
      <LinearGradient
        colors={['rgba(13,27,42,0.62)', 'rgba(13,27,42,0.10)', 'rgba(13,27,42,0.20)', 'rgba(13,27,42,0.88)']}
        locations={[0, 0.3, 0.55, 1]}
        style={{ position: 'absolute', inset: 0 as never }}
        pointerEvents="none"
      />

      {/* flexGrow with an auto basis, not `flex: 1`.
          `flex: 1` is flexBasis 0, which reports no height of its own — against
          a parent that is now min-height rather than fixed, the column would
          measure as nothing and the header would collapse to 320pt regardless
          of what is inside it. An auto basis measures the content first and
          then grows into whatever room is left. */}
      <View style={{ flexGrow: 1, flexBasis: 'auto', paddingTop: insets.top + space.sm, paddingHorizontal: space.lg, paddingBottom: space.lg + overlapBelow }}>
        {/* ── Wordmark, city switch, profile ───────────────────────────── */}
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.sm }}>
          <View style={{ flex: 1 }}>
            {/* The wordmark, small, where the caption wordmark used to be.
                The square mark at `sm` is 40pt tall against the 15pt line it
                replaced, and this header lives in a box of fixed height — see
                the note on `total` below. */}
            <WayoaLogo variant="full" size="xs" accessibilityLabel={BRAND.name} />
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
                  {clothing ? (
                    <T variant="caption" color="rgba(255,255,255,0.78)">
                      {clothing}
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
            /* Never the thing that gives way. Most of this photography is
               Creative Commons, where the credit is a condition of showing the
               picture at all, so it cannot be the flexible one. */
            style={{ fontSize: 10, marginBottom: 6, flexShrink: 0 }}
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
