import React, { useEffect, useRef } from 'react';
import { View } from 'react-native';

import { space } from '../theme';

/** Web build: the same Leaflet document, in an iframe instead of a WebView. */
export function MapCanvas({
  html,
  onSelect,
  background,
}: {
  html: string;
  onSelect: (id: string) => void;
  background: string;
}) {
  const frame = useRef<HTMLIFrameElement | null>(null);

  useEffect(() => {
    const handler = (event: MessageEvent) => {
      if (typeof event.data === 'string' && event.data.length > 0) onSelect(event.data);
    };

    window.addEventListener('message', handler);

    return () => window.removeEventListener('message', handler);
  }, [onSelect]);

  return (
    <View style={{ flex: 1, marginTop: space.md, backgroundColor: background }}>
      {React.createElement('iframe', {
        ref: frame,
        srcDoc: html.replace(/window\.ReactNativeWebView\.postMessage/g, 'window.parent.postMessage'),
        style: { border: 'none', width: '100%', height: '100%' },
        title: 'Map',
      })}
    </View>
  );
}
