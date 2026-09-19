import React from 'react';
import Svg, { Circle, Path, Rect } from 'react-native-svg';

import { useTheme } from '../theme';

/**
 * One rounded-line icon family (Figma: 11).
 *
 * Hand-drawn on a 24px grid with a single stroke weight and round caps, so the
 * set reads as one family rather than assembled from wherever. Icons carry
 * meaning only alongside a label — none of these is used on its own where the
 * meaning would be ambiguous.
 */
export type IconName =
  | 'location'
  | 'clock'
  | 'ticket'
  | 'walk'
  | 'transit'
  | 'weather'
  | 'family'
  | 'accessibility'
  | 'food'
  | 'camera'
  | 'calendar'
  | 'money'
  | 'directions'
  | 'save'
  | 'saved'
  | 'share'
  | 'audio'
  | 'guide'
  | 'lock'
  | 'search'
  | 'filter'
  | 'close'
  | 'back'
  | 'chevron'
  | 'plus'
  | 'check'
  | 'compass'
  | 'map'
  | 'trip'
  | 'bookings'
  | 'person'
  | 'sparkle'
  | 'info';

export function Icon({
  name,
  size = 20,
  color,
  strokeWidth = 1.7,
}: {
  name: IconName;
  size?: number;
  color?: string;
  strokeWidth?: number;
}) {
  const colors = useTheme();
  const stroke = color ?? colors.text.secondary;
  const common = {
    stroke,
    strokeWidth,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    fill: 'none',
  };

  return (
    <Svg width={size} height={size} viewBox="0 0 24 24">
      {paths(name, common, stroke)}
    </Svg>
  );
}

function paths(name: IconName, p: Record<string, unknown>, stroke: string) {
  switch (name) {
    case 'location':
      return (
        <>
          <Path d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11Z" {...p} />
          <Circle cx="12" cy="10" r="2.6" {...p} />
        </>
      );
    case 'clock':
      return (
        <>
          <Circle cx="12" cy="12" r="8.5" {...p} />
          <Path d="M12 7.5V12l3 1.8" {...p} />
        </>
      );
    case 'ticket':
      return (
        <>
          <Path d="M4 9.5V7.5a1.5 1.5 0 0 1 1.5-1.5h13A1.5 1.5 0 0 1 20 7.5v2a2.5 2.5 0 0 0 0 5v2a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 16.5v-2a2.5 2.5 0 0 0 0-5Z" {...p} />
          <Path d="M14 6.5v11" {...p} strokeDasharray="2 2.5" />
        </>
      );
    case 'walk':
      return (
        <>
          <Circle cx="13" cy="4.6" r="1.8" {...p} />
          <Path d="M11 21l1.6-5.2-2.4-2.2.9-4.4 3 1.4 2.3 2.2" {...p} />
          <Path d="M9 12.2 7.4 9.5M12.6 15.8 15 21" {...p} />
        </>
      );
    case 'transit':
      return (
        <>
          <Rect x="5.5" y="3.5" width="13" height="13" rx="3.2" {...p} />
          <Path d="M5.5 11h13" {...p} />
          <Circle cx="9" cy="13.8" r="0.9" fill={stroke} stroke="none" />
          <Circle cx="15" cy="13.8" r="0.9" fill={stroke} stroke="none" />
          <Path d="M8.5 16.5 6.5 20.5M15.5 16.5l2 4" {...p} />
        </>
      );
    case 'weather':
      return (
        <>
          <Circle cx="9" cy="8.5" r="3.2" {...p} />
          <Path d="M10.5 19h6.2a3.3 3.3 0 0 0 .3-6.6 4.6 4.6 0 0 0-8.7-1 3.8 3.8 0 0 0 .4 7.6Z" {...p} />
        </>
      );
    case 'family':
      return (
        <>
          <Circle cx="8" cy="7" r="2.6" {...p} />
          <Circle cx="16.5" cy="9" r="2" {...p} />
          <Path d="M3.5 19.5c0-3 2-5 4.5-5s4.5 2 4.5 5" {...p} />
          <Path d="M14 19.5c0-2.3 1.2-4 2.8-4s3.2 1.4 3.2 4" {...p} />
        </>
      );
    case 'accessibility':
      return (
        <>
          <Circle cx="12" cy="4.6" r="1.8" {...p} />
          <Path d="M7.5 8.5h9M12 8.5V14h4.5" {...p} />
          <Path d="M16.5 14 18 20" {...p} />
          <Circle cx="10" cy="17" r="4" {...p} />
        </>
      );
    case 'food':
      return (
        <>
          <Path d="M7 3v8.5a2.5 2.5 0 0 0 5 0V3" {...p} />
          <Path d="M9.5 3v18" {...p} />
          <Path d="M17 3c-1.6 1.2-2.4 3-2.4 5.2 0 1.7.8 2.8 2.4 3.1V21" {...p} />
        </>
      );
    case 'camera':
      return (
        <>
          <Path d="M4 8.8A2 2 0 0 1 6 6.8h1.8l1.4-2.1h5.6l1.4 2.1H18a2 2 0 0 1 2 2v8.4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z" {...p} />
          <Circle cx="12" cy="12.8" r="3.4" {...p} />
        </>
      );
    case 'calendar':
      return (
        <>
          <Rect x="3.8" y="5.2" width="16.4" height="15" rx="3" {...p} />
          <Path d="M3.8 10h16.4M8.5 3.2v4M15.5 3.2v4" {...p} />
        </>
      );
    case 'money':
      return (
        <>
          <Rect x="3" y="6" width="18" height="12" rx="3" {...p} />
          <Circle cx="12" cy="12" r="2.6" {...p} />
          <Path d="M6.5 9.5v5M17.5 9.5v5" {...p} />
        </>
      );
    case 'directions':
      return (
        <>
          <Path d="M12 3 21 12l-9 9-9-9Z" {...p} />
          <Path d="M9.8 13.5v-2a1.6 1.6 0 0 1 1.6-1.6h3.2" {...p} />
          <Path d="M13 8.2l1.8 1.7L13 11.6" {...p} />
        </>
      );
    case 'save':
      return <Path d="M12 20s-7-4.4-7-9.4A4.1 4.1 0 0 1 12 7.6a4.1 4.1 0 0 1 7 3c0 5-7 9.4-7 9.4Z" {...p} />;
    case 'saved':
      return <Path d="M12 20s-7-4.4-7-9.4A4.1 4.1 0 0 1 12 7.6a4.1 4.1 0 0 1 7 3c0 5-7 9.4-7 9.4Z" fill={stroke} stroke={stroke} strokeWidth={1.4} strokeLinejoin="round" />;
    case 'share':
      return (
        <>
          <Path d="M12 3.5v11" {...p} />
          <Path d="M8.4 7 12 3.5 15.6 7" {...p} />
          <Path d="M6 12v6.5a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V12" {...p} />
        </>
      );
    case 'audio':
      return (
        <>
          <Path d="M5 14.5v-2a7 7 0 0 1 14 0v2" {...p} />
          <Rect x="3.4" y="13.6" width="3.6" height="6" rx="1.8" {...p} />
          <Rect x="17" y="13.6" width="3.6" height="6" rx="1.8" {...p} />
        </>
      );
    case 'guide':
    case 'sparkle':
      return (
        <>
          <Path d="M12 3.2 13.7 9l5.8 1.7-5.8 1.7L12 18.2l-1.7-5.8L4.5 10.7 10.3 9Z" {...p} />
          <Path d="M18.4 3.4 19 5.3l1.9.6-1.9.6-.6 1.9-.6-1.9L16 5.9l1.9-.6Z" {...p} />
        </>
      );
    case 'lock':
      return (
        <>
          <Rect x="4.8" y="10.2" width="14.4" height="10" rx="3" {...p} />
          <Path d="M8.2 10.2V7.6a3.8 3.8 0 0 1 7.6 0v2.6" {...p} />
        </>
      );
    case 'search':
      return (
        <>
          <Circle cx="11" cy="11" r="6.6" {...p} />
          <Path d="m16 16 4.2 4.2" {...p} />
        </>
      );
    case 'filter':
      return <Path d="M4 6.5h16M7 12h10M10 17.5h4" {...p} />;
    case 'close':
      return <Path d="M6.5 6.5l11 11M17.5 6.5l-11 11" {...p} />;
    case 'back':
      return <Path d="M14.5 5 8 12l6.5 7" {...p} />;
    case 'chevron':
      return <Path d="M9.5 5 16 12l-6.5 7" {...p} />;
    case 'plus':
      return <Path d="M12 5.5v13M5.5 12h13" {...p} />;
    case 'check':
      return <Path d="m5 12.5 4.5 4.5L19 7.5" {...p} />;
    case 'compass':
      return (
        <>
          <Circle cx="12" cy="12" r="8.6" {...p} />
          <Path d="m15.2 8.8-1.6 4.6-4.6 1.6 1.6-4.6Z" {...p} />
        </>
      );
    case 'map':
      return (
        <>
          <Path d="M9 4.5 3.8 6.6v13L9 17.4l6 2.1 5.2-2.1v-13L15 6.6Z" {...p} />
          <Path d="M9 4.5v12.9M15 6.6v12.9" {...p} />
        </>
      );
    case 'trip':
      return (
        <>
          <Path d="M4.5 6.5h15M4.5 12h15M4.5 17.5h9" {...p} />
          <Circle cx="18" cy="17.5" r="2.2" {...p} />
        </>
      );
    case 'bookings':
      return (
        <>
          <Path d="M6 3.8h12a1.6 1.6 0 0 1 1.6 1.6v14.8l-7.6-4-7.6 4V5.4A1.6 1.6 0 0 1 6 3.8Z" {...p} />
          <Path d="M9 9.5h6" {...p} />
        </>
      );
    case 'person':
      return (
        <>
          <Circle cx="12" cy="8" r="3.6" {...p} />
          <Path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6" {...p} />
        </>
      );
    case 'info':
      return (
        <>
          <Circle cx="12" cy="12" r="8.6" {...p} />
          <Path d="M12 11v5.2" {...p} />
          <Circle cx="12" cy="7.9" r="0.95" fill={stroke} stroke="none" />
        </>
      );
    default:
      return null;
  }
}
