export type Freshness = {
  source: string | null;
  verified_at: string | null;
  class: string;
  age_seconds: number | null;
  is_stale: boolean;
  is_trusted: boolean;
  label: string;
};

export type Money = { minor: number; currency: string; formatted: string };

export type Category = { key: string; label: string };

export type ImageAttribution = {
  creator: string | null;
  licence: string | null;
  licence_url: string | null;
  source_url: string | null;
} | null;

export type ContentSource = { kind: string; name: string; url: string | null };

export type Travel = { minutes: number; metres: number; mode: string; confidence: string; source: string };

export type ExperienceCard = {
  id: string;
  slug: string;
  title: string;
  summary: string;
  image_url: string | null;
  image_attribution: ImageAttribution;
  categories: Category[];
  duration_minutes: number;
  is_free: boolean;
  price_from: Money | null;
  price_freshness: Freshness;
  weather_exposure: string;
  location: { lat: number; lng: number; name: string; neighbourhood: string | null } | null;
  rating: { value: number; count: number; freshness: Freshness } | null;
  experience_score: number | null;
  travel: Travel | null;
  why: string[];
  caveats: string[];
};

export type ExperienceDetail = {
  id: string;
  slug: string;
  title: string;
  descriptive: {
    summary: string;
    why_it_matters: string;
    best_for: string | null;
    what_to_wear: string | null;
    traveller_tip: string | null;
    know_before_you_go: string[];
    expected_duration_minutes: number;
    duration_range_minutes: [number, number];
    weather_exposure: string;
    energy_level: string;
    best_time_of_day: string[];
    image_url: string | null;
    image_attribution: ImageAttribution;
    categories: Category[];
    destination: string | null;
    neighbourhood: string | null;
  };
  dynamic: {
    price_from: { value: Money | null; is_free: boolean; freshness: Freshness };
    opening_hours: {
      known: boolean;
      schedule: Record<string, unknown> | null;
      open_now: boolean | null;
      closes_at: string | null;
      freshness: Freshness;
    };
    rating: { value: number; count: number; freshness: Freshness } | null;
    offers: Offer[];
    requires_booking: boolean;
    booking_lead_time_hours: number | null;
  };
  location: {
    name: string;
    address: string | null;
    lat: number;
    lng: number;
    website: string | null;
    phone: string | null;
    directions_url: string;
  } | null;
  accessibility: { claims: Record<string, unknown>; source: string | null; note: string };
  signals: { uniqueness: number; tourist_concentration: number; value_for_money: number; queue_risk: number };
  experience_score: number | null;
  explanation: {
    score: number;
    positive: string[];
    negative: string[];
    components: { component: string; value: number; is_neutral: boolean }[];
    contributions: Record<string, number | null>;
  } | null;
  related: Record<string, { id: string; title: string; weight: number; note: string | null }[]>;
  sources: ContentSource[];
  data_source: string;
  is_saved: boolean;
  is_completed: boolean;
};

export type Offer = {
  provider: string;
  provider_product_id: string;
  title: string;
  description: string | null;
  price_from: Money | null;
  price_freshness: Freshness;
  capabilities: string[];
  fulfilment: 'native' | 'redirect';
  cancellation_policy: string | null;
  degraded: boolean;
};

export type AvailabilitySlot = {
  date: string;
  start_time: string | null;
  slots_remaining: number | null;
  price: Money | null;
};

export type ProviderAvailability = {
  provider: string;
  provider_product_id: string;
  slots: AvailabilitySlot[];
  freshness: Freshness;
  is_live: boolean;
  unavailable_reason: string | null;
};

export type DiscoveryContext = {
  local_time: string;
  location_precision: string;
  window_minutes: number | null;
  weather: {
    condition: string;
    temperature_c: number;
    precipitation_probability: number;
    is_poor: boolean;
    sunset: string | null;
    freshness: Freshness;
  } | null;
  next_anchor: { title: string; starts_at: string } | null;
  journey: { id: string; reason: string; familiarity: string } | null;
  engine_version: string;
};

export type DiscoveryResponse = {
  data: ExperienceCard[];
  recommendation_set_id: string;
  context: DiscoveryContext;
  candidates_considered: number;
  catalogue_empty?: boolean;
  notice: string | null;
  interpreted?: { understood_as: string[]; filters: Record<string, unknown> };
};

export type Destination = {
  id: string;
  slug: string;
  name: string;
  country: string;
  timezone: string;
  currency: string;
  lat: number;
  lng: number;
  summary: string | null;
  hero_image_url: string | null;
  hero_image_attribution: ImageAttribution;
  coverage_status?: 'discovered' | 'queued' | 'importing' | 'ready' | 'limited' | 'failed';
};

/**
 * A place that exists but that we do not cover.
 *
 * Deliberately not a Destination: it has no id, because there is nothing in
 * the catalogue behind it. Keeping it a separate type means the compiler
 * refuses the mistake of feeding one to a screen that expects experiences.
 */
export type UncoveredPlace = {
  name: string;
  display_name: string;
  country: string;
  country_code: string | null;
  lat: number;
  lng: number;
  covered: false;
  kind: 'destination_candidate';
  source: 'nominatim';
  coverage_status: 'discoverable';
  candidate_token: string;
  region: string | null;
};

export type DestinationActivation = {
  destination_id: string;
  destination_slug: string;
  coverage_status: Destination['coverage_status'];
  import_id: string | null;
  stage: string | null;
  poll_after_seconds: number | null;
};

export type DestinationImport = {
  id: string;
  destination_id: string;
  destination: Pick<Destination, 'id' | 'name' | 'slug' | 'timezone' | 'coverage_status'>;
  status: 'queued' | 'running' | 'succeeded' | 'partial' | 'failed' | 'cancelled';
  stage: string;
  records_seen: number;
  places_created: number;
  places_matched: number;
  places_needing_review: number;
  places_failed: number;
  experiences_published: number;
  experiences_pending_content: number;
  started_at: string | null;
  finished_at: string | null;
  retryable: boolean;
  message: string;
  poll_after_seconds: number | null;
};

export type DestinationDetail = Destination & {
  neighbourhoods: {
    id: string;
    slug: string;
    name: string;
    character: string | null;
    best_for: string[];
    ideal_duration_minutes: number;
    lat: number;
    lng: number;
    image_url: string | null;
    image_attribution: ImageAttribution;
  }[];
  dont_leave_without: { title: string; description: string; kind: string; experience_id: string | null }[];
  city_essentials: {
    category: string;
    title: string;
    body: string;
    source: { name: string; url: string | null };
    verified_at: string;
  }[];
};

export type Journey = {
  id: string;
  title: string | null;
  reason: string;
  reason_label: string;
  destination: Destination;
  starts_on: string | null;
  ends_on: string | null;
  adults: number;
  children: number;
  child_ages: number[];
  familiarity: string;
  daily_budget_minor: number | null;
  currency: string;
  mission_text: string | null;
  mission_goals: { goal: string; weight: number; evidence: string }[];
  must_do: string[];
  avoid: string[];
  accessibility_mode: boolean;
  anchors: Anchor[];
};

export type Anchor = {
  id: string;
  type: string;
  title: string;
  starts_at: string;
  ends_at: string;
  protected_from: string;
  protected_to: string;
  address: string | null;
  lat: number | null;
  lng: number | null;
};

export type ItineraryItem = {
  id: string;
  kind: 'experience' | 'anchor' | 'meal' | 'travel' | 'rest';
  title: string;
  starts_at: string;
  ends_at: string;
  travel_minutes_from_previous: number;
  travel_mode: string | null;
  locked: boolean;
  score: number | null;
  reason: string | null;
  experience: { id: string; title: string; image_url: string | null; lat: number | null; lng: number | null } | null;
};

export type Itinerary = {
  id: string;
  version: number;
  is_current: boolean;
  generated_at: string;
  engine_version: string;
  objective_value: number;
  days: { id: string; date: string; summary: string | null; items: ItineraryItem[] }[];
};

export type Trip = { id: string; title: string; status: string; journey_id: string; itinerary: Itinerary | null };

export type TravellerProfile = {
  id: string;
  display_name: string | null;
  travel_pace: 'slow' | 'moderate' | 'fast';
  walking_tolerance: 'low' | 'medium' | 'high';
  budget_level: string;
  daily_experience_budget: Money | null;
  currency: string;
  iconic_vs_local: number;
  food_adventurousness: number;
  accessibility: Record<string, unknown>;
  interests: { interest: string; weight: number; source: string }[];
  is_guest: boolean;
};

export type ExperienceDna = {
  interests: { interest: string; label: string; percent: number; learned: boolean }[];
  travel_style: string;
  typical_spend: Money | null;
  preferred_pace: string;
  is_complete: boolean;
};

export type AssistantReply = {
  conversation_id: string;
  message: {
    id: string;
    role: string;
    content: string;
    suggestions: string[];
    experiences: { experience_id: string; title: string; score: number }[];
    grounded: boolean;
    removed_claims: string[];
    tool_calls: string[];
    driver: string;
  };
};

export type Passport = {
  totals: { experiences: number; cities: number; countries: number };
  cities: { id: string; name: string; country: string; lat: number; lng: number; experiences: number }[];
  categories: { key: string; count: number }[];
  milestones: { key: string; label: string; reached: boolean }[];
  recent: { experience_id: string; title: string; destination: string; completed_at: string }[];
};
