import React from 'react';
import { Alert, Linking, Pressable, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';

import { useExperience, useMarkComplete, useToggleSave } from '../../src/api/hooks';
import type { Freshness, Offer } from '../../src/api/types';
import {
  Artwork,
  Button,
  Card,
  Chip,
  Loading,
  Note,
  Row,
  ScoreBadge,
  Screen,
  SectionHeader,
  T,
} from '../../src/components/primitives';
import { clock, minutesLabel, titleCase } from '../../src/lib/format';
import { radius, space, useTheme } from '../../src/theme';

/** Spec s7 — the experience detail page. */
export default function ExperienceScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const colors = useTheme();
  const router = useRouter();

  const { data, isLoading, isError, error } = useExperience(id!);
  const toggleSave = useToggleSave(id!);
  const markComplete = useMarkComplete(id!);

  if (isLoading) {
    return (
      <Screen>
        <Loading />
      </Screen>
    );
  }

  if (isError || !data) {
    return (
      <Screen>
        <Note tone="warn">{error instanceof Error ? error.message : 'Could not load this experience.'}</Note>
        <Button label="Back" tone="secondary" onPress={() => router.back()} style={{ marginTop: space.lg }} />
      </Screen>
    );
  }

  const { descriptive, dynamic, location, signals, explanation } = data;
  const openNow = dynamic.opening_hours.open_now;

  return (
    <Screen contentStyle={{ padding: 0 }}>
      <Artwork id={data.id} category={descriptive.categories[0]?.key} height={190} />

      <Pressable
        onPress={() => router.back()}
        style={{ position: 'absolute', top: space.md, left: space.lg }}
        hitSlop={12}
      >
        <View
          style={{
            width: 36,
            height: 36,
            borderRadius: 18,
            backgroundColor: 'rgba(0,0,0,0.45)',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <T variant="bodyStrong" color="#FFFFFF">
            ←
          </T>
        </View>
      </Pressable>

      <View style={{ padding: space.lg }}>
        <Row style={{ alignItems: 'flex-start', gap: space.md }}>
          <View style={{ flex: 1 }}>
            <T variant="display">{data.title}</T>
            <Row gap={space.sm} wrap style={{ marginTop: 6 }}>
              {descriptive.neighbourhood && (
                <T variant="small" color={colors.inkMuted}>
                  {descriptive.neighbourhood}
                </T>
              )}
              <T variant="small" color={colors.inkFaint}>·</T>
              <T variant="small" color={colors.inkMuted}>
                {minutesLabel(descriptive.expected_duration_minutes)}
              </T>
              <T variant="small" color={colors.inkFaint}>·</T>
              <T variant="small" color={colors.inkMuted}>
                {titleCase(descriptive.weather_exposure)}
              </T>
            </Row>
          </View>
          {data.experience_score !== null && <ScoreBadge score={data.experience_score} />}
        </Row>

        <Row gap={space.sm} wrap style={{ marginTop: space.md }}>
          {descriptive.categories.map((category) => (
            <Chip key={category.key} label={category.label} />
          ))}
        </Row>

        {/* Why this scores what it scores (spec s6.2). */}
        {explanation && (
          <>
            <SectionHeader title="Why this score for you" />
            <Card>
              <View style={{ padding: space.lg, gap: space.sm }}>
                {explanation.positive.map((reason) => (
                  <Row key={reason} gap={space.sm} style={{ alignItems: 'flex-start' }}>
                    <T variant="bodyStrong" color={colors.positive}>
                      +
                    </T>
                    <T variant="small" style={{ flex: 1 }}>
                      {reason}
                    </T>
                  </Row>
                ))}
                {explanation.negative.map((reason) => (
                  <Row key={reason} gap={space.sm} style={{ alignItems: 'flex-start' }}>
                    <T variant="bodyStrong" color={colors.inkFaint}>
                      −
                    </T>
                    <T variant="small" color={colors.inkMuted} style={{ flex: 1 }}>
                      {reason}
                    </T>
                  </Row>
                ))}
              </View>
            </Card>
          </>
        )}

        {/* Facts that change, each with where it came from. */}
        <SectionHeader title="Right now" />
        <Card>
          <View style={{ padding: space.lg, gap: space.lg }}>
            <FactRow
              label="Price"
              value={dynamic.price_from.is_free ? 'Free' : (dynamic.price_from.value?.formatted ?? 'Not verified')}
              freshness={dynamic.price_from.freshness}
            />
            <FactRow
              label="Open now"
              value={
                !dynamic.opening_hours.known
                  ? 'Hours not verified'
                  : openNow
                    ? dynamic.opening_hours.closes_at
                      ? `Yes — closes ${clock(dynamic.opening_hours.closes_at)}`
                      : 'Yes'
                    : 'Closed'
              }
              freshness={dynamic.opening_hours.freshness}
            />
            {dynamic.rating && (
              <FactRow
                label="Rating"
                value={`${dynamic.rating.value.toFixed(1)} from ${dynamic.rating.count.toLocaleString()} reviews`}
                freshness={dynamic.rating.freshness}
              />
            )}
            {dynamic.requires_booking && (
              <Note tone="warn">
                Usually needs booking ahead
                {dynamic.booking_lead_time_hours ? ` — about ${dynamic.booking_lead_time_hours} hours` : ''}.
              </Note>
            )}
          </View>
        </Card>

        <SectionHeader title="Why it matters" />
        <T variant="body" color={colors.ink}>
          {descriptive.why_it_matters}
        </T>

        {descriptive.best_for && (
          <>
            <SectionHeader title="Best for" />
            <T variant="body" color={colors.inkMuted}>
              {descriptive.best_for}
            </T>
          </>
        )}

        {descriptive.know_before_you_go.length > 0 && (
          <>
            <SectionHeader title="Know before you go" />
            <Card>
              <View style={{ padding: space.lg, gap: space.sm }}>
                {descriptive.know_before_you_go.map((line) => (
                  <T key={line} variant="small" color={colors.inkMuted}>
                    · {line}
                  </T>
                ))}
              </View>
            </Card>
          </>
        )}

        {descriptive.traveller_tip && (
          <>
            <SectionHeader title="Traveller tip" />
            <Note tone="good">{descriptive.traveller_tip}</Note>
          </>
        )}

        {descriptive.what_to_wear && (
          <>
            <SectionHeader title="What to wear" />
            <T variant="body" color={colors.inkMuted}>
              {descriptive.what_to_wear}
            </T>
          </>
        )}

        {/* Spec s6.4 — transparent signals, not a "tourist trap" label. */}
        <SectionHeader title="Signals" />
        <Card>
          <View style={{ padding: space.lg, gap: space.md }}>
            <Meter label="Distinctive for this city" value={signals.uniqueness} />
            <Meter label="Value for money" value={signals.value_for_money} />
            <Meter label="Tourist concentration" value={signals.tourist_concentration} inverted />
            <Meter label="Queue risk" value={signals.queue_risk} inverted />
          </View>
        </Card>

        <SectionHeader title="Accessibility" />
        <Card>
          <View style={{ padding: space.lg, gap: space.sm }}>
            {Object.entries(data.accessibility.claims).length === 0 ? (
              <T variant="small" color={colors.inkMuted}>
                No accessibility information has been verified for this yet.
              </T>
            ) : (
              Object.entries(data.accessibility.claims).map(([key, value]) => (
                <Row key={key} style={{ justifyContent: 'space-between' }}>
                  <T variant="small" color={colors.inkMuted}>
                    {titleCase(key)}
                  </T>
                  <T variant="small">{String(value)}</T>
                </Row>
              ))
            )}
            <T variant="small" color={colors.inkFaint}>
              {data.accessibility.note}
              {data.accessibility.source ? ` Source: ${data.accessibility.source}.` : ''}
            </T>
          </View>
        </Card>

        {dynamic.offers.length > 0 && (
          <>
            <SectionHeader title="Tickets" />
            {dynamic.offers.map((offer) => (
              <OfferCard key={`${offer.provider}-${offer.provider_product_id}`} offer={offer} />
            ))}
          </>
        )}

        {Object.keys(data.related).length > 0 && (
          <>
            <SectionHeader title="Combine it with" />
            {Object.entries(data.related).map(([type, items]) => (
              <View key={type} style={{ marginBottom: space.md }}>
                <T variant="small" color={colors.inkFaint} style={{ marginBottom: space.xs }}>
                  {titleCase(type)}
                </T>
                <Row gap={space.sm} wrap>
                  {items.map((item) => (
                    <Chip key={item.id} label={item.title} onPress={() => router.push(`/experience/${item.id}`)} />
                  ))}
                </Row>
              </View>
            ))}
          </>
        )}

        {location && (
          <>
            <SectionHeader title="Getting there" />
            <Card>
              <View style={{ padding: space.lg, gap: space.sm }}>
                <T variant="bodyStrong">{location.name}</T>
                {location.address && (
                  <T variant="small" color={colors.inkMuted}>
                    {location.address}
                  </T>
                )}
                <Button
                  label="Open directions"
                  tone="secondary"
                  onPress={() => Linking.openURL(location.directions_url)}
                  style={{ marginTop: space.sm }}
                />
              </View>
            </Card>
          </>
        )}

        <Row gap={space.md} style={{ marginTop: space.xl }}>
          <Button
            label={data.is_saved ? 'Saved' : 'Save'}
            tone={data.is_saved ? 'secondary' : 'primary'}
            onPress={() => toggleSave.mutate(data.is_saved)}
            style={{ flex: 1 }}
          />
          <Button
            label={data.is_completed ? 'Done' : 'I did this'}
            tone="secondary"
            onPress={() => markComplete.mutate()}
            disabled={data.is_completed}
            style={{ flex: 1 }}
          />
        </Row>

        <T variant="small" color={colors.inkFaint} style={{ marginTop: space.xl, textAlign: 'center' }}>
          Content source: {data.data_source}
        </T>
      </View>
    </Screen>
  );
}

function FactRow({ label, value, freshness }: { label: string; value: string; freshness: Freshness }) {
  const colors = useTheme();

  return (
    <View style={{ gap: 2 }}>
      <Row style={{ justifyContent: 'space-between' }}>
        <T variant="small" color={colors.inkMuted}>
          {label}
        </T>
        <T variant="bodyStrong">{value}</T>
      </Row>
      <T variant="small" color={freshness.is_stale ? colors.warn : colors.inkFaint} style={{ textAlign: 'right' }}>
        {freshness.source ? `${freshness.label} · ${freshness.source}` : 'Not verified'}
      </T>
    </View>
  );
}

function OfferCard({ offer }: { offer: Offer }) {
  const colors = useTheme();

  return (
    <Card style={{ marginBottom: space.sm }}>
      <View style={{ padding: space.lg, gap: space.sm }}>
        <Row style={{ justifyContent: 'space-between' }}>
          <T variant="bodyStrong" style={{ flex: 1 }} numberOfLines={2}>
            {offer.title}
          </T>
          <T variant="bodyStrong" color={colors.accent}>
            {offer.price_from?.formatted ?? '—'}
          </T>
        </Row>

        <T variant="small" color={colors.inkMuted}>
          {offer.fulfilment === 'native'
            ? 'Bookable here, with live availability.'
            : 'Checkout completes on the supplier’s own site.'}
        </T>

        {offer.cancellation_policy && (
          <T variant="small" color={colors.inkFaint}>
            {offer.cancellation_policy}
          </T>
        )}

        {offer.degraded && <Note tone="warn">This supplier is not responding, so its price may be out of date.</Note>}

        <Button
          label={offer.fulfilment === 'native' ? 'Check availability' : 'Continue to supplier'}
          tone="secondary"
          onPress={() =>
            Alert.alert(
              'Booking',
              offer.fulfilment === 'native'
                ? `Live availability comes from ${offer.provider}. Native checkout is wired end to end in the API (POST /bookings with an Idempotency-Key).`
                : `You would be handed to ${offer.provider} to complete payment. Their cancellation terms apply.`,
            )
          }
        />
      </View>
    </Card>
  );
}

function Meter({ label, value, inverted }: { label: string; value: number; inverted?: boolean }) {
  const colors = useTheme();
  const good = inverted ? value <= 40 : value >= 65;

  return (
    <View style={{ gap: 6 }}>
      <Row style={{ justifyContent: 'space-between' }}>
        <T variant="small" color={colors.inkMuted}>
          {label}
        </T>
        <T variant="small" color={good ? colors.positive : colors.inkMuted}>
          {value}
        </T>
      </Row>
      <View style={{ height: 6, borderRadius: 3, backgroundColor: colors.surfaceAlt, overflow: 'hidden' }}>
        <View
          style={{
            width: `${Math.max(2, Math.min(100, value))}%`,
            height: 6,
            borderRadius: 3,
            backgroundColor: good ? colors.positive : colors.inkFaint,
          }}
        />
      </View>
    </View>
  );
}
