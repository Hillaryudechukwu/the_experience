import React, { useState } from 'react';
import { Alert, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useDeleteAccount, useProfile } from '../../src/api/hooks';
import { Icon } from '../../src/components/Icon';
import { Button, Card, Gutter, Note, Row, Screen, T } from '../../src/components/primitives';
import { useSession } from '../../src/store/session';
import { radius, space, TOUCH_TARGET, useTheme } from '../../src/theme';
import { useBackTo } from '../../src/lib/navigation';

/**
 * Account deletion (Apple App Store guideline 5.1.1(v)).
 *
 * A screen rather than an alert. Apple requires the path to be easy to find
 * and unambiguous, and an alert cannot say what is actually about to happen —
 * which here includes the one thing that is not deleted. A traveller who finds
 * a booking record surviving a "delete everything" they were never warned
 * about has been misled, whatever the legal basis for keeping it.
 *
 * The password is asked for again even though the device already holds a valid
 * token. The one realistic case for this screen being reached by someone other
 * than the account holder is a phone that has been picked up by somebody else,
 * and the password is the only thing here that such a person would not have.
 */
export default function DeleteAccount() {
  const colors = useTheme();
  const router = useRouter();
  const goBack = useBackTo('/(tabs)/you');
  const session = useSession();

  const { data: profile } = useProfile();
  const remove = useDeleteAccount();

  const [password, setPassword] = useState('');
  const isGuest = profile?.is_guest ?? true;
  const canSubmit = isGuest || password.length > 0;

  const confirm = () => {
    Alert.alert(
      'Delete everything?',
      'This is immediate and cannot be undone.',
      [
        { text: 'Keep my account', style: 'cancel' },
        { text: 'Delete', style: 'destructive', onPress: run },
      ],
    );
  };

  const run = async () => {
    try {
      const result = await remove.mutateAsync(isGuest ? undefined : password);

      await session.reset();

      Alert.alert(
        'Your account has been deleted',
        result.retained_bookings > 0
          ? `Everything is gone. ${result.retained_bookings} paid booking${result.retained_bookings === 1 ? '' : 's'} had to be kept as a financial record, with your name and every link to you removed from ${result.retained_bookings === 1 ? 'it' : 'them'}.`
          : 'Everything held about you has been removed.',
      );

      router.replace('/onboarding');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Something went wrong.';
      Alert.alert('Could not delete your account', message);
    }
  };

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.md, gap: space.md }}>
        <T variant="displayL">Delete your account</T>

        <T variant="body" color={colors.text.secondary}>
          {isGuest
            ? 'You are browsing as a guest, so there is no account to sign out of — but this device has still built up a history, and this erases it.'
            : 'This removes your account and everything attached to it. It is immediate and cannot be undone.'}
        </T>

        {/* ── What goes ────────────────────────────────────────────────── */}
        <Card>
          <View style={{ padding: space.md, gap: space.sm }}>
            <T variant="label" color={colors.text.tertiary}>
              What will be deleted
            </T>
            {[
              'Your profile, interests and travel style',
              'Every trip, plan and fixed commitment you entered',
              'Saved and completed experiences',
              'Everywhere the app recorded you having been',
              'Your conversations with the guide',
              isGuest ? 'This device’s session' : 'Your account, password and every sign-in',
            ].map((line) => (
              <Row key={line} gap={space.xs} align="flex-start">
                <Icon name="close" size={15} color={colors.status.error} strokeWidth={2.1} />
                <T variant="small" color={colors.text.primary} style={{ flex: 1 }}>
                  {line}
                </T>
              </Row>
            ))}
          </View>
        </Card>

        {/* ── What stays, and why ──────────────────────────────────────── */}
        <Note tone="warning" icon={<Icon name="info" size={16} color={colors.status.warning} />}>
          Bookings you actually paid for are kept. A supplier, a tax authority or a card dispute may need
          that record, so we keep the amount and the reference — and delete the traveller names and every
          link back to you. Bookings that never reached payment are deleted with everything else.
        </Note>

        {/* ── Proof of identity ────────────────────────────────────────── */}
        {!isGuest ? (
          <View style={{ gap: space.xs }}>
            <T variant="label" color={colors.text.tertiary}>
              Confirm your password
            </T>
            <TextInput
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              autoCapitalize="none"
              autoComplete="current-password"
              textContentType="password"
              placeholder="Your password"
              placeholderTextColor={colors.text.tertiary}
              accessibilityLabel="Your password"
              style={{
                minHeight: TOUCH_TARGET,
                borderWidth: 1,
                borderColor: colors.border.strong,
                borderRadius: radius.control,
                paddingHorizontal: space.md,
                color: colors.text.primary,
                backgroundColor: colors.background.elevated,
                fontSize: 16,
              }}
            />
          </View>
        ) : null}

        <View style={{ gap: space.xs, marginTop: space.xs }}>
          <Button
            label={remove.isPending ? 'Deleting…' : 'Delete my account'}
            tone="destructive"
            onPress={confirm}
            disabled={!canSubmit || remove.isPending}
            loading={remove.isPending}
          />
          <Button label="Keep my account" tone="tertiary" onPress={goBack} />
        </View>
      </Gutter>
    </Screen>
  );
}
