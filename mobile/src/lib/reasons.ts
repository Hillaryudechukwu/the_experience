import type { IconName } from '../components/Icon';

/** Journey purpose (Figma: 14.3). Expressive, not a dropdown. */
export const JOURNEY_REASONS: { key: string; label: string; hint: string; icon: IconName }[] = [
  { key: 'holiday', label: 'Holiday', hint: 'A balanced mix of the essential and the interesting', icon: 'compass' },
  { key: 'business', label: 'Business', hint: 'Short, close to base, good for an evening', icon: 'bookings' },
  { key: 'conference', label: 'Conference', hint: 'Fits the gaps around the programme', icon: 'calendar' },
  { key: 'family', label: 'Family', hint: 'Age-appropriate, short queues, food and toilets', icon: 'family' },
  { key: 'romantic', label: 'Romantic getaway', hint: 'Two people, good light, good food', icon: 'save' },
  { key: 'anniversary', label: 'Celebration', hint: 'Memorable over comprehensive', icon: 'sparkle' },
  { key: 'solo', label: 'Solo adventure', hint: 'Flexible, reflective, social when you want it', icon: 'walk' },
  { key: 'visiting_friends', label: 'Visiting friends', hint: 'What locals actually do', icon: 'person' },
  { key: 'layover', label: 'Layover', hint: 'High value, short, and back in time', icon: 'clock' },
  { key: 'other', label: 'Something else', hint: 'We will learn as you go', icon: 'map' },
];

export const MORE_REASONS: { key: string; label: string; hint: string; icon: IconName }[] = [
  { key: 'honeymoon', label: 'Honeymoon', hint: 'Romantic, scenic, unhurried', icon: 'save' },
  { key: 'birthday', label: 'Birthday trip', hint: 'Celebratory and social', icon: 'sparkle' },
  { key: 'cultural', label: 'Cultural exploration', hint: 'Depth over coverage', icon: 'camera' },
  { key: 'food', label: 'Food-focused', hint: 'Markets, producers, the real dishes', icon: 'food' },
  { key: 'backpacking', label: 'Backpacking', hint: 'Budget first, local always', icon: 'walk' },
  { key: 'photography', label: 'Photography', hint: 'Viewpoints and golden hour', icon: 'camera' },
  { key: 'retreat', label: 'Personal retreat', hint: 'Quiet, green, restorative', icon: 'weather' },
  { key: 'sports_event', label: 'Sports event', hint: 'The fixture anchors the day', icon: 'ticket' },
  { key: 'festival', label: 'Concert or festival', hint: 'The event anchors the night', icon: 'audio' },
  { key: 'shopping', label: 'Shopping', hint: 'Districts and markets', icon: 'money' },
  { key: 'relocation', label: 'Exploring somewhere to live', hint: 'Neighbourhoods and everyday life', icon: 'location' },
  { key: 'education', label: 'Education', hint: 'Learning, and mindful of budget', icon: 'info' },
  { key: 'pilgrimage', label: 'Religious travel', hint: 'Reflective, respectful timing', icon: 'compass' },
  { key: 'medical', label: 'Medical travel', hint: 'Low effort, close to base, restful', icon: 'accessibility' },
];

export const FAMILIARITY = [
  { key: 'never', label: 'Never been' },
  { key: 'once', label: 'Been once' },
  { key: 'few', label: 'A few times' },
  { key: 'well', label: 'I know it well' },
];

/** Interests (Figma: 14.5). */
export const INTERESTS: { key: string; label: string; icon: IconName }[] = [
  { key: 'history', label: 'History', icon: 'compass' },
  { key: 'food', label: 'Food', icon: 'food' },
  { key: 'art', label: 'Art', icon: 'camera' },
  { key: 'architecture', label: 'Architecture', icon: 'map' },
  { key: 'nature', label: 'Nature', icon: 'weather' },
  { key: 'shopping', label: 'Shopping', icon: 'money' },
  { key: 'nightlife', label: 'Nightlife', icon: 'audio' },
  { key: 'adventure', label: 'Adventure', icon: 'walk' },
  { key: 'photography', label: 'Photography', icon: 'camera' },
  { key: 'sport', label: 'Sport', icon: 'ticket' },
  { key: 'wellness', label: 'Wellness', icon: 'accessibility' },
  { key: 'culture', label: 'Local culture', icon: 'location' },
  { key: 'family', label: 'Family', icon: 'family' },
  { key: 'music', label: 'Music', icon: 'audio' },
  { key: 'local_life', label: 'Local life', icon: 'person' },
];

/** Offline / loading fallback keyed by journey reason. */
export const MISSION_SUGGESTIONS_BY_REASON: Record<string, string[]> = {
  holiday: [
    'I want the essentials without feeling like I only saw the postcard.',
    'I want at least one meal I will still talk about next year.',
    'I want a mix of iconic and a little off the main square.',
  ],
  business: [
    "I'm here for work, but I don't want to leave without tasting the city.",
    'I want short, high-value evenings close to where I am staying.',
    'I want one impressive dinner I could take a client to.',
  ],
  conference: [
    'I want something memorable in the gaps between sessions.',
    'I want one neighbourhood walk that is not the hotel lobby.',
    'I want a dinner worth recommending to people I meet here.',
  ],
  family: [
    'I want my children to really experience this city.',
    'I want days that do not end in meltdowns and long queues.',
    'I want something we will all remember, not just endure.',
  ],
  romantic: [
    'I want evenings that feel like us, not like a checklist.',
    'I want good light, good food, and nowhere too crowded.',
    'I want one moment that feels unforgettable for two.',
  ],
  anniversary: [
    'I want an unforgettable anniversary weekend.',
    'I want dinner and a view that feel worth the trip alone.',
    'I want fewer stops and more time in the right places.',
  ],
  solo: [
    'I want space to explore at my own pace.',
    'I want places I can enjoy alone — and company when I choose it.',
    'I want to come back feeling clearer, not just fuller.',
  ],
  visiting_friends: [
    'I want to do what locals actually do, not the tourist loop.',
    'I want recommendations my hosts will be proud to share.',
    'I want evenings that fit around seeing people I care about.',
  ],
  layover: [
    'I want the highest-value hours before I have to leave.',
    'I want something close, quick, and worth the dash.',
    'I want one strong memory from a short stop.',
  ],
  other: [
    'I want this trip to feel like mine, not a template.',
    'I want a few things done well rather than everything half-seen.',
    'I want to leave knowing I used the time well.',
  ],
};

export function missionSuggestionsFor(reason: string | null | undefined): string[] {
  if (reason && MISSION_SUGGESTIONS_BY_REASON[reason]) {
    return MISSION_SUGGESTIONS_BY_REASON[reason];
  }
  return MISSION_SUGGESTIONS_BY_REASON.other;
}

/** @deprecated Prefer missionSuggestionsFor(reason) — kept for any stray imports. */
export const MISSION_SUGGESTIONS = MISSION_SUGGESTIONS_BY_REASON.other;

export const ANCHOR_TYPES: { key: string; label: string; icon: IconName }[] = [
  { key: 'flight', label: 'Flight', icon: 'ticket' },
  { key: 'train', label: 'Train', icon: 'transit' },
  { key: 'hotel_checkin', label: 'Hotel check-in', icon: 'bookings' },
  { key: 'hotel_checkout', label: 'Hotel check-out', icon: 'bookings' },
  { key: 'conference', label: 'Conference', icon: 'calendar' },
  { key: 'meeting', label: 'Meeting', icon: 'calendar' },
  { key: 'wedding', label: 'Wedding', icon: 'sparkle' },
  { key: 'fixture', label: 'Sports fixture', icon: 'ticket' },
  { key: 'concert', label: 'Concert', icon: 'audio' },
  { key: 'restaurant', label: 'Restaurant', icon: 'food' },
  { key: 'theatre', label: 'Theatre', icon: 'audio' },
  { key: 'prepaid_tour', label: 'Prepaid tour', icon: 'ticket' },
  { key: 'transfer', label: 'Airport transfer', icon: 'transit' },
];
