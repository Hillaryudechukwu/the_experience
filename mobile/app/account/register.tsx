import React, { useState } from 'react';
import { Alert, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useRegister } from '../../src/api/hooks';
import { Button, Gutter, Note, Screen, T } from '../../src/components/primitives';
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

  const submit = async () => {
    if (name.trim().length < 2 || !email.includes('@') || password.length < 8) {
      Alert.alert('Check your details', 'Name, a valid email, and a password of at least 8 characters are required.');
      return;
    }

    try {
      await register.mutateAsync({ name: name.trim(), email: email.trim(), password });
      Alert.alert('Account created', 'Your guest saves, plans and bookings are now on this account.');
      router.replace('/(tabs)/you');
    } catch {
      /* hook alerts */
    }
  };

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.sm, gap: space.md }}>
        <T variant="displayL">Create an account</T>
        <T variant="body" color={colors.text.secondary}>
          Keep this trip across devices. Everything you already saved as a guest comes with you.
        </T>

        <Field label="Name" value={name} onChange={setName} autoComplete="name" />
        <Field
          label="Email"
          value={email}
          onChange={setEmail}
          autoComplete="email"
          keyboardType="email-address"
          autoCapitalize="none"
        />
        <Field
          label="Password"
          value={password}
          onChange={setPassword}
          autoComplete="new-password"
          secureTextEntry
        />

        <Note>Use at least 8 characters. We never see your password in plain text after you submit.</Note>

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
  keyboardType,
  autoCapitalize,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  secureTextEntry?: boolean;
  autoComplete?: 'name' | 'email' | 'new-password' | 'password';
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
