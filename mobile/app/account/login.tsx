import React, { useState } from 'react';
import { Alert, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useLogin } from '../../src/api/hooks';
import { Button, Gutter, Screen, T } from '../../src/components/primitives';
import { useBackTo } from '../../src/lib/navigation';
import { radius, space, useTheme } from '../../src/theme';

export default function Login() {
  const colors = useTheme();
  const router = useRouter();
  const goBack = useBackTo('/(tabs)/you');
  const login = useLogin();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  const submit = async () => {
    if (!email.includes('@') || password.length < 1) {
      Alert.alert('Check your details', 'Email and password are required.');
      return;
    }

    try {
      await login.mutateAsync({ email: email.trim(), password });
      router.replace('/(tabs)/you');
    } catch {
      /* hook alerts */
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
          keyboardType="email-address"
          autoCapitalize="none"
        />
        <Field label="Password" value={password} onChange={setPassword} autoComplete="password" secureTextEntry />

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
  keyboardType,
  autoCapitalize,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  secureTextEntry?: boolean;
  autoComplete?: 'email' | 'password';
  keyboardType?: 'email-address' | 'default';
  autoCapitalize?: 'none' | 'sentences';
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
        keyboardType={keyboardType}
        autoCapitalize={autoCapitalize}
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
        }}
      />
    </View>
  );
}
