import React, { useEffect, useMemo, useState } from 'react';
import { useLocalSearchParams, useRouter } from 'expo-router';

import { ApiError } from '../../src/api/client';
import { useSendVerificationEmail, useVerifyEmail } from '../../src/api/hooks';
import { WayoaLogo } from '../../src/components/WayoaLogo';
import { Button, Gutter, Note, Screen, T } from '../../src/components/primitives';
import { showAlert } from '../../src/lib/alert';
import { space, useTheme } from '../../src/theme';

function firstParam(value: string | string[] | undefined): string {
  if (Array.isArray(value)) return value[0] ?? '';
  return value ?? '';
}

export default function VerifyEmail() {
  const colors = useTheme();
  const router = useRouter();
  const params = useLocalSearchParams<{ id?: string | string[]; hash?: string | string[] }>();
  const verify = useVerifyEmail();
  const resend = useSendVerificationEmail();

  const id = useMemo(() => Number(firstParam(params.id)), [params.id]);
  const hash = useMemo(() => firstParam(params.hash), [params.hash]);

  const [error, setError] = useState<string | null>(null);
  const [verified, setVerified] = useState(false);
  const [resent, setResent] = useState(false);

  useEffect(() => {
    if (!Number.isFinite(id) || id < 1 || hash === '') return;

    let cancelled = false;

    void (async () => {
      try {
        await verify.mutateAsync({ id, hash });
        if (!cancelled) setVerified(true);
      } catch (cause) {
        if (cancelled) return;
        const message =
          cause instanceof ApiError
            ? cause.message
            : cause instanceof Error
              ? cause.message
              : 'Could not confirm that email.';
        setError(message);
      }
    })();

    return () => {
      cancelled = true;
    };
    // Only when the deep-link params change — not when the mutation object identity does.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [hash, id]);

  const sendAgain = async () => {
    setError(null);
    try {
      await resend.mutateAsync();
      setResent(true);
    } catch (cause) {
      const message =
        cause instanceof ApiError
          ? cause.message
          : cause instanceof Error
            ? cause.message
            : 'Could not resend the email.';
      setError(message);
      showAlert('Could not resend', message);
    }
  };

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.sm, gap: space.md }}>
        <WayoaLogo variant="full" size="md" />
        <T variant="displayL">{verified ? 'Email confirmed' : 'Confirm your email'}</T>
        <T variant="body" color={colors.text.secondary}>
          {verified
            ? 'Thanks — this address is confirmed. Password resets and subscription receipts can reach you here.'
            : 'We sent a confirmation link to the address you registered with. Open it on this phone, or tap resend if it has not arrived.'}
        </T>

        {resent && !verified ? (
          <Note tone="info">Another confirmation email is on its way.</Note>
        ) : null}

        {error ? <Note tone="warning">{error}</Note> : null}

        {verified ? (
          <Button label="Continue" onPress={() => router.replace('/(tabs)/you')} />
        ) : (
          <>
            <Button
              label={resend.isPending ? 'Sending…' : 'Resend confirmation'}
              onPress={sendAgain}
              disabled={resend.isPending || verify.isPending}
            />
            <Button
              label="Continue for now"
              tone="secondary"
              onPress={() => router.replace('/(tabs)/you')}
            />
          </>
        )}
      </Gutter>
    </Screen>
  );
}
