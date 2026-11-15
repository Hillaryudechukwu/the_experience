import React, { useState } from 'react';
import { Platform, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { ApiError } from '../../src/api/client';
import { useForgotPassword } from '../../src/api/hooks';
import { WayoaLogo } from '../../src/components/WayoaLogo';
import { Button, Gutter, Note, Screen, T } from '../../src/components/primitives';
import { showAlert } from '../../src/lib/alert';
import { useBackTo } from '../../src/lib/navigation';
import { radius, space, useTheme } from '../../src/theme';

export default function ForgotPassword() {
  const colors = useTheme();
  const router = useRouter();
  const goBack = useBackTo('/account/login');
  const forgot = useForgotPassword();

  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    const trimmed = email.trim().toLowerCase();
    if (!trimmed.includes('@')) {
      const message = 'Enter the email on your account.';
      setError(message);
      showAlert('Check your email', message);
      return;
    }

    setError(null);

    try {
      await forgot.mutateAsync(trimmed);
      setSent(true);
    } catch (cause) {
      const message =
        cause instanceof ApiError
          ? cause.message
          : cause instanceof Error
            ? cause.message
            : 'Could not send a reset link.';
      setError(message);
      showAlert('Could not send a reset link', message);
    }
  };

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.sm, gap: space.md }}>
        <WayoaLogo variant="full" size="md" />
        <T variant="displayL">Forgot password</T>
        <T variant="body" color={colors.text.secondary}>
          Enter the email on your account. If it matches, we send a link to choose a new password.
        </T>

        {sent ? (
          <Note tone="info">
            If that email is on an account, a reset link is on its way. It expires in one hour.
          </Note>
        ) : (
          <>
            <View style={{ gap: 6 }}>
              <T variant="label" color={colors.text.tertiary}>
                Email
              </T>
              <TextInput
                accessibilityLabel="Email"
                value={email}
                onChangeText={setEmail}
                autoComplete="email"
                textContentType="emailAddress"
                keyboardType="email-address"
                autoCapitalize="none"
                autoCorrect={false}
                spellCheck={false}
                style={{
                  borderWidth: 1,
                  borderColor: colors.border.strong,
                  borderRadius: radius.control,
                  paddingHorizontal: space.md,
                  paddingVertical: 12,
                  color: colors.text.primary,
                  backgroundColor: colors.background.elevated,
                  minHeight: 48,
                  fontSize: Platform.OS === 'web' ? 16 : 17,
                }}
              />
            </View>

            {error ? <Note tone="warning">{error}</Note> : null}

            <Button
              label={forgot.isPending ? 'Sending…' : 'Send reset link'}
              onPress={submit}
              disabled={forgot.isPending}
            />
          </>
        )}

        <Button label="Back to sign in" tone="secondary" onPress={() => router.replace('/account/login')} />
        <Button label="Back" tone="tertiary" onPress={goBack} />
      </Gutter>
    </Screen>
  );
}
