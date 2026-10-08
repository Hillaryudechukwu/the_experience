import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, TextInput, View } from 'react-native';
import * as Location from 'expo-location';
import { useRouter } from 'expo-router';

import { useActivateDestination, useDestinationImport, useDestinations, useRetryDestinationImport } from '../../src/api/hooks';
import { http } from '../../src/api/client';
import type { UncoveredPlace } from '../../src/api/types';
import { useDebounced } from '../../src/lib/useDebounced';
import { Icon } from '../../src/components/Icon';
import { OnboardingChrome, OnboardingIntro } from '../../src/components/OnboardingChrome';
import { Button, Card, CardPress, Chip, Gutter, Note, Row, Screen, T } from '../../src/components/primitives';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

function distanceKm(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number {
  const radians = (degrees: number) => (degrees * Math.PI) / 180;
  const dLat = radians(b.lat - a.lat);
  const dLng = radians(b.lng - a.lng);
  const lat1 = radians(a.lat);
  const lat2 = radians(b.lat);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;

  return 6371 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

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
  const [worldwide, setWorldwide] = useState(false);
  const session = useSession();
  const activate = useActivateDestination();
  const importProgress = useDestinationImport(session.destinationImportId);
  const retryImport = useRetryDestinationImport();
  const viewedCandidates = useRef(new Set<string>());

  /* Debounced for the same reason as the search screen: this list already
     updates as you type, but without a pause it issues a request per
     keystroke, and "London" is six. */
  const debouncedQuery = useDebounced(query.trim());
  const { data, isLoading, isError } = useDestinations(debouncedQuery || undefined, worldwide);
  const destinations = data?.covered ?? [];

  /* Places that exist but that we do not cover. The API only looks these up
     when nothing in the catalogue matched, so this is empty almost always. */
  const elsewhere = data?.elsewhere ?? [];

  /* The whole catalogue, for the empty state: "not Paris" is only useful
     alongside what we can actually help with. */
  const { data: all } = useDestinations();
  const covered = all?.covered ?? [];
  const { setDestination, setDestinationImport, setCoords, setLocationPrecision } = session;

  useEffect(() => {
    setWorldwide(false);
  }, [query]);

  useEffect(() => {
    const progress = importProgress.data;

    if (!progress || progress.status !== 'succeeded') return;

    void http.post('/events', {
      events: [{
        type: 'destination_selected_after_import',
        subject_type: 'destination',
        subject_id: progress.destination.id,
        surface: 'destination_onboarding',
        properties: { import_id: progress.id, status: progress.status },
      }],
    }).catch(() => undefined);

    setDestination(progress.destination).then(() => {
      router.push('/onboarding/purpose');
    });
  }, [importProgress.data, router, setDestination]);

  useEffect(() => {
    const newlyViewed = elsewhere.filter((place) => {
      const key = `${place.name}|${place.country}`;
      if (viewedCandidates.current.has(key)) return false;
      viewedCandidates.current.add(key);
      return true;
    });

    if (newlyViewed.length === 0) return;

    void http.post('/events', {
      events: newlyViewed.map((place) => ({
        type: 'destination_candidate_viewed',
        surface: 'destination_onboarding',
        properties: { country_code: place.country_code, kind: place.kind, source: place.source },
      })),
    }).catch(() => undefined);
  }, [elsewhere]);

  const abandonImport = async () => {
    const progress = importProgress.data;
    if (progress) {
      void http.post('/events', {
        events: [{
          type: 'destination_activation_abandoned',
          subject_type: 'destination',
          subject_id: progress.destination.id,
          surface: 'destination_onboarding',
          properties: { import_id: progress.id, status: progress.status },
        }],
      }).catch(() => undefined);
    }
    await setDestinationImport(null);
  };

  const retryFailedImport = async () => {
    if (!session.destinationImportId) return;

    const result = await retryImport.mutateAsync(session.destinationImportId);
    await setDestinationImport(result.import_id);
  };

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
      const coords = { lat: position.coords.latitude, lng: position.coords.longitude };
      await setCoords(coords, 'precise');

      const nearest = covered
        .map((destination) => ({ destination, distance: distanceKm(coords, destination) }))
        .filter(({ distance }) => distance <= 100)
        .sort((a, b) => a.distance - b.distance)[0]?.destination;

      if (nearest) {
        await setDestination(nearest);
        setLocationNote(`Located near ${nearest.name}.`);
        router.push('/onboarding/purpose');
      } else {
        setLocationNote('Location found. We do not cover a nearby city yet, so choose one below.');
      }
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

  const prepare = async (candidate: UncoveredPlace) => {
    const result = await activate.mutateAsync(candidate.candidate_token);

    if (result.import_id) {
      await setDestinationImport(result.import_id);
    }
  };

  const continueWithLimitedCoverage = async () => {
    const progress = importProgress.data;
    if (!progress || progress.status !== 'partial') return;

    void http.post('/events', {
      events: [{
        type: 'destination_selected_after_import',
        subject_type: 'destination',
        subject_id: progress.destination.id,
        surface: 'destination_onboarding',
        properties: { import_id: progress.id, status: progress.status },
      }],
    }).catch(() => undefined);

    await setDestination(progress.destination);
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

        {isError ? <Note>Could not load locations. Check your connection and try again.</Note> : null}

        {session.destinationImportId && importProgress.data ? (
          <Card>
            <View style={{ padding: space.md, gap: space.xs }}>
              <Row gap={space.sm} align="center">
                {['queued', 'running'].includes(importProgress.data.status) ? (
                  <ActivityIndicator color={colors.action.primary} />
                ) : (
                  <Icon name="location" size={18} color={colors.action.primary} />
                )}
                <View style={{ flex: 1 }}>
                  <T variant="h3">Preparing {importProgress.data.destination.name}</T>
                  <T variant="small" color={colors.text.secondary}>
                    {importProgress.data.message}
                  </T>
                </View>
              </Row>
              {importProgress.data.status === 'failed' ? (
                <View style={{ gap: space.sm }}>
                  {importProgress.data.retryable ? (
                    <Button
                      label={retryImport.isPending ? 'Retrying…' : 'Try preparing again'}
                      onPress={retryFailedImport}
                      disabled={retryImport.isPending}
                    />
                  ) : null}
                  <Button
                    label="Choose another city"
                    tone="secondary"
                    onPress={abandonImport}
                  />
                </View>
              ) : null}
              {importProgress.data.status === 'partial' ? (
                <View style={{ gap: space.sm }}>
                  <Note>
                    Interlude found some grounded recommendations, but coverage is still limited. You can continue now or choose another city.
                  </Note>
                  <Button
                    label="Continue with limited coverage"
                    onPress={continueWithLimitedCoverage}
                  />
                  <Button
                    label="Choose another city"
                    tone="secondary"
                    onPress={abandonImport}
                  />
                </View>
              ) : null}
            </View>
          </Card>
        ) : null}

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

        {!isLoading && query.trim().length >= 3 && destinations.length > 0 && !worldwide ? (
          <Button
            label="Search worldwide for another city with this name"
            tone="secondary"
            onPress={() => setWorldwide(true)}
          />
        ) : null}

        {worldwide && elsewhere.length > 0 ? (
          <View style={{ gap: space.xs }}>
            <T variant="label" color={colors.text.tertiary}>
              Other cities worldwide
            </T>
            {elsewhere.map((place) => (
              <Card key={place.display_name}>
                <CardPress
                  onPress={() => prepare(place)}
                  accessibilityLabel={`Prepare ${place.name}, ${place.country}`}
                  disabled={activate.isPending || !!session.destinationImportId}
                >
                  <Row justify="space-between" align="center" style={{ padding: space.md }} gap={space.sm}>
                    <View style={{ flex: 1, gap: 3 }}>
                      <T variant="h3">{place.name}</T>
                      <T variant="small" color={colors.text.secondary}>
                        {[place.region, place.country].filter(Boolean).join(', ')}
                      </T>
                      <T variant="caption" color={colors.action.primary}>
                        {activate.isPending ? 'Starting…' : 'Prepare this city'}
                      </T>
                    </View>
                    <Icon name="chevron" size={16} color={colors.text.tertiary} />
                  </Row>
                </CardPress>
              </Card>
            ))}
          </View>
        ) : null}

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
                  Cities Interlude can prepare
                </T>
                {elsewhere.map((place) => (
                  <Card key={place.display_name}>
                    <CardPress
                      onPress={() => prepare(place)}
                      accessibilityLabel={`Prepare ${place.name}, ${place.country}`}
                      disabled={activate.isPending || !!session.destinationImportId}
                    >
                      <Row justify="space-between" align="center" style={{ padding: space.md }} gap={space.sm}>
                        <View style={{ flex: 1, gap: 3 }}>
                          <T variant="h3">{place.name}</T>
                          <T variant="small" color={colors.text.secondary}>
                            {[place.region, place.country].filter(Boolean).join(', ')}
                          </T>
                          <T variant="caption" color={colors.action.primary}>
                            {activate.isPending ? 'Starting…' : 'Prepare this city'}
                          </T>
                        </View>
                        <Icon name="chevron" size={16} color={colors.text.tertiary} />
                      </Row>
                    </CardPress>
                  </Card>
                ))}
              </View>
            ) : null}

            <Note>
              {elsewhere.length > 0
                ? 'Choose a city and Interlude will prepare grounded recommendations from live place data. You can leave this screen while it works.'
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
