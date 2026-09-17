import React, { useState } from 'react';
import { ActivityIndicator, Pressable, TextInput, View } from 'react-native';
import * as Location from 'expo-location';
import { useRouter } from 'expo-router';

import { useDestinations } from '../../src/api/hooks';
import { Button, Card, Note, Row, Screen, T } from '../../src/components/primitives';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

/**
 * Step one of the 30-second value test (spec s28.1).
 *
 * Location is offered, never required: picking a city by hand reaches exactly
 * the same recommendations.
 */
export default function WhereAreYou() {
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
        setLocationNote('No problem — choose a city below and everything works the same.');
        await setLocationPrecision('off');

        return;
      }

      const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      await setCoords({ lat: position.coords.latitude, lng: position.coords.longitude }, 'precise');

      /* Still ask which city: coordinates alone do not tell us which catalogue
         to search, and we would rather be correct than clever. */
      setLocationNote('Got it. Now pick the city you are in or heading to.');
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
      <View style={{ gap: space.sm, marginTop: space.xl }}>
        <T variant="label" color={colors.accent}>
          The Experience
        </T>
        <T variant="display">Where are you?</T>
        <T variant="body" color={colors.inkMuted}>
          Tell us the city and we will work out what is genuinely worth your time there — not a list of
          everything that exists.
        </T>
      </View>

      <Row style={{ marginTop: space.xl }} gap={space.md}>
        <Button
          label={locating ? 'Locating…' : 'Use my location'}
          onPress={useMyLocation}
          disabled={locating}
          style={{ flex: 1 }}
        />
      </Row>

      {locationNote && (
        <View style={{ marginTop: space.md }}>
          <Note>{locationNote}</Note>
        </View>
      )}

      <TextInput
        placeholder="Search cities"
        placeholderTextColor={colors.inkFaint}
        value={query}
        onChangeText={setQuery}
        autoCorrect={false}
        style={{
          marginTop: space.xl,
          backgroundColor: colors.surface,
          borderColor: colors.line,
          borderWidth: 1,
          borderRadius: radius.md,
          paddingHorizontal: space.lg,
          paddingVertical: 14,
          color: colors.ink,
          fontSize: 16,
        }}
      />

      <View style={{ marginTop: space.lg, gap: space.sm }}>
        {isLoading && <ActivityIndicator color={colors.accent} />}
        {destinations.map((destination) => (
          <Card key={destination.id} onPress={() => choose(destination)}>
            <View style={{ padding: space.lg, gap: 4 }}>
              <Row style={{ justifyContent: 'space-between' }}>
                <T variant="heading">{destination.name}</T>
                <T variant="small" color={colors.inkFaint}>
                  {destination.country}
                </T>
              </Row>
              {destination.summary && (
                <T variant="small" color={colors.inkMuted} numberOfLines={2}>
                  {destination.summary}
                </T>
              )}
            </View>
          </Card>
        ))}

        {!isLoading && destinations.length === 0 && (
          <Note>No cities match that yet. The catalogue currently covers London, Rome, New York and Tokyo.</Note>
        )}
      </View>

      <Pressable onPress={() => router.push('/onboarding/purpose')} style={{ marginTop: space.xl }}>
        <T variant="small" color={colors.inkFaint}>
          Skip for now
        </T>
      </Pressable>
    </Screen>
  );
}
