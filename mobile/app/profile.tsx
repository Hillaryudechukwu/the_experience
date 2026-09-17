import React from 'react';
import { Alert, Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useExperienceDna, usePassport, useProfile, useUpdateProfile } from '../src/api/hooks';
import { Button, Card, Chip, Loading, Note, Row, Screen, SectionHeader, T } from '../src/components/primitives';
import { INTERESTS } from '../src/lib/reasons';
import { http } from '../src/api/client';
import { useSession } from '../src/store/session';
import { radius, space, useTheme } from '../src/theme';

/** Spec s2.3 Experience DNA, plus the privacy controls from s23.2. */
export default function Profile() {
  const colors = useTheme();
  const router = useRouter();
  const session = useSession();

  const { data: profile, isLoading } = useProfile();
  const { data: dna } = useExperienceDna();
  const { data: passport } = usePassport();
  const update = useUpdateProfile();

  if (isLoading || !profile) {
    return (
      <Screen>
        <Loading />
      </Screen>
    );
  }

  const selected = new Set(profile.interests.filter((i) => i.weight >= 50).map((i) => i.interest));

  const toggleInterest = (key: string) => {
    update.mutate({ interests: { [key]: selected.has(key) ? 10 : 85 } });
  };

  const forgetLocation = async () => {
    try {
      const result = await http.delete<{ deleted_snapshots: number }>('/privacy/location-history');
      Alert.alert('Deleted', `Removed ${result.deleted_snapshots} stored context snapshots.`);
    } catch (cause) {
      Alert.alert('Could not delete', cause instanceof Error ? cause.message : 'Try again.');
    }
  };

  return (
    <Screen>
      <Row style={{ justifyContent: 'space-between' }}>
        <T variant="display">You</T>
        <Pressable onPress={() => router.back()} hitSlop={10}>
          <T variant="bodyStrong" color={colors.accent}>
            Done
          </T>
        </Pressable>
      </Row>

      {profile.is_guest && (
        <View style={{ marginTop: space.lg }}>
          <Note>
            You are browsing as a guest. Everything works — saving, planning and booking. Create an account
            only when you want it across devices.
          </Note>
        </View>
      )}

      {dna && (
        <>
          <SectionHeader title="Your Experience DNA" />
          <Card>
            <View style={{ padding: space.lg, gap: space.md }}>
              <Row style={{ justifyContent: 'space-between' }}>
                <T variant="heading">{dna.travel_style}</T>
                <T variant="small" color={colors.inkMuted}>
                  {dna.preferred_pace}
                </T>
              </Row>

              {dna.interests.slice(0, 6).map((interest) => (
                <View key={interest.interest} style={{ gap: 4 }}>
                  <Row style={{ justifyContent: 'space-between' }}>
                    <Row gap={space.xs}>
                      <T variant="small">{interest.label}</T>
                      {interest.learned && (
                        <T variant="small" color={colors.inkFaint}>
                          (learned)
                        </T>
                      )}
                    </Row>
                    <T variant="small" color={colors.inkMuted}>
                      {interest.percent}%
                    </T>
                  </Row>
                  <View style={{ height: 6, borderRadius: 3, backgroundColor: colors.surfaceAlt }}>
                    <View
                      style={{
                        width: `${interest.percent}%`,
                        height: 6,
                        borderRadius: 3,
                        backgroundColor: colors.accent,
                      }}
                    />
                  </View>
                </View>
              ))}

              {!dna.is_complete && (
                <T variant="small" color={colors.inkFaint}>
                  Pick a few more interests below and this gets noticeably sharper.
                </T>
              )}
            </View>
          </Card>
        </>
      )}

      <SectionHeader title="Interests" />
      <Row gap={space.sm} wrap>
        {INTERESTS.map((interest) => (
          <Chip
            key={interest.key}
            label={interest.label}
            selected={selected.has(interest.key)}
            onPress={() => toggleInterest(interest.key)}
            tone="accent"
          />
        ))}
      </Row>

      <SectionHeader title="Pace" />
      <Row gap={space.sm}>
        {(['slow', 'moderate', 'fast'] as const).map((pace) => (
          <Chip
            key={pace}
            label={pace === 'slow' ? 'Take it slow' : pace === 'fast' ? 'Pack it in' : 'Balanced'}
            selected={profile.travel_pace === pace}
            onPress={() => update.mutate({ travel_pace: pace })}
          />
        ))}
      </Row>

      <SectionHeader title="Walking" />
      <Row gap={space.sm}>
        {(['low', 'medium', 'high'] as const).map((tolerance) => (
          <Chip
            key={tolerance}
            label={tolerance === 'low' ? 'Keep it short' : tolerance === 'high' ? 'Happy to walk' : 'Some walking'}
            selected={profile.walking_tolerance === tolerance}
            onPress={() => update.mutate({ walking_tolerance: tolerance })}
          />
        ))}
      </Row>

      {passport && (
        <>
          <SectionHeader title="Passport" action="Open" onAction={() => router.push('/passport')} />
          <Card>
            <Row style={{ padding: space.lg, justifyContent: 'space-around' }}>
              <Stat label="Experiences" value={passport.totals.experiences} />
              <Stat label="Cities" value={passport.totals.cities} />
              <Stat label="Countries" value={passport.totals.countries} />
            </Row>
          </Card>
        </>
      )}

      <SectionHeader title="Location and privacy" />
      <Row gap={space.sm} wrap>
        {(['precise', 'approximate', 'off'] as const).map((mode) => (
          <Chip
            key={mode}
            label={mode === 'precise' ? 'Precise' : mode === 'approximate' ? 'Approximate' : 'Off'}
            selected={session.locationPrecision === mode}
            onPress={() => session.setLocationPrecision(mode)}
          />
        ))}
      </Row>
      <View style={{ marginTop: space.md }}>
        <Note>
          Approximate rounds your position to about a kilometre before anything sees it. Off means we use the
          city centre instead — everything still works.
        </Note>
      </View>

      <Button
        label="Delete my stored location history"
        tone="secondary"
        onPress={forgetLocation}
        style={{ marginTop: space.lg }}
      />

      <Button
        label="Start a new journey"
        tone="secondary"
        onPress={() => router.push('/onboarding')}
        style={{ marginTop: space.md }}
      />

      <Pressable
        onPress={() =>
          Alert.alert('Reset this device', 'This clears your local session. Your saved data stays on the server.', [
            { text: 'Cancel', style: 'cancel' },
            {
              text: 'Reset',
              style: 'destructive',
              onPress: async () => {
                await session.reset();
                router.replace('/onboarding');
              },
            },
          ])
        }
        style={{ marginTop: space.xl, alignItems: 'center' }}
      >
        <T variant="small" color={colors.inkFaint}>
          Reset this device
        </T>
      </Pressable>
    </Screen>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  const colors = useTheme();

  return (
    <View style={{ alignItems: 'center', gap: 2 }}>
      <T variant="title">{value}</T>
      <T variant="small" color={colors.inkMuted}>
        {label}
      </T>
    </View>
  );
}
