import React, { useCallback } from 'react';
import { View } from 'react-native';
import { useRouter } from 'expo-router';

import { useEntitlement } from '../../src/api/hooks';
import { BRAND } from '../../src/brand';
import { Icon } from '../../src/components/Icon';
import { Button, Card, Gutter, Row, Screen, T } from '../../src/components/primitives';
import { FALLBACK_PRICE_LABEL } from '../../src/lib/billing';
import { STORE_NAME } from '../../src/lib/legal';
import { useSession } from '../../src/store/session';
import { space, useTheme } from '../../src/theme';

/** "3 November 2026" — the day the month runs out, in the reader's locale. */
function longDate(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
}

/**
 * Purchase / restore succeeded and the server says the month is open.
 *
 * Sits between the paywall and the product so the traveller sees that the
 * charge worked before Home or Ready starts building a plan. A confirmation
 * email goes out from the API on the same claim.
 */
export default function SubscriptionConfirmed() {
  const colors = useTheme();
  const router = useRouter();
  const { data: entitlement } = useEntitlement();
  const price = FALLBACK_PRICE_LABEL;
  const paidUntil = entitlement?.paid_until ? longDate(entitlement.paid_until) : null;

  const continueIntoApp = useCallback(() => {
    const { journeyId, tripId } = useSession.getState();
    router.replace(journeyId && !tripId ? '/onboarding/ready' : '/(tabs)');
  }, [router]);

  return (
    <Screen>
      <Gutter style={{ paddingTop: space.xxl, gap: space.sm }}>
        <Icon name="check" size={28} color={colors.status.open} strokeWidth={2.2} />
        <T variant="displayL">Your month is active</T>
        <T variant="bodyL" color={colors.text.secondary}>
          {BRAND.name} is open on this account
          {paidUntil ? ` until ${paidUntil}` : ''}. A confirmation is on its way to your email.
        </T>
      </Gutter>

      <Gutter style={{ marginTop: space.lg }}>
        <Card tone="warm">
          <View style={{ padding: space.md, gap: space.sm }}>
            {[
              `${price} for one month`,
              `Billed by ${STORE_NAME}`,
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

      <Gutter style={{ marginTop: space.xl, gap: space.sm }}>
        <Button label="Continue" size="large" onPress={continueIntoApp} haptic="medium" />
        <Button
          label="Your subscription"
          tone="secondary"
          onPress={() => router.push('/subscription')}
        />
      </Gutter>

      <Gutter style={{ marginTop: space.lg, marginBottom: space.xxl }}>
        <T variant="caption" color={colors.text.tertiary}>
          Manage or cancel in your {STORE_NAME} account. Deleting your {BRAND.name} account does not
          cancel the store subscription.
        </T>
      </Gutter>
    </Screen>
  );
}
