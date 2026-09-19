import React, { useMemo, useState } from 'react';
import { ScrollView, View } from 'react-native';
import { useRouter } from 'expo-router';

import { useDiscovery } from '../../src/api/hooks';
import type { ExperienceCard } from '../../src/api/types';
import { ScoreRing } from '../../src/components/ExperienceScore';
import { Icon } from '../../src/components/Icon';
import { MapCanvas } from '../../src/components/MapCanvas';
import { Photo } from '../../src/components/Photo';
import { Sheet } from '../../src/components/Sheet';
import { Button, Chip, Gutter, Loading, Note, Row, Screen, T } from '../../src/components/primitives';
import { toFitReasons, WhyThisFits } from '../../src/components/WhyThisFits';
import { useSession } from '../../src/store/session';
import { radius, scoreBand, space, useTheme } from '../../src/theme';

const FILTERS = [
  { key: 'must_experience', label: 'Must see' },
  { key: 'hidden_gem', label: 'Hidden gems' },
  { key: 'free', label: 'Free' },
  { key: 'food_experience', label: 'Food' },
  { key: 'culture', label: 'Culture' },
  { key: 'family', label: 'Kids' },
];

/**
 * Map (Figma: 21).
 *
 * An exploration surface rather than an afterthought. Pins carry the Experience
 * Score so the map answers "which of these is worth my afternoon" at a glance,
 * and selecting one opens a sheet with the reasoning rather than dumping the
 * traveller straight into a detail page.
 */
export default function MapScreen() {
  const colors = useTheme();
  const router = useRouter();
  const session = useSession();
  const [filters, setFilters] = useState<string[]>([]);
  const [selected, setSelected] = useState<ExperienceCard | null>(null);

  const discovery = useDiscovery('now', { categories: filters, limit: 40 });
  const cards = discovery.data?.data ?? [];

  const centre = useMemo(() => {
    if (session.coords) return session.coords;
    const first = cards.find((card) => card.location);

    return first?.location ? { lat: first.location.lat, lng: first.location.lng } : { lat: 51.5074, lng: -0.1278 };
  }, [cards, session.coords]);

  const markers = useMemo(
    () =>
      cards
        .filter((card) => card.location)
        .map((card) => ({
          id: card.id,
          lat: card.location!.lat,
          lng: card.location!.lng,
          title: card.title,
          score: card.experience_score ?? 0,
          colour: scoreBand(card.experience_score ?? 0, colors).ring,
        })),
    [cards, colors],
  );

  const html = useMemo(
    () => buildHtml(centre, markers, colors.background.base, colors.text.primary),
    [centre, markers, colors.background.base, colors.text.primary],
  );

  return (
    <Screen scroll={false}>
      <Gutter style={{ paddingTop: space.sm, paddingBottom: space.xs }}>
        <T variant="h2">Map</T>
      </Gutter>

      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={{ paddingHorizontal: space.lg, gap: space.xs, paddingBottom: space.xs }}
        style={{ flexGrow: 0 }}
      >
        {FILTERS.map((filter) => (
          <Chip
            key={filter.key}
            label={filter.label}
            selected={filters.includes(filter.key)}
            tone="accent"
            size="small"
            onPress={() =>
              setFilters((current) =>
                current.includes(filter.key) ? current.filter((f) => f !== filter.key) : [...current, filter.key],
              )
            }
          />
        ))}
      </ScrollView>

      {discovery.isLoading ? (
        <Loading label="Placing pins" />
      ) : markers.length === 0 ? (
        <Gutter>
          <Note>Nothing to plot yet. Pick a city on the Discover tab first.</Note>
        </Gutter>
      ) : (
        <MapCanvas
          html={html}
          background={colors.background.base}
          onSelect={(id) => setSelected(cards.find((card) => card.id === id) ?? null)}
        />
      )}

      {/* ── Selected pin sheet ─────────────────────────────────────────── */}
      <Sheet visible={!!selected} onClose={() => setSelected(null)}>
        {selected ? (
          <View style={{ gap: space.md }}>
            <View style={{ borderRadius: radius.card, overflow: 'hidden' }}>
              <Photo
                id={selected.id}
                uri={selected.image_url}
                attribution={selected.image_attribution}
                category={selected.categories[0]?.key}
                height={150}
              />
            </View>

            <Row justify="space-between" align="flex-start" gap={space.sm}>
              <View style={{ flex: 1, gap: 4 }}>
                <T variant="h2" numberOfLines={2}>
                  {selected.title}
                </T>
                {selected.location?.neighbourhood ? (
                  <Row gap={5}>
                    <Icon name="location" size={14} color={colors.text.tertiary} />
                    <T variant="small" color={colors.text.secondary}>
                      {selected.location.neighbourhood}
                    </T>
                  </Row>
                ) : null}
              </View>
              {selected.experience_score !== null ? <ScoreRing score={selected.experience_score} size="medium" /> : null}
            </Row>

            <Row gap={space.md} wrap>
              {selected.travel ? (
                <Row gap={5}>
                  <Icon name={selected.travel.mode === 'walk' ? 'walk' : 'transit'} size={15} color={colors.text.tertiary} />
                  <T variant="small" color={colors.text.secondary}>
                    {selected.travel.minutes} min away
                  </T>
                </Row>
              ) : null}
              <Row gap={5}>
                <Icon name="clock" size={15} color={colors.text.tertiary} />
                <T variant="small" color={colors.text.secondary}>
                  {selected.duration_minutes} min
                </T>
              </Row>
              <Row gap={5}>
                <Icon name="money" size={15} color={colors.text.tertiary} />
                <T variant="small" color={selected.is_free ? colors.status.open : colors.text.secondary}>
                  {selected.is_free ? 'Free' : (selected.price_from?.formatted ?? 'Price not verified')}
                </T>
              </Row>
            </Row>

            {selected.why.length > 0 ? <WhyThisFits reasons={toFitReasons(selected.why.slice(0, 3))} /> : null}

            <Button
              label="Open experience"
              onPress={() => {
                const id = selected.id;
                setSelected(null);
                router.push(`/experience/${id}`);
              }}
            />
          </View>
        ) : null}
      </Sheet>
    </Screen>
  );
}

type Marker = { id: string; lat: number; lng: number; title: string; score: number; colour: string };

function buildHtml(centre: { lat: number; lng: number }, markers: Marker[], background: string, ink: string): string {
  return `<!doctype html>
<html>
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"/>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<style>
  html, body, #map { margin:0; padding:0; height:100%; background:${background}; }
  .pin { border-radius:999px; color:#fff;
         font:600 12px Inter,-apple-system,system-ui,sans-serif;
         width:34px; height:34px; display:flex; align-items:center; justify-content:center;
         box-shadow:0 3px 10px rgba(13,27,42,.34); border:2.5px solid #fff; }
  .leaflet-control-attribution { font-size:9px; color:${ink}99; }
</style>
</head>
<body>
<div id="map"></div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  var map = L.map('map', { zoomControl: false, attributionControl: true })
    .setView([${centre.lat}, ${centre.lng}], 13);

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  var markers = ${JSON.stringify(markers)};
  var bounds = [];

  markers.forEach(function (m) {
    var icon = L.divIcon({
      className: '',
      html: '<div class="pin" style="background:' + m.colour + '">' + m.score + '</div>',
      iconSize: [34, 34],
      iconAnchor: [17, 17]
    });

    L.marker([m.lat, m.lng], { icon: icon })
      .addTo(map)
      .on('click', function () {
        window.ReactNativeWebView
          ? window.ReactNativeWebView.postMessage(m.id)
          : window.parent.postMessage(m.id, '*');
      });

    bounds.push([m.lat, m.lng]);
  });

  if (bounds.length > 1) map.fitBounds(bounds, { padding: [44, 44] });
</script>
</body>
</html>`;
}
