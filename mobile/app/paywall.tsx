import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Linking, Platform, Pressable, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useEntitlement, useLogout, useProfile } from '../src/api/hooks';
import { BRAND } from '../src/brand';
import { Icon } from '../src/components/Icon';
import { NativeSubscriptionActions } from '../src/components/NativeSubscriptionActions';
import { Button, Card, Gutter, Loading, Note, Row, Screen, T } from '../src/components/primitives';
import { confirmAction, showAlert } from '../src/lib/alert';
import { FALLBACK_PRICE_LABEL, isNativeStorePlatform } from '../src/lib/billing';
import { PRIVACY_POLICY_URL, STORE_NAME, TERMS_URL } from '../src/lib/legal';
import { useSession } from '../src/store/session';
import { radius, space, useTheme } from '../src/theme';

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
  const logout = useLogout();

  /* A guest cannot own a receipt, which is the whole reason the purchase
     waits for an account rather than offering itself to anyone. */
  const signedIn = profile ? profile.is_guest === false : false;
  const accountEmail = profile?.account?.email?.trim() || null;
  const pending = entitlement?.state === 'pending';

  const signOut = () => {
    confirmAction('Sign out?', 'Sign in with another account to use a different month on this phone.', {
      confirmLabel: 'Sign out',
      cancelLabel: 'Stay signed in',
      destructive: true,
      onConfirm: async () => {
        try {
          await logout.mutateAsync();
        } catch {
          /* hook alerts */
        }
        /* This device is a guest now whether or not the server heard us, and
           a guest cannot Restore or Subscribe — login is the only way on. */
        router.replace('/account/login');
      },
    });
  };

  /* The store's localized price once it loads; the fixed label only until
     then, and on web where there is no store to ask. */
  const [storePrice, setStorePrice] = useState<string | null>(null);
  const price = storePrice ?? FALLBACK_PRICE_LABEL;

  /* Onboarding leaves a journey but no trip — the trip is the paid part.
     Ready is the screen that creates it and builds the plan, so a month
     bought straight after setup goes there rather than to an empty Home. */
  const enterApp = useCallback(() => {
    const { journeyId, tripId } = useSession.getState();
    router.replace(journeyId && !tripId ? '/onboarding/ready' : '/(tabs)');
  }, [router]);

  /* Fresh purchase / restore: celebrate before entering the product. */
  const showConfirmation = useCallback(
    (confirmationEmailed: boolean) => {
      /* The price the store showed, so the confirmation agrees with the charge. */
      router.replace({
        pathname: '/subscription/confirmed',
        params: { price, emailed: confirmationEmailed ? '1' : '0' },
      });
    },
    [router, price],
  );

  /* Skip the auto-enter when Subscribe / Restore already routed to the
     confirmation screen — otherwise refetch would race and skip it. */
  const confirmingPurchase = useRef(false);

  /* Already paid while this screen was open — a restore landing, or a
     notification arriving. Do not make them tap anything.

     In an effect, because navigating is a side effect: called while
     rendering it warns, builds the rest of this screen anyway, and fires
     twice under StrictMode. */
  const entitled = entitlement?.can_use ?? false;

  useEffect(() => {
    if (entitled && !confirmingPurchase.current) enterApp();
  }, [entitled, enterApp]);

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
        <T variant="displayL">{BRAND.name} is {price} a month</T>
        <T variant="bodyL" color={colors.text.secondary}>
          Setting up your trip is free. Finding what is worth doing, asking the guide, building the
          plan and booking it need a month.
        </T>
      </Gutter>

      <Gutter style={{ marginTop: space.lg }}>
        <Card tone="warm">
          <View style={{ padding: space.md, gap: space.sm }}>
            {[
              `${price} for one month`,
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
        ) : signedIn && isNativeStorePlatform() ? (
          <NativeSubscriptionActions
            priceLabel={price}
            pending={pending}
            isRefetching={isRefetching}
            onStorePrice={setStorePrice}
            onEntitled={(confirmationEmailed) => {
              confirmingPurchase.current = true;
              void refetch();
              showConfirmation(confirmationEmailed);
            }}
          />
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

      {/* Show which WAYOA account owns Restore / Subscribe. Without this,
         a second phone looks "stuck" on the paywall when it is simply signed
         into the wrong account — and there is no You tab to Sign out from. */}
      {signedIn ? (
        <Gutter style={{ marginTop: space.xl, gap: space.xs }}>
          <T variant="caption" color={colors.text.tertiary}>
            {accountEmail ? `Signed in as ${accountEmail}` : 'Signed in'}
          </T>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Sign out"
            onPress={signOut}
            disabled={logout.isPending}
            style={{ paddingVertical: space.xs, alignSelf: 'flex-start' }}
          >
            <T variant="small" color={colors.action.primary}>
              {logout.isPending ? 'Signing out…' : 'Sign out'}
            </T>
          </Pressable>
        </Gutter>
      ) : null}

      {/* The way out stays open. A paywall that traps someone with no way to
          delete their account is a rejection on both stores. */}
      <Gutter style={{ marginTop: signedIn ? space.md : space.xl, marginBottom: space.xxl }}>
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
