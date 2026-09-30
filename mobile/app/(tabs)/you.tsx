import React from 'react';
import { Alert, Linking, Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { API_URL, http } from '../../src/api/client';
import { useExperienceDna, usePassport, useProfile, useUpdateProfile } from '../../src/api/hooks';
import { Icon, type IconName } from '../../src/components/Icon';
import { Button, Card, CardPress, Chip, Divider, Gutter, LoadFailure, Loading, Note, Row, Screen, SectionHeader, T } from '../../src/components/primitives';
import { titleCase } from '../../src/lib/format';
import { useSession } from '../../src/store/session';
import { radius, space, useTheme } from '../../src/theme';

/*
 * The policy lives on the web rather than in the bundle, so the app, the two
 * store listings and a reviewer with only a browser all read one document.
 *
 * It prefers a URL on a domain we own, because that is the one the listings
 * carry and it has to survive the backend moving host — a store listing
 * pointing at a dead policy is a compliance failure, not a broken link. The
 * copy served beside the API is the fallback for development and for any build
 * that has not been given the public address.
 */
const PRIVACY_POLICY_URL =
  process.env.EXPO_PUBLIC_PRIVACY_URL ?? `${API_URL.replace(/\/api\/?$/, '')}/legal/privacy`;

const INTERESTS = [
  'history', 'food', 'culture', 'architecture', 'nature', 'art', 'music',
  'shopping', 'nightlife', 'sport', 'photography', 'wellness', 'family',
  'adventure', 'local_life',
];

/**
 * You (Figma: 40).
 *
 * Personalisation is shown, not hidden. The DNA bars are the system explaining
 * what it thinks it knows, and every one of them is editable — a profile you
 * cannot correct is a profile you cannot trust.
 */
export default function You() {
  const colors = useTheme();
  const router = useRouter();
  const session = useSession();

  const { data: profile, isLoading, isError, refetch } = useProfile();
  const { data: dna } = useExperienceDna();
  const { data: passport } = usePassport();
  const update = useUpdateProfile();

  /* Loading and "did not load" are different states. Collapsing them into one
     spinner meant a failed request turned forever. */
  if (isLoading) {
    return (
      <Screen>
        <Loading />
      </Screen>
    );
  }

  if (isError || !profile) {
    return (
      <Screen>
        <Gutter>
          <LoadFailure title="Your profile did not load" onRetry={() => refetch()} />
        </Gutter>
      </Screen>
    );
  }

  const selected = new Set(profile.interests.filter((i) => i.weight >= 50).map((i) => i.interest));

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
      <Gutter style={{ paddingTop: space.sm }}>
        <T variant="displayL">You</T>
        {profile.is_guest ? (
          <View style={{ marginTop: space.md }}>
            <Note tone="info" icon={<Icon name="info" size={16} color={colors.action.onSoft} />}>
              Browsing as a guest. Saving, planning and booking all work. Create an account only when you want
              this across devices.
            </Note>
          </View>
        ) : null}
      </Gutter>

      {/* ── Experience DNA ─────────────────────────────────────────────── */}
      {dna ? (
        <Gutter>
          <SectionHeader title="Your Experience DNA" caption="What we have learned, and what you told us" />
          <Card tone="warm">
            <View style={{ padding: space.md, gap: space.md }}>
              <Row justify="space-between" align="flex-start">
                <View style={{ flex: 1 }}>
                  <T variant="h2">{dna.travel_style}</T>
                  <T variant="small" color={colors.text.secondary}>
                    {dna.preferred_pace}
                  </T>
                </View>
                {dna.typical_spend ? (
                  <View style={{ alignItems: 'flex-end' }}>
                    <T variant="label" color={colors.text.tertiary}>
                      Typical
                    </T>
                    <T variant="bodyStrong">{dna.typical_spend.formatted}/day</T>
                  </View>
                ) : null}
              </Row>

              <View style={{ gap: space.xs }}>
                {dna.interests.slice(0, 6).map((interest) => (
                  <View key={interest.interest} style={{ gap: 5 }}>
                    <Row justify="space-between">
                      <Row gap={6}>
                        <T variant="small">{interest.label}</T>
                        {interest.learned ? (
                          <T variant="caption" color={colors.text.tertiary}>
                            learned
                          </T>
                        ) : null}
                      </Row>
                      <T variant="smallStrong" color={colors.text.secondary}>
                        {interest.percent}%
                      </T>
                    </Row>
                    <View style={{ height: 6, borderRadius: 3, backgroundColor: colors.background.elevated }}>
                      <View
                        style={{
                          width: `${interest.percent}%`,
                          height: 6,
                          borderRadius: 3,
                          backgroundColor: colors.action.primary,
                        }}
                      />
                    </View>
                  </View>
                ))}
              </View>

              {!dna.is_complete ? (
                <T variant="caption" color={colors.text.tertiary}>
                  Pick a few more interests below and this gets noticeably sharper.
                </T>
              ) : null}
            </View>
          </Card>
        </Gutter>
      ) : null}

      {/* ── Passport ───────────────────────────────────────────────────── */}
      {passport ? (
        <Gutter>
          <SectionHeader title="Passport" action="Open" onAction={() => router.push('/passport')} />
          <Card>
            <CardPress onPress={() => router.push('/passport')} accessibilityLabel="Open your passport">
              <Row justify="space-around" style={{ padding: space.lg }}>
                <Stat label="Experiences" value={passport.totals.experiences} />
                <Stat label="Cities" value={passport.totals.cities} />
                <Stat label="Countries" value={passport.totals.countries} />
              </Row>
            </CardPress>
          </Card>
        </Gutter>
      ) : null}

      {/* ── Interests ──────────────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader title="Interests" />
        <Row gap={space.xs} wrap>
          {INTERESTS.map((key) => (
            <Chip
              key={key}
              label={titleCase(key)}
              selected={selected.has(key)}
              tone="accent"
              onPress={() => update.mutate({ interests: { [key]: selected.has(key) ? 10 : 85 } })}
            />
          ))}
        </Row>
      </Gutter>

      {/* ── Travel style ───────────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader title="Pace" />
        <Row gap={space.xs}>
          {(['slow', 'moderate', 'fast'] as const).map((pace) => (
            <Chip
              key={pace}
              label={pace === 'slow' ? 'Slow' : pace === 'fast' ? 'Packed' : 'Balanced'}
              selected={profile.travel_pace === pace}
              onPress={() => update.mutate({ travel_pace: pace })}
            />
          ))}
        </Row>

        <SectionHeader title="Walking" />
        <Row gap={space.xs}>
          {(['low', 'medium', 'high'] as const).map((tolerance) => (
            <Chip
              key={tolerance}
              label={tolerance === 'low' ? 'Keep it short' : tolerance === 'high' ? 'Happy to walk' : 'Some walking'}
              selected={profile.walking_tolerance === tolerance}
              onPress={() => update.mutate({ walking_tolerance: tolerance })}
            />
          ))}
        </Row>
      </Gutter>

      {/* ── Privacy ────────────────────────────────────────────────────── */}
      <Gutter>
        <SectionHeader title="Location and privacy" />
        <Row gap={space.xs} wrap>
          {(['precise', 'approximate', 'off'] as const).map((mode) => (
            <Chip
              key={mode}
              label={mode === 'precise' ? 'Precise' : mode === 'approximate' ? 'Approximate' : 'Off'}
              selected={session.locationPrecision === mode}
              onPress={() => session.setLocationPrecision(mode)}
            />
          ))}
        </Row>
        <View style={{ marginTop: space.sm }}>
          <Note>
            Approximate rounds your position to about a kilometre before anything sees it. Off uses the city
            centre instead — everything still works.
          </Note>
        </View>
      </Gutter>

      {/* ── Links ──────────────────────────────────────────────────────── */}
      <Gutter style={{ marginTop: space.xl }}>
        <Card>
          <LinkRow icon="lock" label="Delete my stored location history" onPress={forgetLocation} />
          <Divider />
          <LinkRow icon="compass" label="Start a new journey" onPress={() => router.push('/onboarding')} />
          <Divider />
          <LinkRow icon="info" label="City essentials" onPress={() => router.push('/essentials')} />
        </Card>
      </Gutter>

      {/* ── Account ────────────────────────────────────────────────────
          Both stores require these two to be reachable from inside the app:
          a policy a traveller can actually read, and a way out. Neither is
          buried behind a support email. */}
      <Gutter style={{ marginTop: space.lg }}>
        <Card>
          <LinkRow
            icon="lock"
            label="Privacy policy"
            onPress={async () => {
              /*
               * Not swallowed. This was `.catch(() => {})`, so a device that
               * cannot open the URL — no browser, offline, a malformed host
               * from a misconfigured build — answered the tap by doing
               * absolutely nothing.
               *
               * Both stores require this control and a reviewer will press it.
               * A dead button is a rejection; an address they can read is not.
               */
              try {
                await Linking.openURL(PRIVACY_POLICY_URL);
              } catch {
                Alert.alert('Could not open the policy', PRIVACY_POLICY_URL);
              }
            }}
          />
          <Divider />
          <LinkRow
            icon="close"
            label="Delete my account"
            tone="destructive"
            onPress={() => router.push('/account/delete')}
          />
        </Card>
      </Gutter>

      <Gutter style={{ marginTop: space.lg }}>
        <Pressable
          onPress={() =>
            Alert.alert('Reset this device', 'Clears your local session. Saved data stays on the server.', [
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
          style={{ alignItems: 'center', paddingVertical: space.sm }}
        >
          <T variant="small" color={colors.text.tertiary}>
            Reset this device
          </T>
        </Pressable>
      </Gutter>
    </Screen>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  const colors = useTheme();

  return (
    <View style={{ alignItems: 'center', gap: 2 }}>
      <T variant="h1">{value}</T>
      <T variant="caption" color={colors.text.secondary}>
        {label}
      </T>
    </View>
  );
}

function LinkRow({
  icon,
  label,
  onPress,
  tone = 'neutral',
}: {
  icon: IconName;
  label: string;
  onPress: () => void;
  tone?: 'neutral' | 'destructive';
}) {
  const colors = useTheme();
  const ink = tone === 'destructive' ? colors.status.error : colors.text.secondary;

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      style={({ pressed }) => ({ opacity: pressed ? 0.7 : 1 })}
    >
      <Row justify="space-between" style={{ padding: space.md }}>
        <Row gap={space.sm}>
          <Icon name={icon} size={18} color={ink} />
          <T variant="body" color={tone === 'destructive' ? colors.status.error : undefined}>
            {label}
          </T>
        </Row>
        <Icon name="chevron" size={16} color={colors.text.tertiary} />
      </Row>
    </Pressable>
  );
}
