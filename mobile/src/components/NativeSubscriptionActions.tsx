import React, { useCallback, useEffect, useState } from 'react';
import { Platform } from 'react-native';
import {
  ErrorCode,
  getAvailablePurchases as readStorePurchases,
  useIAP,
  type ProductSubscription,
  type Purchase,
} from 'expo-iap';

import { ApiError } from '../api/client';
import { useRefreshAppleSubscription, useRefreshGoogleSubscription } from '../api/hooks';
import { Button } from './primitives';
import { showAlert } from '../lib/alert';
import { APPLE_PRODUCT_ID, GOOGLE_PRODUCT_ID, storeProductId } from '../lib/billing';

type Props = {
  priceLabel: string;
  pending: boolean;
  isRefetching: boolean;
  onEntitled: () => void;
};

/**
 * StoreKit / Play purchase + restore (Subscription JEM §11 P4).
 *
 * The phone starts the purchase; Laravel decides access after refresh.
 * Web must not mount this — there is no merchant of record in the browser.
 */
export function NativeSubscriptionActions({ priceLabel, pending, isRefetching, onEntitled }: Props) {
  const refreshApple = useRefreshAppleSubscription();
  const refreshGoogle = useRefreshGoogleSubscription();
  const [busy, setBusy] = useState(false);
  const productId = storeProductId();

  /**
   * `claimed` means Laravel accepted and recorded the receipt, even if the
   * month is not open yet (grace, pending). The store transaction is
   * finished on either `claimed` or `entitled` — Play refunds a purchase
   * nobody acknowledges within three days, and StoreKit replays an
   * unfinished one on every launch. Only a refusal leaves it open to retry.
   */
  const verifyOnServer = useCallback(
    async (purchase: Purchase): Promise<'entitled' | 'claimed' | 'refused'> => {
      const token = purchase.purchaseToken;
      if (!token) {
        showAlert('Purchase incomplete', 'The store did not return a receipt to verify.');
        return 'refused';
      }

      try {
        const entitlement =
          Platform.OS === 'ios'
            ? await refreshApple.mutateAsync(token)
            : await refreshGoogle.mutateAsync({
                purchase_token: token,
                product_id: purchase.productId || GOOGLE_PRODUCT_ID,
              });
        if (entitlement.can_use) {
          onEntitled();
          return 'entitled';
        }
        showAlert('Still confirming', 'The charge is recorded; open the app again in a moment.');
        return 'claimed';
      } catch (error) {
        if (error instanceof ApiError && error.status === 409) {
          showAlert(
            'Already linked',
            'This subscription belongs to another WAYOA account. Sign in there, or use a different store account.',
          );
          return 'refused';
        }
        showAlert(
          'Could not confirm',
          error instanceof Error ? error.message : 'The store charge could not be verified yet.',
        );
        return 'refused';
      }
    },
    [onEntitled, refreshApple, refreshGoogle],
  );

  const { connected, subscriptions, fetchProducts, requestPurchase, finishTransaction } = useIAP({
    onPurchaseSuccess: async (purchase) => {
      setBusy(true);
      try {
        if ((await verifyOnServer(purchase)) !== 'refused') {
          await finishTransaction({ purchase, isConsumable: false });
        }
      } finally {
        setBusy(false);
      }
    },
    onPurchaseError: (error) => {
      setBusy(false);
      if (error.code === ErrorCode.UserCancelled) return;
      showAlert('Purchase failed', error.message || 'The store could not complete the purchase.');
    },
  });

  useEffect(() => {
    if (!connected) return;
    fetchProducts({
      skus: Platform.OS === 'ios' ? [APPLE_PRODUCT_ID] : [GOOGLE_PRODUCT_ID],
      type: 'subs',
    }).catch(() => {
      /* Surface on Subscribe tap if products never arrive. */
    });
  }, [connected, fetchProducts]);

  const subscription = subscriptions.find((item) => item.id === productId);

  const subscribe = async () => {
    if (!connected) {
      showAlert(
        'Store not ready',
        'In-app purchase needs a development or store build with Play Billing / StoreKit. Expo Go cannot complete a purchase.',
      );
      return;
    }

    if (!subscription) {
      showAlert(
        'Product unavailable',
        `The store has not returned ${productId} yet. Confirm the product exists in App Store Connect or Play Console.`,
      );
      return;
    }

    setBusy(true);
    try {
      if (Platform.OS === 'android') {
        const offers = androidOffers(subscription);
        if (offers.length === 0) {
          showAlert('Product unavailable', 'Play did not return a subscription offer for this plan.');
          return;
        }
        await requestPurchase({
          request: {
            google: {
              skus: [GOOGLE_PRODUCT_ID],
              subscriptionOffers: offers,
            },
          },
          type: 'subs',
        });
      } else {
        await requestPurchase({
          request: {
            apple: { sku: APPLE_PRODUCT_ID },
          },
          type: 'subs',
        });
      }
    } catch (error) {
      showAlert('Purchase failed', error instanceof Error ? error.message : 'Could not start the purchase.');
    } finally {
      setBusy(false);
    }
  };

  const restore = async () => {
    if (!connected) {
      showAlert('Store not ready', 'Restore needs a store build with an active Play or App Store account.');
      return;
    }

    setBusy(true);
    try {
      const purchases = await readStorePurchases();
      const candidates = purchases.filter(
        (purchase) =>
          purchase.productId === productId
          || purchase.productId === APPLE_PRODUCT_ID
          || purchase.productId === GOOGLE_PRODUCT_ID,
      );

      if (candidates.length === 0) {
        showAlert('Nothing to restore', 'No active WAYOA subscription was found on this store account.');
        return;
      }

      let linked = false;
      for (const purchase of candidates) {
        const result = await verifyOnServer(purchase);
        if (result === 'refused') continue;
        await finishTransaction({ purchase, isConsumable: false });
        linked = true;
        if (result === 'entitled') break;
      }

      if (!linked) {
        showAlert('Could not restore', 'A store purchase was found but could not be linked to this account.');
      }
    } catch (error) {
      showAlert('Could not restore', error instanceof Error ? error.message : 'Restore failed.');
    } finally {
      setBusy(false);
    }
  };

  const disabled = pending || busy || isRefetching;

  return (
    <>
      <Button
        label={pending || busy ? 'Waiting for the store…' : `Subscribe — ${priceLabel} a month`}
        size="large"
        disabled={disabled}
        onPress={subscribe}
        haptic="medium"
      />
      <Button
        label={busy || isRefetching ? 'Checking…' : 'Restore a subscription'}
        tone="secondary"
        disabled={disabled}
        onPress={restore}
      />
    </>
  );
}

function androidOffers(subscription: ProductSubscription): { sku: string; offerToken: string }[] {
  const offers = 'subscriptionOffers' in subscription ? subscription.subscriptionOffers : null;
  if (!Array.isArray(offers)) return [];

  return offers
    .map((offer) => {
      const token = offer?.offerTokenAndroid;
      if (typeof token !== 'string' || token === '') return null;
      return { sku: GOOGLE_PRODUCT_ID, offerToken: token };
    })
    .filter((offer): offer is { sku: string; offerToken: string } => offer !== null);
}
