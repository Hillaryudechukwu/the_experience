import React, { useState } from 'react';
import { ActivityIndicator, TextInput, View } from 'react-native';
import * as Location from 'expo-location';
import { useRouter } from 'expo-router';

import { useDestinations } from '../../src/api/hooks';
import { useDebounced } from '../../src/lib/useDebounced';
import { Icon } from '../../src/components/Icon';
import { OnboardingChrome, OnboardingIntro } from '../../src/components/OnboardingChrome';
import { Button, Card, CardPress, Chip, Gutter, Note, Row, Screen, T } from '../../src/components/primitives';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

/**
 * Destination (Figma: 14.2).
 *
 * Location is offered, never required — the copy says so, and declining leads
 * to exactly the same recommendations via the city list. A permission prompt
 * that blocks the product is a permission prompt people deny.
 */
export default function Destination() {
  const colors = useTheme();
  const router = useRouter();
  const [query, setQuery] = useState('');
  const [locating, setLocating] = useState(false);
  const [locationNote, setLocationNote] = useState<string | null>(null);

  /* Debounced for the same reason as the search screen: this list already
     updates as you type, but without a pause it issues a request per
     keystroke, and "London" is six. */
  const { data, isLoading } = useDestinations(useDebounced(query.trim()) || undefined);
  const destinations = data?.covered ?? [];

  /* Places that exist but that we do not cover. The API only looks these up
     when nothing in the catalogue matched, so this is empty almost always. */
  const elsewhere = data?.elsewhere ?? [];

  /* The whole catalogue, for the empty state: "not Paris" is only useful
     alongside what we can actually help with. */
  const { data: all } = useDestinations();
  const covered = all?.covered ?? [];
  const { setDestination, setCoords, setLocationPrecision } = useSession();

  const useMyLocation = async () => {
    setLocating(true);
    setLocationNote(null);

    try {
      const { status } = await Location.requestForegroundPermissionsAsync();

      if (status !== 'granted') {
        setLocationNote('No problem — pick a city below and everything works the same.');
        await setLocationPrecision('off');

        return;
      }

      const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      await setCoords({ lat: position.coords.latitude, lng: position.coords.longitude }, 'precise');
      setLocationNote('Got it. Now choose the city you are in or heading to.');
    } catch {
      setLocationNote('Could not read your location. Choose a city below instead.');
    } finally {
      setLocating(false);
    }
  };

  const choose = async (destination: { id: string; slug: string; name: string; timezone: string }) => {
    await setDestination(destination);
    router.push('/onboarding/purpose');
  };

  return (
    <Screen>
      <OnboardingChrome step={1} total={6} />
      <OnboardingIntro
        title="Where are you going?"
        body="Tell us the city and we will work out what is genuinely worth your time there."
      />

      <Gutter style={{ marginTop: space.lg }}>
        <Button
          label={locating ? 'Locating…' : "I'm already here"}
          tone="secondary"
          onPress={useMyLocation}
          disabled={locating}
          icon={<Icon name="location" size={17} color={colors.text.primary} />}
        />

        {locationNote ? (
          <View style={{ marginTop: space.sm }}>
            <Note>{locationNote}</Note>
          </View>
        ) : null}

        <Row
          gap={space.xs}
          style={{
            marginTop: space.lg,
            backgroundColor: colors.background.elevated,
            borderColor: colors.border.subtle,
            borderWidth: 1,
            borderRadius: radius.pill,
            paddingHorizontal: space.md,
          }}
        >
          <Icon name="search" size={18} color={colors.text.tertiary} />
          <TextInput
            placeholder="Search cities"
            placeholderTextColor={colors.text.tertiary}
            value={query}
            onChangeText={setQuery}
            autoCorrect={false}
            style={{
              flex: 1,
              paddingVertical: 14,
              color: colors.text.primary,
              fontFamily: 'Inter_400Regular',
              fontSize: 16,
            }}
          />
        </Row>
      </Gutter>

      <Gutter style={{ marginTop: space.md, gap: space.xs }}>
        {isLoading ? <ActivityIndicator color={colors.action.primary} /> : null}

        {destinations.map((destination) => (
          <Card key={destination.id}>
            <CardPress onPress={() => choose(destination)} accessibilityLabel={`Choose ${destination.name}`}>
              <Row justify="space-between" align="flex-start" style={{ padding: space.md }} gap={space.sm}>
                <View style={{ flex: 1, gap: 4 }}>
                  <T variant="h3">{destination.name}</T>
                  {destination.summary ? (
                    <T variant="small" color={colors.text.secondary} numberOfLines={2}>
                      {destination.summary}
                    </T>
                  ) : null}
                </View>
                <View style={{ alignItems: 'flex-end', gap: 4 }}>
                  <T variant="caption" color={colors.text.tertiary}>
                    {destination.country}
                  </T>
                  <Icon name="chevron" size={16} color={colors.text.tertiary} />
                </View>
              </Row>
            </CardPress>
          </Card>
        ))}

        {/*
          * A dead end, turned into a way forward.
          *
          * This used to be one sentence naming the four cities in prose, which
          * would have been a lie the day a fifth was added, and which left a
          * traveller whose city is not covered with nothing to do but retype.
          * The list below is the catalogue itself, and every entry is
          * tappable, so the answer to "we are not in Paris yet" is the set of
          * places we can actually be useful in.
          */}
        {!isLoading && destinations.length === 0 ? (
          <View style={{ gap: space.sm }}>
            {/* Name what they typed back to them. "We found Paris, we just do
                not cover it" answers a different question from "no results",
                and it is the question they were actually asking. */}
            {elsewhere.length > 0 ? (
              <View style={{ gap: space.xs }}>
                <T variant="label" color={colors.text.tertiary}>
                  Found, but not covered yet
                </T>
                {elsewhere.map((place) => (
                  <Row key={place.display_name} gap={space.xs} align="flex-start">
                    <Icon name="location" size={15} color={colors.text.tertiary} />
                    <T variant="small" color={colors.text.secondary} style={{ flex: 1 }}>
                      {`${place.name}, ${place.country}`}
                    </T>
                  </Row>
                ))}
              </View>
            ) : null}

            <Note>
              {elsewhere.length > 0
                ? 'We only cover a city once we have researched it properly — opening hours, walking times, what is actually worth your time. Somewhere we had merely geocoded would give you worse answers than a map.'
                : `We could not find ${query.trim()} at all. Check the spelling, or pick one of these.`}
            </Note>

            {covered.length > 0 ? (
              <>
                <T variant="label" color={colors.text.tertiary} style={{ marginTop: space.xs }}>
                  Where we can help today
                </T>
                <Row gap={space.xs} wrap>
                  {covered.map((destination) => (
                    <Chip
                      key={destination.id}
                      label={`${destination.name}`}
                      onPress={() => choose(destination)}
                    />
                  ))}
                </Row>
              </>
            ) : null}
          </View>
        ) : null}
      </Gutter>
    </Screen>
  );
}
