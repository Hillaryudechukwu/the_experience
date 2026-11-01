import { Platform } from 'react-native';

/** Store product ids — must match SUBSCRIPTION_JEM §6 and console products. */
export const APPLE_PRODUCT_ID = process.env.EXPO_PUBLIC_APPLE_PRODUCT_ID ?? 'wayoa.monthly';
export const GOOGLE_PRODUCT_ID = process.env.EXPO_PUBLIC_GOOGLE_PRODUCT_ID ?? 'wayoa_monthly';

export function storeProductId(): string {
  return Platform.OS === 'ios' ? APPLE_PRODUCT_ID : GOOGLE_PRODUCT_ID;
}

export function isNativeStorePlatform(): boolean {
  return Platform.OS === 'ios' || Platform.OS === 'android';
}
