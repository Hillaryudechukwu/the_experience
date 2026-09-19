import React, { useState } from 'react';
import { ActivityIndicator, TextInput, View } from 'react-native';
import * as Location from 'expo-location';
import { useRouter } from 'expo-router';

import { useDestinations } from '../../src/api/hooks';
import { Icon } from '../../src/components/Icon';
import { OnboardingChrome, OnboardingIntro } from '../../src/components/OnboardingChrome';
import { Button, Card, Gutter, Note, Row, Screen, T } from '../../src/components/primitives';
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

  const { data: destinations = [], isLoading } = useDestinations(query.trim() || undefined);
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
          <Card key={destination.id} onPress={() => choose(destination)}>
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
          </Card>
        ))}

        {!isLoading && destinations.length === 0 ? (
          <Note>No cities match that yet. The catalogue currently covers London, Rome, New York and Tokyo.</Note>
        ) : null}
      </Gutter>
    </Screen>
  );
}
