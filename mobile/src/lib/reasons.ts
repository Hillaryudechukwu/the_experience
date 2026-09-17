/** Spec s3.1 — "What brings you here?" */
export const JOURNEY_REASONS: { key: string; label: string; hint: string }[] = [
  { key: 'holiday', label: 'Holiday or city break', hint: 'A balanced mix of the essential and the interesting' },
  { key: 'business', label: 'Business trip', hint: 'Short, close to base, good for an evening' },
  { key: 'conference', label: 'Conference or trade event', hint: 'Fits the gaps around the programme' },
  { key: 'honeymoon', label: 'Honeymoon', hint: 'Romantic, scenic, unhurried' },
  { key: 'anniversary', label: 'Anniversary', hint: 'Memorable over comprehensive' },
  { key: 'birthday', label: 'Birthday trip', hint: 'Celebratory and social' },
  { key: 'family', label: 'Family holiday', hint: 'Age-appropriate, short queues, food and toilets' },
  { key: 'visiting_friends', label: 'Visiting friends or family', hint: 'What locals actually do' },
  { key: 'romantic', label: 'Romantic getaway', hint: 'Two people, good light, good food' },
  { key: 'solo', label: 'Solo adventure', hint: 'Flexible, reflective, social when you want it' },
  { key: 'backpacking', label: 'Backpacking', hint: 'Budget first, local always' },
  { key: 'cultural', label: 'Cultural exploration', hint: 'Depth over coverage' },
  { key: 'food', label: 'Food-focused trip', hint: 'Markets, producers, the real dishes' },
  { key: 'pilgrimage', label: 'Religious or pilgrimage travel', hint: 'Reflective, respectful timing' },
  { key: 'education', label: 'Education', hint: 'Learning, and mindful of budget' },
  { key: 'medical', label: 'Medical travel', hint: 'Low effort, close to base, restful' },
  { key: 'relocation', label: 'Exploring somewhere to live', hint: 'Neighbourhoods and everyday life' },
  { key: 'shopping', label: 'Shopping', hint: 'Districts and markets' },
  { key: 'sports_event', label: 'Sports event', hint: 'The fixture anchors the day' },
  { key: 'festival', label: 'Concert or festival', hint: 'The event anchors the night' },
  { key: 'photography', label: 'Photography trip', hint: 'Viewpoints and golden hour' },
  { key: 'retreat', label: 'Personal retreat', hint: 'Quiet, green, restorative' },
  { key: 'layover', label: 'Layover', hint: 'High value, short, and back in time' },
  { key: 'other', label: 'Something else', hint: 'We will learn as you go' },
];

export const FAMILIARITY = [
  { key: 'never', label: 'Never been' },
  { key: 'once', label: 'Been once' },
  { key: 'few', label: 'A few times' },
  { key: 'well', label: 'I know it well' },
];

export const INTERESTS: { key: string; label: string }[] = [
  { key: 'history', label: 'History' },
  { key: 'food', label: 'Food' },
  { key: 'culture', label: 'Culture' },
  { key: 'architecture', label: 'Architecture' },
  { key: 'nature', label: 'Nature' },
  { key: 'art', label: 'Art' },
  { key: 'music', label: 'Music' },
  { key: 'shopping', label: 'Shopping' },
  { key: 'nightlife', label: 'Nightlife' },
  { key: 'sport', label: 'Sport' },
  { key: 'photography', label: 'Photography' },
  { key: 'wellness', label: 'Wellness' },
  { key: 'family', label: 'Family activities' },
  { key: 'adventure', label: 'Adventure' },
  { key: 'local_life', label: 'Local life' },
];

export const MOODS = [
  { key: 'adventurous', label: 'Adventurous' },
  { key: 'relaxed', label: 'Relaxed' },
  { key: 'romantic', label: 'Romantic' },
  { key: 'curious', label: 'Curious' },
  { key: 'hungry', label: 'Hungry' },
  { key: 'social', label: 'Social' },
  { key: 'inspired', label: 'Inspired' },
  { key: 'energetic', label: 'Energetic' },
  { key: 'different', label: 'Something different' },
];

export const TIME_WINDOWS = [
  { minutes: 30, label: '30 min' },
  { minutes: 60, label: '1 hour' },
  { minutes: 120, label: '2 hours' },
  { minutes: 180, label: '3 hours' },
  { minutes: 300, label: 'Half a day' },
  { minutes: 600, label: 'All day' },
];

export const ANCHOR_TYPES = [
  { key: 'flight', label: 'Flight' },
  { key: 'train', label: 'Train' },
  { key: 'hotel_checkin', label: 'Hotel check-in' },
  { key: 'hotel_checkout', label: 'Hotel check-out' },
  { key: 'conference', label: 'Conference' },
  { key: 'meeting', label: 'Meeting' },
  { key: 'wedding', label: 'Wedding' },
  { key: 'fixture', label: 'Sports fixture' },
  { key: 'concert', label: 'Concert' },
  { key: 'restaurant', label: 'Restaurant booking' },
  { key: 'theatre', label: 'Theatre' },
  { key: 'prepaid_tour', label: 'Prepaid tour' },
  { key: 'transfer', label: 'Airport transfer' },
];
