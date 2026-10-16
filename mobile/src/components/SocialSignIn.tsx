import React, { useEffect, useState } from 'react';
import { Platform, View } from 'react-native';
import * as AppleAuthentication from 'expo-apple-authentication';
import * as Google from 'expo-auth-session/providers/google';
import * as WebBrowser from 'expo-web-browser';

import { ApiError } from '../api/client';
import { useIdentitySignIn } from '../api/hooks';
import { showAlert } from '../lib/alert';
import { space } from '../theme';
import { Button } from './primitives';

WebBrowser.maybeCompleteAuthSession();

const enabled = process.env.EXPO_PUBLIC_GOOGLE_AUTH_ENABLED === 'true';

export type IdentityProof = {
  id_token: string;
  provider: 'google' | 'apple';
};

/**
 * Google everywhere, with Apple above it on iOS when the device offers it —
 * App Store review expects Sign in with Apple alongside other providers.
 *
 * Google is not gated on Apple being available. appleAvailable starts false
 * and is only set by an async check, so gating it meant the first render of
 * every iOS screen had no buttons at all, and an iOS build missing the
 * expo-apple-authentication entitlement showed an empty box permanently.
 * Offering one provider is a degraded sign-in; offering none is a dead end.
 *
 * The flag defaults off, so a build with no client ids shows nothing.
 */
export function SocialSignIn(props: {
  mode?: 'account' | 'proof';
  onSignedIn?: () => void;
  onProof?: (proof: IdentityProof) => void;
}) {
  if (!enabled) return null;

  return <SocialSignInButtons {...props} />;
}

function SocialSignInButtons({
  mode = 'account',
  onSignedIn,
  onProof,
}: {
  mode?: 'account' | 'proof';
  onSignedIn?: () => void;
  onProof?: (proof: IdentityProof) => void;
}) {
  const signIn = useIdentitySignIn();
  const [appleAvailable, setAppleAvailable] = useState(false);
  const [busy, setBusy] = useState(false);

  const [request, response, promptGoogle] = Google.useIdTokenAuthRequest({
    clientId: process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID || 'disabled',
    webClientId: process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID || 'disabled',
    iosClientId: process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID || 'disabled',
    androidClientId: process.env.EXPO_PUBLIC_GOOGLE_ANDROID_CLIENT_ID || 'disabled',
  });

  const finish = async (provider: 'google' | 'apple', idToken: string, name: string | null) => {
    if (mode === 'proof') {
      onProof?.({ id_token: idToken, provider });
      return;
    }

    setBusy(true);
    try {
      await signIn.mutateAsync({ provider, id_token: idToken, name });
      onSignedIn?.();
    } catch (cause) {
      const message = cause instanceof ApiError ? cause.message : 'That sign-in could not be verified. Try again.';
      showAlert('Could not sign in', message);
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    if (Platform.OS !== 'ios') return;
    AppleAuthentication.isAvailableAsync().then(setAppleAvailable).catch(() => setAppleAvailable(false));
  }, []);

  useEffect(() => {
    if (response?.type !== 'success') return;
    const token = response.params.id_token;
    if (typeof token !== 'string' || token === '') return;
    void finish('google', token, null);
  }, [response]);

  const apple = async () => {
    try {
      const credential = await AppleAuthentication.signInAsync({
        requestedScopes: [
          AppleAuthentication.AppleAuthenticationScope.FULL_NAME,
          AppleAuthentication.AppleAuthenticationScope.EMAIL,
        ],
      });
      if (!credential.identityToken) {
        showAlert('Could not sign in', 'That sign-in could not be verified. Try again.');
        return;
      }
      const given = [credential.fullName?.givenName, credential.fullName?.familyName].filter(Boolean).join(' ');
      await finish('apple', credential.identityToken, given || null);
    } catch (cause) {
      const code = cause && typeof cause === 'object' && 'code' in cause ? String(cause.code) : '';
      if (code === 'ERR_REQUEST_CANCELED') return;
    }
  };

  const showApple = Platform.OS === 'ios' && appleAvailable;
  const googleClientId = Platform.select({
    ios: process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID,
    android: process.env.EXPO_PUBLIC_GOOGLE_ANDROID_CLIENT_ID,
    default: process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID,
  });

  return (
    <View style={{ gap: space.xs }}>
      {showApple ? (
        <Button label="Continue with Apple" tone="secondary" onPress={apple} disabled={busy} />
      ) : null}
      <Button
        label={busy ? 'Signing in…' : 'Continue with Google'}
        tone="secondary"
        onPress={() => {
          promptGoogle().catch((cause: unknown) => {
            const type = cause && typeof cause === 'object' && 'type' in cause ? String(cause.type) : '';
            if (type === 'cancel' || type === 'dismiss') return;
          });
        }}
        disabled={!request || !googleClientId || busy}
      />
    </View>
  );
}
