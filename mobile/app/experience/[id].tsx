import React, { useMemo, useState } from 'react';
import { ActivityIndicator, Alert, Linking, Pressable, ScrollView, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';

import {
  newIdempotencyKey,
  recordEvents,
  useAvailability,
  useCreateBooking,
  useExperience,
  useMarkComplete,
  useToggleSave,
} from '../../src/api/hooks';
import type { AvailabilitySlot, Freshness, Offer } from '../../src/api/types';
import { ScoreBadge, ScoreExplanation } from '../../src/components/ExperienceScore';
import { Icon, type IconName } from '../../src/components/Icon';
import { Photo } from '../../src/components/Photo';
import { Sheet } from '../../src/components/Sheet';
import { openingStatus, StatusPill } from '../../src/components/StatusPill';
import {
  Button,
  Card,
  Chip,
  Divider,
  Gutter,
  Loading,
  Note,
  Row,
  SectionHeader,
  T,
} from '../../src/components/primitives';
import { toFitReasons, WhyThisFits } from '../../src/components/WhyThisFits';
import { clock, minutesLabel, titleCase } from '../../src/lib/format';
import { elevation, radius, space, TOUCH_TARGET, useTheme } from '../../src/theme';
import { useBackTo } from '../../src/lib/navigation';

/**
 * Experience detail (Figma: 20).
 *
 * Immersive hero, then the reasoning, then the facts — in that order, because
 * "why this suits you" is the thing this product has that a directory does not.
 * Live context and descriptive content stay visually separate so a traveller
 * can tell what is current from what is merely true in general.
 */
export default function ExperienceScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const colors = useTheme();
  const router = useRouter();
  const goBack = useBackTo('/(tabs)');
  const insets = useSafeAreaInsets();

  const { data, isLoading, isError, error } = useExperience(id!);
  const toggleSave = useToggleSave(id!);
  const markComplete = useMarkComplete(id!);

  const [explaining, setExplaining] = useState(false);
  const [expanded, setExpanded] = useState<string | null>(null);

  if (isLoading) {
    return (
      <SafeAreaView style={{ flex: 1, backgroundColor: colors.background.base }}>
        <Loading />
      </SafeAreaView>
    );
  }

  if (isError || !data) {
    return (
      <SafeAreaView style={{ flex: 1, backgroundColor: colors.background.base }}>
        <Gutter style={{ paddingTop: space.lg }}>
          <Note tone="warning">{error instanceof Error ? error.message : 'Could not load this experience.'}</Note>
          <Button label="Back" tone="secondary" onPress={goBack} style={{ marginTop: space.md }} />
        </Gutter>
      </SafeAreaView>
    );
  }

  const { descriptive, dynamic, location, signals, explanation } = data;
  const status = openingStatus(dynamic.opening_hours.known, dynamic.opening_hours.open_now, dynamic.opening_hours.closes_at);

  /* Adaptive CTA (Figma: 20) — what this place actually needs next. */
  const primaryCta = dynamic.offers.length > 0
    ? { label: 'Check tickets', onPress: () => setExpanded('offers') }
    : { label: 'Add to my day', onPress: () => router.push('/(tabs)/trip') };

  return (
    <View style={{ flex: 1, backgroundColor: colors.background.base }}>
      <ScrollView contentContainerStyle={{ paddingBottom: 120 }} showsVerticalScrollIndicator={false}>
        {/* ── Hero ───────────────────────────────────────────────────── */}
        <Photo
          id={data.id}
          uri={descriptive.image_url}
          attribution={descriptive.image_attribution}
          category={descriptive.categories[0]?.key}
          height={320}
          overlay
        >
          <View style={{ flex: 1, justifyContent: 'space-between', paddingTop: insets.top + space.xs }}>
            <Row justify="space-between" style={{ paddingHorizontal: space.md }}>
              <CircleButton icon="back" onPress={goBack} label="Back" />
              <Row gap={space.xs}>
                <CircleButton
                  icon={data.is_saved ? 'saved' : 'save'}
                  onPress={() => toggleSave.mutate(data.is_saved)}
                  label={data.is_saved ? 'Remove from saved' : 'Save'}
                  active={data.is_saved}
                />
                <CircleButton icon="share" onPress={() => Alert.alert('Share', 'Sharing is not wired up yet.')} label="Share" />
              </Row>
            </Row>

            <View style={{ padding: space.lg, gap: 6 }}>
              {descriptive.neighbourhood ? (
                <Row gap={5}>
                  <Icon name="location" size={14} color="rgba(255,255,255,0.85)" />
                  <T variant="smallStrong" color="rgba(255,255,255,0.85)">
                    {descriptive.neighbourhood}
                    {descriptive.destination ? ` · ${descriptive.destination}` : ''}
                  </T>
                </Row>
              ) : null}
              <T variant="displayL" color="#FFFFFF">
                {data.title}
              </T>
            </View>
          </View>
        </Photo>

        {/* ── Score + external rating ────────────────────────────────── */}
        <Gutter style={{ marginTop: space.lg }}>
          <Card level="card">
            <View style={{ padding: space.md, gap: space.md }}>
              {data.experience_score !== null ? (
                <ScoreBadge score={data.experience_score} size="large" onExplain={() => setExplaining(true)} />
              ) : null}

              {dynamic.rating ? (
                <>
                  <Divider />
                  <Row justify="space-between">
                    <View>
                      <T variant="label" color={colors.text.tertiary}>
                        Elsewhere
                      </T>
                      <T variant="small" color={colors.text.secondary}>
                        {dynamic.rating.value.toFixed(1)} from {dynamic.rating.count.toLocaleString()} reviews
                      </T>
                    </View>
                    <T variant="caption" color={colors.text.tertiary}>
                      {sourceLabel(dynamic.rating.freshness.source)}
                    </T>
                  </Row>
                </>
              ) : null}
            </View>
          </Card>
        </Gutter>

        {/* ── Categories ─────────────────────────────────────────────── */}
        <Gutter style={{ marginTop: space.md }}>
          <Row gap={space.xs} wrap>
            {descriptive.categories.map((category) => (
              <Chip key={category.key} label={category.label} size="small" />
            ))}
          </Row>
        </Gutter>

        {/* ── Why you'll like it ─────────────────────────────────────── */}
        {explanation && explanation.positive.length > 0 ? (
          <Gutter>
            <SectionHeader title="Why you'll like it" />
            <WhyThisFits reasons={toFitReasons(explanation.positive)} title="For this trip" tone="warm" />
          </Gutter>
        ) : null}

        {/* ── Live context ───────────────────────────────────────────── */}
        <Gutter>
          <SectionHeader title="Right now" caption="Facts that change, with where they came from" />
          <Card>
            <View style={{ padding: space.md, gap: space.md }}>
              <Row justify="space-between" align="center">
                <StatusPill kind={status} />
                {dynamic.opening_hours.closes_at && status !== 'closed' ? (
                  <T variant="small" color={colors.text.secondary}>
                    Closes {clock(dynamic.opening_hours.closes_at)}
                  </T>
                ) : null}
              </Row>

              <Divider />

              <Fact
                label="Price"
                value={dynamic.price_from.is_free ? 'Free' : (dynamic.price_from.value?.formatted ?? 'Not verified')}
                freshness={dynamic.price_from.freshness}
                icon="money"
              />
              <Fact
                label="Opening hours"
                value={dynamic.opening_hours.known ? 'Published' : 'Not verified'}
                freshness={dynamic.opening_hours.freshness}
                icon="clock"
              />

              {dynamic.requires_booking ? (
                <Note tone="warning" icon={<Icon name="ticket" size={16} color={colors.status.warning} />}>
                  Usually needs booking ahead
                  {dynamic.booking_lead_time_hours ? ` — about ${dynamic.booking_lead_time_hours} hours` : ''}.
                </Note>
              ) : null}
            </View>
          </Card>
        </Gutter>

        {/* ── Quick facts ────────────────────────────────────────────── */}
        <Gutter>
          <SectionHeader title="Quick facts" />
          <Row gap={space.xs} wrap>
            <FactTile icon="clock" label="Duration" value={minutesLabel(descriptive.expected_duration_minutes)} />
            <FactTile icon="weather" label="Setting" value={titleCase(descriptive.weather_exposure)} />
            <FactTile icon="walk" label="Effort" value={titleCase(descriptive.energy_level)} />
            {descriptive.best_time_of_day.length > 0 ? (
              <FactTile icon="camera" label="Best time" value={titleCase(descriptive.best_time_of_day[0])} />
            ) : null}
          </Row>
        </Gutter>

        {/* ── Why it matters ─────────────────────────────────────────── */}
        <Gutter>
          <SectionHeader title="Why it matters" />
          <T variant="bodyL">{descriptive.why_it_matters}</T>
          {descriptive.best_for ? (
            <View style={{ marginTop: space.md }}>
              <T variant="label" color={colors.text.tertiary}>
                Best for
              </T>
              <T variant="body" color={colors.text.secondary}>
                {descriptive.best_for}
              </T>
            </View>
          ) : null}
        </Gutter>

        {/* ── Know before you go ─────────────────────────────────────── */}
        {descriptive.know_before_you_go.length > 0 ? (
          <Gutter>
            <SectionHeader title="Know before you go" />
            <Card>
              <View style={{ padding: space.md, gap: space.sm }}>
                {descriptive.know_before_you_go.map((line) => (
                  <Row key={line} align="flex-start" gap={space.xs}>
                    <Icon name="info" size={15} color={colors.text.tertiary} />
                    <T variant="small" color={colors.text.secondary} style={{ flex: 1 }}>
                      {line}
                    </T>
                  </Row>
                ))}
                {descriptive.what_to_wear ? (
                  <>
                    <Divider />
                    <Row align="flex-start" gap={space.xs}>
                      <Icon name="person" size={15} color={colors.text.tertiary} />
                      <T variant="small" color={colors.text.secondary} style={{ flex: 1 }}>
                        {descriptive.what_to_wear}
                      </T>
                    </Row>
                  </>
                ) : null}
              </View>
            </Card>
          </Gutter>
        ) : null}

        {descriptive.traveller_tip ? (
          <Gutter>
            <SectionHeader title="Traveller tip" />
            <Note tone="success" icon={<Icon name="sparkle" size={16} color={colors.status.open} />}>
              {descriptive.traveller_tip}
            </Note>
          </Gutter>
        ) : null}

        {/* ── Signals (not a "tourist trap" label) ───────────────────── */}
        <Gutter>
          <SectionHeader title="Signals" caption="Transparent measures rather than a verdict" />
          <Card>
            <View style={{ padding: space.md, gap: space.md }}>
              <Meter label="Distinctive for this city" value={signals.uniqueness} />
              <Meter label="Value for money" value={signals.value_for_money} />
              <Meter label="Tourist concentration" value={signals.tourist_concentration} inverted />
              <Meter label="Queue risk" value={signals.queue_risk} inverted />
            </View>
          </Card>
        </Gutter>

        {/* ── Accessibility ──────────────────────────────────────────── */}
        <Gutter>
          <SectionHeader title="Accessibility" />
          <Card>
            <View style={{ padding: space.md, gap: space.xs }}>
              {Object.entries(data.accessibility.claims).length === 0 ? (
                <T variant="small" color={colors.text.secondary}>
                  No accessibility information has been verified for this yet.
                </T>
              ) : (
                Object.entries(data.accessibility.claims).map(([key, value]) => (
                  <Row key={key} justify="space-between">
                    <T variant="small" color={colors.text.secondary}>
                      {titleCase(key)}
                    </T>
                    <T variant="smallStrong">{String(value)}</T>
                  </Row>
                ))
              )}
              <T variant="caption" color={colors.text.tertiary}>
                {data.accessibility.note}
                {data.accessibility.source ? ` Source: ${data.accessibility.source}.` : ''}
              </T>
            </View>
          </Card>
        </Gutter>

        {/* ── Offers ─────────────────────────────────────────────────── */}
        {dynamic.offers.length > 0 ? (
          <Gutter>
            <SectionHeader title="Tickets" caption="Provider-neutral — differences shown plainly" />
            {dynamic.offers.map((offer) => (
              <OfferCard
                key={`${offer.provider}-${offer.provider_product_id}`}
                offer={offer}
                experienceId={data.id}
              />
            ))}
          </Gutter>
        ) : null}

        {/* ── Combine with ───────────────────────────────────────────── */}
        {Object.keys(data.related).length > 0 ? (
          <Gutter>
            <SectionHeader title="Combine it with" caption="Close by, and sensible in sequence" />
            {Object.entries(data.related).map(([type, items]) => (
              <View key={type} style={{ marginBottom: space.md }}>
                <T variant="label" color={colors.text.tertiary} style={{ marginBottom: space.xs }}>
                  {titleCase(type)}
                </T>
                <Row gap={space.xs} wrap>
                  {items.map((item) => (
                    <Chip key={item.id} label={item.title} size="small" onPress={() => router.push(`/experience/${item.id}`)} />
                  ))}
                </Row>
              </View>
            ))}
          </Gutter>
        ) : null}

        {/* ── Getting there ──────────────────────────────────────────── */}
        {location ? (
          <Gutter>
            <SectionHeader title="Getting there" />
            <Card>
              <View style={{ padding: space.md, gap: space.sm }}>
                <T variant="bodyStrong">{location.name}</T>
                {location.address ? (
                  <T variant="small" color={colors.text.secondary}>
                    {location.address}
                  </T>
                ) : null}
                <Button
                  label="Open directions"
                  tone="secondary"
                  size="small"
                  icon={<Icon name="directions" size={16} color={colors.text.primary} />}
                  onPress={() => Linking.openURL(location.directions_url)}
                  style={{ alignSelf: 'flex-start', marginTop: space.xs }}
                />
              </View>
            </Card>
          </Gutter>
        ) : null}

        {/* ── Sources ────────────────────────────────────────────────── */}
        <Gutter>
          <SectionHeader title="Where this came from" />
          <Card tone="sunken" bordered={false} level="none">
            <View style={{ padding: space.md, gap: space.xs }}>
              {data.sources.length === 0 ? (
                <T variant="small" color={colors.text.secondary}>
                  Written in-house.
                </T>
              ) : (
                data.sources.map((source) => (
                  <Pressable
                    key={source.kind}
                    disabled={!source.url}
                    onPress={() => source.url && Linking.openURL(source.url)}
                  >
                    <Row justify="space-between" gap={space.sm}>
                      <T variant="small" color={colors.text.tertiary}>
                        {titleCase(source.kind)}
                      </T>
                      <T
                        variant="small"
                        color={source.url ? colors.action.primary : colors.text.primary}
                        style={{ flex: 1 }}
                        align="right"
                        numberOfLines={2}
                      >
                        {source.name}
                      </T>
                    </Row>
                  </Pressable>
                ))
              )}
            </View>
          </Card>
        </Gutter>
      </ScrollView>

      {/* ── Sticky adaptive CTA ────────────────────────────────────────── */}
      <View
        style={{
          position: 'absolute',
          left: 0,
          right: 0,
          bottom: 0,
          paddingHorizontal: space.lg,
          paddingTop: space.sm,
          paddingBottom: insets.bottom + space.sm,
          backgroundColor: colors.background.elevated,
          borderTopWidth: 1,
          borderTopColor: colors.border.subtle,
          ...elevation.sheet,
        }}
      >
        <Row gap={space.xs}>
          <Button
            label={data.is_saved ? 'Saved' : 'Save'}
            tone="secondary"
            onPress={() => toggleSave.mutate(data.is_saved)}
            icon={<Icon name={data.is_saved ? 'saved' : 'save'} size={17} color={colors.text.primary} />}
            style={{ flex: 1 }}
          />
          <Button label={primaryCta.label} onPress={primaryCta.onPress} haptic="medium" style={{ flex: 1.4 }} />
        </Row>
      </View>

      {/* ── Score explanation (Figma: 12) ──────────────────────────────── */}
      <Sheet visible={explaining} onClose={() => setExplaining(false)}>
        {explanation ? (
          <ScoreExplanation
            score={explanation.score}
            positives={explanation.positive}
            considerations={explanation.negative}
          />
        ) : null}
      </Sheet>

      {/* ── Offers sheet ───────────────────────────────────────────────── */}
      <Sheet visible={expanded === 'offers'} onClose={() => setExpanded(null)} title="Ticket options">
        <View style={{ gap: space.sm }}>
          {dynamic.offers.map((offer) => (
            <OfferCard
              key={`sheet-${offer.provider}-${offer.provider_product_id}`}
              offer={offer}
              experienceId={data.id}
            />
          ))}
          {!data.is_completed ? (
            <Button
              label="I did this"
              tone="secondary"
              onPress={() => {
                markComplete.mutate();
                setExpanded(null);
              }}
            />
          ) : null}
        </View>
      </Sheet>
    </View>
  );
}

/** Provider keys are internal. Travellers get the name of the actual source. */
function sourceLabel(source: string | null): string {
  if (!source) return 'Not verified';

  const names: Record<string, string> = {
    google_places: 'Google',
    osm: 'OpenStreetMap',
    wikimedia: 'Wikimedia',
    seed_demo: 'Editorial',
    sandbox: 'Sandbox supplier',
    deeplink: 'Partner',
    viator: 'Viator',
    open_meteo: 'Open-Meteo',
    seeded_forecast: 'Simulated forecast',
    osrm: 'OSRM',
    estimator: 'Estimated',
  };

  return names[source] ?? source.replace(/_/g, ' ');
}

function CircleButton({
  icon,
  onPress,
  label,
  active,
}: {
  icon: IconName;
  onPress: () => void;
  label: string;
  active?: boolean;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      onPress={onPress}
      hitSlop={6}
      style={({ pressed }) => ({
        width: TOUCH_TARGET,
        height: TOUCH_TARGET,
        borderRadius: TOUCH_TARGET / 2,
        backgroundColor: 'rgba(13,27,42,0.55)',
        alignItems: 'center',
        justifyContent: 'center',
        opacity: pressed ? 0.75 : 1,
      })}
    >
      <Icon name={icon} size={20} color={active ? '#FF6B5E' : '#FFFFFF'} strokeWidth={1.9} />
    </Pressable>
  );
}

function Fact({
  label,
  value,
  freshness,
  icon,
}: {
  label: string;
  value: string;
  freshness: Freshness;
  icon: IconName;
}) {
  const colors = useTheme();

  return (
    <View style={{ gap: 2 }}>
      <Row justify="space-between">
        <Row gap={6}>
          <Icon name={icon} size={15} color={colors.text.tertiary} />
          <T variant="small" color={colors.text.secondary}>
            {label}
          </T>
        </Row>
        <T variant="bodyStrong">{value}</T>
      </Row>
      <T variant="caption" color={freshness.is_stale ? colors.status.warning : colors.text.tertiary} align="right">
        {freshness.source ? `${freshness.label} · ${sourceLabel(freshness.source)}` : 'Not verified'}
      </T>
    </View>
  );
}

function FactTile({ icon, label, value }: { icon: IconName; label: string; value: string }) {
  const colors = useTheme();

  return (
    <View
      style={{
        flexGrow: 1,
        minWidth: '46%',
        backgroundColor: colors.background.elevated,
        borderWidth: 1,
        borderColor: colors.border.subtle,
        borderRadius: radius.control,
        padding: space.sm,
        gap: 4,
      }}
    >
      <Row gap={6}>
        <Icon name={icon} size={15} color={colors.text.tertiary} />
        <T variant="caption" color={colors.text.tertiary}>
          {label}
        </T>
      </Row>
      <T variant="bodyStrong">{value}</T>
    </View>
  );
}

function slotStartsAt(slot: AvailabilitySlot): string {
  const time = slot.start_time && /^\d{2}:\d{2}/.test(slot.start_time)
    ? slot.start_time.slice(0, 5)
    : '11:30';

  return `${slot.date}T${time}:00`;
}

function OfferCard({ offer, experienceId }: { offer: Offer; experienceId: string }) {
  const colors = useTheme();
  const router = useRouter();
  const createBooking = useCreateBooking();
  const [picking, setPicking] = useState(false);
  const [busy, setBusy] = useState(false);

  const availability = useAvailability(experienceId, picking && offer.fulfilment === 'native');
  const slots = useMemo(() => {
    const row = availability.data?.find(
      (item) =>
        item.provider === offer.provider && item.provider_product_id === offer.provider_product_id,
    );

    return row?.slots ?? [];
  }, [availability.data, offer.provider, offer.provider_product_id]);

  const book = async (startsAt?: string | null) => {
    const key = newIdempotencyKey();
    setBusy(true);
    recordEvents([{ type: 'booking_started', subject_type: 'experience', subject_id: experienceId, properties: { provider: offer.provider } }]);

    try {
      const booking = await createBooking.mutateAsync({
        provider: offer.provider,
        provider_product_id: offer.provider_product_id,
        quantity: 1,
        starts_at: startsAt ?? undefined,
        idempotency_key: key,
      });

      recordEvents([
        {
          type: 'booking_confirmed',
          subject_type: 'experience',
          subject_id: experienceId,
          properties: { provider: offer.provider, state: booking.state, reference: booking.reference },
        },
      ]);

      setPicking(false);

      if (booking.redirect_url) {
        await Linking.openURL(booking.redirect_url);
      }

      Alert.alert(
        booking.state === 'confirmed' ? 'Booked' : 'Continue with the supplier',
        booking.state === 'confirmed'
          ? `Reference ${booking.reference}. Your tickets are in Bookings.`
          : `Reference ${booking.reference}. Finish payment with ${offer.provider}, then return to Bookings.`,
        [
          { text: 'Stay here', style: 'cancel' },
          { text: 'Open Bookings', onPress: () => router.push('/(tabs)/bookings') },
        ],
      );
    } catch (cause) {
      recordEvents([
        {
          type: 'booking_failed',
          subject_type: 'experience',
          subject_id: experienceId,
          properties: { provider: offer.provider, message: cause instanceof Error ? cause.message : 'unknown' },
        },
      ]);
    } finally {
      setBusy(false);
    }
  };

  const onPress = async () => {
    if (offer.degraded) {
      Alert.alert('Supplier unavailable', 'This supplier is not responding right now. Try another option or come back shortly.');
      return;
    }

    if (offer.fulfilment === 'redirect') {
      await book(null);
      return;
    }

    setPicking(true);
  };

  return (
    <Card style={{ marginBottom: space.sm }}>
      <View style={{ padding: space.md, gap: space.sm }}>
        <Row justify="space-between" align="flex-start" gap={space.sm}>
          <T variant="bodyStrong" style={{ flex: 1 }} numberOfLines={2}>
            {offer.title}
          </T>
          <T variant="h3" color={colors.action.primary}>
            {offer.price_from?.formatted ?? '—'}
          </T>
        </Row>

        <Row gap={space.xs} wrap>
          <StatusPill
            kind={offer.fulfilment === 'native' ? 'available' : 'unknown'}
            label={offer.fulfilment === 'native' ? 'Book here' : 'Supplier checkout'}
          />
          <T variant="caption" color={colors.text.tertiary}>
            {offer.provider}
          </T>
        </Row>

        {offer.cancellation_policy ? (
          <T variant="small" color={colors.text.secondary}>
            {offer.cancellation_policy}
          </T>
        ) : null}

        {offer.degraded ? (
          <Note tone="warning">This supplier is not responding, so its price may be out of date.</Note>
        ) : null}

        <Button
          label={
            busy
              ? 'Working…'
              : offer.fulfilment === 'native'
                ? 'Check availability'
                : 'Continue to supplier'
          }
          tone="secondary"
          size="small"
          onPress={onPress}
          disabled={busy}
          style={{ alignSelf: 'flex-start' }}
        />
      </View>

      <Sheet
        visible={picking}
        onClose={() => !busy && setPicking(false)}
        title="Choose a time"
      >
        <View style={{ gap: space.sm }}>
          {availability.isLoading ? (
            <ActivityIndicator color={colors.action.primary} />
          ) : null}

          {availability.isError ? (
            <Note tone="warning">
              {availability.error instanceof Error
                ? availability.error.message
                : 'Could not load availability.'}
            </Note>
          ) : null}

          {!availability.isLoading && !availability.isError && slots.length === 0 ? (
            <Note tone="warning">
              No live slots from {offer.provider} in the next few days. Nothing was booked.
            </Note>
          ) : null}

          {slots.map((slot) => {
            const startsAt = slotStartsAt(slot);
            const label = `${slot.date}${slot.start_time ? ` · ${slot.start_time.slice(0, 5)}` : ''}`;

            return (
              <Button
                key={`${slot.date}-${slot.start_time ?? 'any'}`}
                label={slot.price ? `${label} · ${slot.price.formatted}` : label}
                tone="secondary"
                onPress={() => book(startsAt)}
                disabled={busy}
              />
            );
          })}
        </View>
      </Sheet>
    </Card>
  );
}

function Meter({ label, value, inverted }: { label: string; value: number; inverted?: boolean }) {
  const colors = useTheme();
  const good = inverted ? value <= 40 : value >= 65;

  return (
    <View style={{ gap: 6 }}>
      <Row justify="space-between">
        <T variant="small" color={colors.text.secondary}>
          {label}
        </T>
        <T variant="smallStrong" color={good ? colors.status.open : colors.text.secondary}>
          {value}
        </T>
      </Row>
      <View style={{ height: 6, borderRadius: 3, backgroundColor: colors.background.sunken, overflow: 'hidden' }}>
        <View
          style={{
            width: `${Math.max(2, Math.min(100, value))}%`,
            height: 6,
            borderRadius: 3,
            backgroundColor: good ? colors.status.open : colors.text.tertiary,
          }}
        />
      </View>
    </View>
  );
}
