import React, { useState } from 'react';
import { Platform, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { ApiError } from '../../src/api/client';
import { useLogin } from '../../src/api/hooks';
import { Button, Gutter, Note, Screen, T } from '../../src/components/primitives';
import { showAlert } from '../../src/lib/alert';
import { useBackTo } from '../../src/lib/navigation';
import { radius, space, useTheme } from '../../src/theme';

export default function Login() {
  const colors = useTheme();
  const router = useRouter();
  const goBack = useBackTo('/(tabs)/you');
  const login = useLogin();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    const trimmedEmail = email.trim().toLowerCase();

    if (!trimmedEmail.includes('@') || password.length < 1) {
      const message = 'Email and password are required.';
      setError(message);
      showAlert('Check your details', message);
      return;
    }

    setError(null);

    try {
      await login.mutateAsync({ email: trimmedEmail, password });
      router.replace('/(tabs)/you');
    } catch (cause) {
      const message =
        cause instanceof ApiError
          ? cause.message
          : cause instanceof Error
            ? cause.message
            : 'Could not sign in.';
      setError(message);
      showAlert('Could not sign in', message);
    }
  };

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.sm, gap: space.md }}>
        <T variant="displayL">Sign in</T>
        <T variant="body" color={colors.text.secondary}>
          Pick up the trip you left on another device. Guest work on this phone is adopted when you sign in.
        </T>

        <Field
          label="Email"
          value={email}
          onChange={setEmail}
          autoComplete="email"
          textContentType="emailAddress"
          keyboardType="email-address"
          autoCapitalize="none"
          autoCorrect={false}
        />
        <Field
          label="Password"
          value={password}
          onChange={setPassword}
          autoComplete="password"
          textContentType="password"
          secureTextEntry
          autoCapitalize="none"
          autoCorrect={false}
        />

        {error ? <Note tone="warning">{error}</Note> : null}

        <Button label={login.isPending ? 'Signing in…' : 'Sign in'} onPress={submit} disabled={login.isPending} />
        <Button label="Create an account" tone="tertiary" onPress={() => router.replace('/account/register')} />
        <Button label="Back" tone="secondary" onPress={goBack} />
      </Gutter>
    </Screen>
  );
}

function Field({
  label,
  value,
  onChange,
  secureTextEntry,
  autoComplete,
  textContentType,
  keyboardType,
  autoCapitalize,
  autoCorrect,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  secureTextEntry?: boolean;
  autoComplete?: 'email' | 'password';
  textContentType?: 'emailAddress' | 'password';
  keyboardType?: 'email-address' | 'default';
  autoCapitalize?: 'none' | 'sentences';
  autoCorrect?: boolean;
}) {
  const colors = useTheme();

  return (
    <View style={{ gap: 6 }}>
      <T variant="label" color={colors.text.tertiary}>
        {label}
      </T>
      <TextInput
        value={value}
        onChangeText={onChange}
        secureTextEntry={secureTextEntry}
        autoComplete={autoComplete}
        textContentType={textContentType}
        keyboardType={keyboardType}
        autoCapitalize={autoCapitalize}
        autoCorrect={autoCorrect}
        spellCheck={false}
        importantForAutofill="yes"
        placeholderTextColor={colors.text.tertiary}
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
  );
}
