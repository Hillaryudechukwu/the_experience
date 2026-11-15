import React, { useMemo, useState } from 'react';
import { useLocalSearchParams, useRouter } from 'expo-router';

import { ApiError } from '../../src/api/client';
import { useResetPassword } from '../../src/api/hooks';
import { PasswordField } from '../../src/components/PasswordField';
import { WayoaLogo } from '../../src/components/WayoaLogo';
import { Button, Gutter, Note, Screen, T } from '../../src/components/primitives';
import { showAlert } from '../../src/lib/alert';
import { space, useTheme } from '../../src/theme';

function firstParam(value: string | string[] | undefined): string {
  if (Array.isArray(value)) return value[0] ?? '';
  return value ?? '';
}

export default function ResetPassword() {
  const colors = useTheme();
  const router = useRouter();
  const params = useLocalSearchParams<{ token?: string | string[]; email?: string | string[] }>();
  const reset = useResetPassword();

  const token = useMemo(() => firstParam(params.token), [params.token]);
  const email = useMemo(() => firstParam(params.email).trim().toLowerCase(), [params.email]);

  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    if (!token || !email.includes('@')) {
      const message = 'Open the link from your email again — this page is missing the reset token.';
      setError(message);
      showAlert('Link incomplete', message);
      return;
    }

    if (password.length < 8) {
      const message = 'Use at least 8 characters.';
      setError(message);
      showAlert('Check your password', message);
      return;
    }

    if (password !== confirmation) {
      const message = 'The two passwords do not match.';
      setError(message);
      showAlert('Check your password', message);
      return;
    }

    setError(null);

    try {
      await reset.mutateAsync({
        email,
        token,
        password,
        password_confirmation: confirmation,
      });
      showAlert('Password updated', 'Sign in with your new password.');
      router.replace('/account/login');
    } catch (cause) {
      const message =
        cause instanceof ApiError
          ? cause.message
          : cause instanceof Error
            ? cause.message
            : 'Could not update your password.';
      setError(message);
      showAlert('Could not update your password', message);
    }
  };

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.sm, gap: space.md }}>
        <WayoaLogo variant="full" size="md" />
        <T variant="displayL">Choose a new password</T>
        <T variant="body" color={colors.text.secondary}>
          {email ? `For ${email}. ` : ''}
          Use at least 8 characters.
        </T>

        <PasswordField
          label="New password"
          value={password}
          onChange={setPassword}
          autoComplete="new-password"
          textContentType="newPassword"
        />
        <PasswordField
          label="Confirm password"
          value={confirmation}
          onChange={setConfirmation}
          autoComplete="new-password"
          textContentType="newPassword"
        />

        {error ? <Note tone="warning">{error}</Note> : null}

        <Button
          label={reset.isPending ? 'Saving…' : 'Update password'}
          onPress={submit}
          disabled={reset.isPending}
        />
        <Button label="Request a new link" tone="tertiary" onPress={() => router.replace('/account/forgot-password')} />
      </Gutter>
    </Screen>
  );
}
