import React, { useMemo, useRef, useState } from 'react';
import { Platform, View } from 'react-native';
import { useRouter } from 'expo-router';

import { MapCanvas } from '../../src/components/MapCanvas';

import { useDiscovery } from '../../src/api/hooks';
import { Chip, Loading, Note, Row, Screen, T } from '../../src/components/primitives';
import { useSession } from '../../src/store/session';
import { scoreBand, space, useTheme } from '../../src/theme';

const FILTERS = [
  { key: 'must_experience', label: 'Must see' },
  { key: 'hidden_gem', label: 'Hidden gems' },
  { key: 'free', label: 'Free' },
  { key: 'food_experience', label: 'Food' },
  { key: 'culture', label: 'Culture' },
  { key: 'family', label: 'Kids' },
];

/**
 * Map discovery (spec s17.3).
 *
 * Rendered with Leaflet over OpenStreetMap inside a WebView: no API key, no
 * native map module, and it runs in Expo Go as well as a dev build. Pins carry
 * the Experience Score, price and saved state.
 */
export default function MapScreen() {
  const colors = useTheme();
  const router = useRouter();
  const session = useSession();
  const [filters, setFilters] = useState<string[]>([]);

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
          price: card.is_free ? 'Free' : (card.price_from?.formatted ?? 'Price not verified'),
          travel: card.travel ? `${card.travel.minutes} min` : '',
          colour: scoreBand(card.experience_score ?? 0, colors).fg,
        })),
    [cards, colors],
  );

  const html = useMemo(() => buildHtml(centre, markers, colors.bg), [centre, markers, colors.bg]);

  return (
    <Screen scroll={false}>
      <View style={{ paddingHorizontal: space.lg, paddingTop: space.sm, gap: space.sm }}>
        <T variant="title">Map</T>
        <Row gap={space.sm} wrap>
          {FILTERS.map((filter) => (
            <Chip
              key={filter.key}
              label={filter.label}
              selected={filters.includes(filter.key)}
              tone="accent"
              onPress={() =>
                setFilters((current) =>
                  current.includes(filter.key) ? current.filter((f) => f !== filter.key) : [...current, filter.key],
                )
              }
            />
          ))}
        </Row>
      </View>

      {discovery.isLoading ? (
        <Loading label="Placing pins" />
      ) : markers.length === 0 ? (
        <View style={{ padding: space.lg }}>
          <Note>Nothing to plot yet. Pick a city on the Today tab first.</Note>
        </View>
      ) : (
        <MapCanvas html={html} onSelect={(id) => router.push(`/experience/${id}`)} background={colors.bg} />
      )}
    </Screen>
  );
}

type Marker = {
  id: string;
  lat: number;
  lng: number;
  title: string;
  score: number;
  price: string;
  travel: string;
  colour: string;
};

function buildHtml(centre: { lat: number; lng: number }, markers: Marker[], background: string): string {
  return `<!doctype html>
<html>
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"/>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<style>
  html, body, #map { margin:0; padding:0; height:100%; background:${background}; }
  .pin { border-radius:999px; color:#fff; font:700 12px -apple-system,system-ui,sans-serif;
         width:34px; height:34px; display:flex; align-items:center; justify-content:center;
         box-shadow:0 2px 8px rgba(0,0,0,.35); border:2px solid rgba(255,255,255,.9); }
  .leaflet-popup-content { font:14px -apple-system,system-ui,sans-serif; margin:10px 12px; }
  .leaflet-popup-content b { display:block; margin-bottom:2px; }
  .leaflet-popup-content small { color:#666; }
  .leaflet-popup-content a { display:inline-block; margin-top:6px; color:#B4531F; font-weight:600; text-decoration:none; }
</style>
</head>
<body>
<div id="map"></div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  var map = L.map('map', { zoomControl: false }).setView([${centre.lat}, ${centre.lng}], 13);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  var markers = ${JSON.stringify(markers)};
  var group = [];

  markers.forEach(function (m) {
    var icon = L.divIcon({
      className: '',
      html: '<div class="pin" style="background:' + m.colour + '">' + m.score + '</div>',
      iconSize: [34, 34],
      iconAnchor: [17, 17]
    });
    var marker = L.marker([m.lat, m.lng], { icon: icon }).addTo(map);
    marker.bindPopup(
      '<b>' + m.title + '</b><small>' + m.price + (m.travel ? ' · ' + m.travel : '') + '</small>' +
      '<a href="#" onclick="window.ReactNativeWebView.postMessage(\\'' + m.id + '\\'); return false;">Open</a>'
    );
    group.push([m.lat, m.lng]);
  });

  if (group.length > 1) map.fitBounds(group, { padding: [40, 40] });
</script>
</body>
</html>`;
}
