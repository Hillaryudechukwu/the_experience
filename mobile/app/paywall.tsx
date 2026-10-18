import React from 'react';
import { Linking, Platform, Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useEntitlement, useProfile } from '../src/api/hooks';
import { BRAND } from '../src/brand';
import { Icon } from '../src/components/Icon';
import { Button, Card, Gutter, Loading, Note, Row, Screen, T } from '../src/components/primitives';
import { showAlert } from '../src/lib/alert';
import { PRIVACY_POLICY_URL, STORE_NAME, TERMS_URL } from '../src/lib/legal';
import { radius, space, useTheme } from '../src/theme';

const PRICE = process.env.EXPO_PUBLIC_SUBSCRIPTION_PRICE ?? '£1.99';

/**
 * The paywall (Subscription JEM §11 P4).
 *
 * Setup is free; the product is not. This is what stands between the two, and
 * it has to say what a month costs and what happens afterwards *before* the
 * store sheet appears — both reviewers check for exactly that.
 *
 * It never decides anything. `can_use` comes from the server, and the screen
 * exists so a traveller meets a price rather than a 402.
 */
export default function Paywall() {
  const colors = useTheme();
  const router = useRouter();
  const { data: entitlement, isLoading, refetch, isRefetching } = useEntitlement();
  const { data: profile } = useProfile();

  /* A guest cannot own a receipt, which is the whole reason the purchase
     waits for an account rather than offering itself to anyone. */
  const signedIn = profile ? profile.is_guest === false : false;
  const pending = entitlement?.state === 'pending';

  /* Already paid while this screen was open — a restore landing, or a
     notification arriving. Do not make them tap anything. */
  if (entitlement?.can_use) {
    router.replace('/(tabs)');
  }

  const openUrl = async (url: string, failure: string) => {
    try {
      await Linking.openURL(url);
    } catch {
      showAlert(failure, url);
    }
  };

  if (isLoading) {
    return (
      <Screen scroll={false} contentStyle={{ justifyContent: 'center' }}>
        <Loading />
      </Screen>
    );
  }

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.xxl, gap: space.sm }}>
        <Icon name="sparkle" size={28} color={colors.action.primary} strokeWidth={1.8} />
        <T variant="displayL">{BRAND.name} is {PRICE} a month</T>
        <T variant="bodyL" color={colors.text.secondary}>
          Setting up your trip is free. Finding what is worth doing, asking the guide, building the
          plan and booking it need a month.
        </T>
      </Gutter>

      <Gutter style={{ marginTop: space.lg }}>
        <Card tone="warm">
          <View style={{ padding: space.md, gap: space.sm }}>
            {[
              `${PRICE} for one month`,
              'Renews until you pause or cancel',
              'The month you have paid for always runs to its end',
            ].map((line) => (
              <Row key={line} gap={space.xs} align="flex-start">
                <Icon name="check" size={16} color={colors.status.open} />
                <T variant="body" style={{ flex: 1 }}>
                  {line}
                </T>
              </Row>
            ))}
          </View>
        </Card>
      </Gutter>

      {pending ? (
        <Gutter style={{ marginTop: space.md }}>
          <Note icon={<Icon name="clock" size={16} color={colors.text.secondary} />}>
            Still confirming your payment with {STORE_NAME}. This usually takes a moment — the app
            opens as soon as the charge goes through.
          </Note>
        </Gutter>
      ) : null}

      <Gutter style={{ marginTop: space.lg, gap: space.sm }}>
        {Platform.OS === 'web' ? (
          /* No purchase on web: the stores are the merchant of record, and
             neither sells through a browser. Saying so is better than a
             button that cannot work. */
          <Note>
            Subscribe in the {BRAND.name} app on iPhone or Android. Your month works everywhere once
            it is active, including here.
          </Note>
        ) : signedIn ? (
          <>
            <Button
              label={pending ? 'Waiting for the store…' : `Subscribe — ${PRICE} a month`}
              size="large"
              disabled={pending}
              onPress={() =>
                showAlert(
                  'Not available in this build',
                  'In-app purchase needs a store build. This is the paywall; the purchase arrives with it.',
                )
              }
              haptic="medium"
            />
            <Button
              label={isRefetching ? 'Checking…' : 'Restore a subscription'}
              tone="secondary"
              disabled={isRefetching}
              onPress={() => refetch()}
            />
          </>
        ) : (
          <>
            {/* A receipt belongs to an account. Without one there is nothing to
                restore onto, and nothing stopping a second device claiming the
                same month. */}
            <Note>Sign in first, so your month follows you to a new phone.</Note>
            <Button label="Sign in" size="large" onPress={() => router.push('/account/login')} />
            <Button
              label="Create an account"
              tone="secondary"
              onPress={() => router.push('/account/register')}
            />
          </>
        )}
      </Gutter>

      {entitlement?.paid_until ? (
        <Gutter style={{ marginTop: space.md }}>
          <Button
            label="Your subscription"
            tone="secondary"
            onPress={() => router.push('/subscription')}
          />
        </Gutter>
      ) : null}

      <Gutter style={{ marginTop: space.lg }}>
        <T variant="caption" color={colors.text.tertiary}>
          Billed by {STORE_NAME}. Cancel any time in your {STORE_NAME} account — the month you have
          paid for keeps working until it ends.
        </T>
        <Row gap={space.md} style={{ marginTop: space.sm }}>
          <Pressable
            accessibilityRole="button"
            onPress={() => openUrl(TERMS_URL, 'Could not open the terms')}
          >
            <T variant="small" color={colors.action.primary}>
              Terms of use
            </T>
          </Pressable>
          <Pressable
            accessibilityRole="button"
            onPress={() => openUrl(PRIVACY_POLICY_URL, 'Could not open the policy')}
          >
            <T variant="small" color={colors.action.primary}>
              Privacy policy
            </T>
          </Pressable>
        </Row>
      </Gutter>

      {/* The way out stays open. A paywall that traps someone with no way to
          delete their account is a rejection on both stores. */}
      <Gutter style={{ marginTop: space.xl, marginBottom: space.xxl }}>
        <Pressable
          accessibilityRole="button"
          onPress={() => router.push('/account/delete')}
          style={{ padding: space.sm, borderRadius: radius.control, alignSelf: 'flex-start' }}
        >
          <T variant="small" color={colors.text.tertiary}>
            Delete your account
          </T>
        </Pressable>
      </Gutter>
    </Screen>
  );
}
