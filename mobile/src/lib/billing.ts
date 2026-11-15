import { Platform } from 'react-native';

/** Store product ids — must match SUBSCRIPTION_JEM §6 and console products. */
export const APPLE_PRODUCT_ID = process.env.EXPO_PUBLIC_APPLE_PRODUCT_ID ?? 'wayoa.monthly';
export const GOOGLE_PRODUCT_ID = process.env.EXPO_PUBLIC_GOOGLE_PRODUCT_ID ?? 'wayoa_monthly';

/**
 * What a month costs before the store has said. The store's own localized
 * price replaces it as soon as products load — a fixed £ label shown to a US
 * or EU storefront would disagree with the sheet that charges them.
 */
export const FALLBACK_PRICE_LABEL = process.env.EXPO_PUBLIC_SUBSCRIPTION_PRICE ?? '£1.99';

export function storeProductId(): string {
  return Platform.OS === 'ios' ? APPLE_PRODUCT_ID : GOOGLE_PRODUCT_ID;
}

export function isNativeStorePlatform(): boolean {
  return Platform.OS === 'ios' || Platform.OS === 'android';
}
