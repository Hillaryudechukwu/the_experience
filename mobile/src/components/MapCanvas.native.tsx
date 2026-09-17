import React from 'react';
import { WebView } from 'react-native-webview';

import { space } from '../theme';

/**
 * Leaflet in a WebView.
 *
 * Chosen over a native map SDK so the map works in Expo Go, needs no API key
 * and behaves identically on both platforms.
 */
export function MapCanvas({
  html,
  onSelect,
  background,
}: {
  html: string;
  onSelect: (id: string) => void;
  background: string;
}) {
  return (
    <WebView
      originWhitelist={['*']}
      source={{ html }}
      style={{ flex: 1, marginTop: space.md, backgroundColor: background }}
      onMessage={(event) => {
        const id = event.nativeEvent.data;
        if (id) onSelect(id);
      }}
    />
  );
}
