import React from 'react';
import { Linking, Platform, Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useEntitlement } from '../src/api/hooks';
import { BRAND } from '../src/brand';
import { Icon } from '../src/components/Icon';
import { Button, Card, Gutter, Loading, Note, Row, Screen, T } from '../src/components/primitives';
import { showAlert } from '../src/lib/alert';
import { PRIVACY_POLICY_URL, STORE_NAME, SUBSCRIPTION_SETTINGS_URL, TERMS_URL } from '../src/lib/legal';
import { useBackTo } from '../src/lib/navigation';
import { space, useTheme } from '../src/theme';

const PRICE = process.env.EXPO_PUBLIC_SUBSCRIPTION_PRICE ?? '£1.99';

/** "3 November 2026" — the day the month runs out, in the reader's own locale. */
function longDate(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
}

/**
 * The subscription screen (Subscription JEM §11 P5).
 *
 * Pausing is part of the product, and the one thing this screen has to get
 * right is that asking to stop never shortens the month already bought. So
 * the date comes first and the consequence second: you have it until the
 * third, and after that it is off until another month is paid for.
 *
 * It does not invent a pause. Play owns the real one — the traveller picks
 * the length there and Play resumes billing itself — and Apple has none at
 * all. An app that offered its own pause button would be describing something
 * neither store would then do.
 */
export default function Subscription() {
  const colors = useTheme();
  const router = useRouter();
  const goBack = useBackTo('/(tabs)/you');
  const { data: entitlement, isLoading, refetch, isRefetching } = useEntitlement();

  const header = (
    <Gutter style={{ paddingTop: space.sm }}>
      <Pressable accessibilityRole="button" onPress={goBack} hitSlop={12} accessibilityLabel="Back">
        <Icon name="back" size={24} color={colors.text.primary} />
      </Pressable>
      <T variant="displayL" style={{ marginTop: space.sm }}>
        Your subscription
      </T>
    </Gutter>
  );

  if (isLoading) {
    return (
      <Screen>
        {header}
        <Loading />
      </Screen>
    );
  }

  const state = entitlement?.state ?? 'inactive';
  const paidUntil = entitlement?.paid_until ?? null;
  const ending = state === 'active_until_period_end';

  /* Nothing to manage. Sending them to the paywall is the honest answer —
     this screen would otherwise describe a subscription that is not there. */
  if (!entitlement?.can_use && !paidUntil) {
    return (
      <Screen>
        {header}
        <Gutter style={{ gap: space.md }}>
          <Note>You do not have a month running.</Note>
          <Button label="See what a month costs" onPress={() => router.push('/paywall')} />
        </Gutter>
      </Screen>
    );
  }

  const openSettings = async () => {
    try {
      await Linking.openURL(SUBSCRIPTION_SETTINGS_URL);
    } catch {
      showAlert(`Could not open ${STORE_NAME}`, SUBSCRIPTION_SETTINGS_URL);
    }
  };

  return (
    <Screen>
      {header}

      <Gutter style={{ marginTop: space.md }}>
        <Card tone="warm">
          <View style={{ padding: space.md, gap: space.xs }}>
            <T variant="h3">
              {PRICE} a month
            </T>
            {paidUntil ? (
              <T variant="bodyL">
                You have {BRAND.name} until {longDate(paidUntil)}.
              </T>
            ) : null}
            <T variant="small" color={colors.text.secondary}>
              Billed by {STORE_NAME}
              {entitlement?.auto_renew ? ' · renews automatically' : ' · will not renew'}
            </T>
          </View>
        </Card>
      </Gutter>

      {ending ? (
        <Gutter style={{ marginTop: space.md }}>
          <Note icon={<Icon name="clock" size={16} color={colors.text.secondary} />}>
            After that it {Platform.OS === 'android' ? 'pauses' : 'stops'} and you will not be
            charged. It stays off until a paid month is active again.
          </Note>
        </Gutter>
      ) : null}

      {state === 'paused' ? (
        <Gutter style={{ marginTop: space.md }}>
          <Note tone="warning">
            Your subscription is paused.
            {entitlement?.pause_resumes_at
              ? ` Play will try to charge again on ${longDate(entitlement.pause_resumes_at)}.`
              : ''}{' '}
            {BRAND.name} stays off until that charge goes through.
          </Note>
        </Gutter>
      ) : null}

      <Gutter style={{ marginTop: space.lg, gap: space.sm }}>
        {Platform.OS === 'web' ? (
          <Note>
            Subscriptions are managed on the phone you bought it on — in your Apple or Google
            account.
          </Note>
        ) : (
          <>
            <Button
              label={Platform.OS === 'android' ? `Manage or pause in ${STORE_NAME}` : 'Stop next renewal'}
              size="large"
              onPress={openSettings}
            />

            {/* The two platforms genuinely differ here, and saying so is the
                difference between a traveller who knows what will happen and
                one who finds out when nothing turns back on. */}
            <T variant="small" color={colors.text.secondary}>
              {Platform.OS === 'android'
                ? `Pause lives in ${STORE_NAME}, where you choose how long. Play stops billing for that time and tries again when it ends — if that charge works, ${BRAND.name} comes back by itself.`
                : `Apple has no pause. Stopping the next renewal keeps the month you have paid for, but ${BRAND.name} will not turn itself back on — using it again means buying another month.`}
            </T>
          </>
        )}

        <Button
          label={isRefetching ? 'Checking…' : 'Refresh'}
          tone="secondary"
          disabled={isRefetching}
          onPress={() => refetch()}
        />
      </Gutter>

      {/* The review notes tell a reviewer deletion is here, so it is here.
          Both stores check that an account can be ended from inside the app,
          and a paywalled traveller must not be trapped with a subscription
          screen that offers no way out. */}
      <Gutter style={{ marginTop: space.lg }}>
        <Pressable
          accessibilityRole="button"
          onPress={() => router.push('/account/delete')}
          hitSlop={8}
        >
          <T variant="small" color={colors.text.tertiary}>
            Delete your account
          </T>
        </Pressable>
      </Gutter>

      <Gutter style={{ marginTop: space.lg, marginBottom: space.xxl }}>
        <Row gap={space.md}>
          <Pressable
            accessibilityRole="button"
            onPress={() =>
              Linking.openURL(TERMS_URL).catch(() => showAlert('Could not open the terms', TERMS_URL))
            }
          >
            <T variant="small" color={colors.action.primary}>
              Terms of use
            </T>
          </Pressable>
          <Pressable
            accessibilityRole="button"
            onPress={() =>
              Linking.openURL(PRIVACY_POLICY_URL).catch(() =>
                showAlert('Could not open the policy', PRIVACY_POLICY_URL),
              )
            }
          >
            <T variant="small" color={colors.action.primary}>
              Privacy policy
            </T>
          </Pressable>
        </Row>
      </Gutter>
    </Screen>
  );
}
