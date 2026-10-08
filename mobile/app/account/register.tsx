import React, { useState } from 'react';
import { Platform, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { ApiError } from '../../src/api/client';
import { useRegister } from '../../src/api/hooks';
import { Button, Gutter, Note, Screen, T } from '../../src/components/primitives';
import { showAlert } from '../../src/lib/alert';
import { useBackTo } from '../../src/lib/navigation';
import { radius, space, useTheme } from '../../src/theme';

export default function Register() {
  const colors = useTheme();
  const router = useRouter();
  const goBack = useBackTo('/(tabs)/you');
  const register = useRegister();

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    const trimmedName = name.trim();
    const trimmedEmail = email.trim().toLowerCase();

    if (trimmedName.length < 2 || !trimmedEmail.includes('@') || password.length < 8) {
      const message = 'Name, a valid email, and a password of at least 8 characters are required.';
      setError(message);
      showAlert('Check your details', message);
      return;
    }

    setError(null);

    try {
      await register.mutateAsync({ name: trimmedName, email: trimmedEmail, password });
      /* Navigate first — on native, presenting an alert before replace can leave
         the stack looking unchanged if the alert is dismissed oddly. */
      router.replace('/(tabs)/you');
      showAlert('Account created', 'Your guest saves, plans and bookings are now on this account.');
    } catch (cause) {
      const message =
        cause instanceof ApiError
          ? cause.message
          : cause instanceof Error
            ? cause.message
            : 'Could not create your account.';
      setError(message);
      showAlert('Could not create your account', message);
    }
  };

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.sm, gap: space.md }}>
        <T variant="displayL">Create an account</T>
        <T variant="body" color={colors.text.secondary}>
          Keep this trip across devices. Everything you already saved as a guest comes with you.
        </T>

        <Field
          label="Name"
          value={name}
          onChange={setName}
          autoComplete="name"
          textContentType="name"
          autoCapitalize="words"
        />
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
          autoComplete="new-password"
          textContentType="newPassword"
          secureTextEntry
          autoCapitalize="none"
          autoCorrect={false}
        />

        <Note>Use at least 8 characters. We never see your password in plain text after you submit.</Note>

        {error ? (
          <Note tone="warning">
            {error}
          </Note>
        ) : null}

        <Button label={register.isPending ? 'Creating…' : 'Create account'} onPress={submit} disabled={register.isPending} />
        <Button label="I already have an account" tone="tertiary" onPress={() => router.replace('/account/login')} />
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
  autoComplete?: 'name' | 'email' | 'new-password' | 'password';
  textContentType?: 'name' | 'emailAddress' | 'newPassword' | 'password';
  keyboardType?: 'email-address' | 'default';
  autoCapitalize?: 'none' | 'sentences' | 'words';
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
        /* Stop iOS from uppercasing the first password character. */
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
          /* Prevents iOS zoom-on-focus that can leave the submit control off-screen. */
          fontSize: Platform.OS === 'web' ? 16 : 17,
        }}
      />
    </View>
  );
}
