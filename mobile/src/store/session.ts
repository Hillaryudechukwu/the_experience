import AsyncStorage from '@react-native-async-storage/async-storage';
import { create } from 'zustand';

export type Coords = { lat: number; lng: number } | null;

type SessionState = {
  ready: boolean;
  destinationSlug: string | null;
  destinationId: string | null;
  destinationName: string | null;
  destinationTimezone: string | null;
  journeyId: string | null;
  tripId: string | null;
  coords: Coords;
  locationPrecision: 'precise' | 'approximate' | 'off';
  onboarded: boolean;

  hydrate: () => Promise<void>;
  setDestination: (d: { id: string; slug: string; name: string; timezone?: string }) => Promise<void>;
  setJourney: (journeyId: string | null) => Promise<void>;
  setTrip: (tripId: string | null) => Promise<void>;
  setCoords: (coords: Coords, precision?: 'precise' | 'approximate') => Promise<void>;
  setLocationPrecision: (p: 'precise' | 'approximate' | 'off') => Promise<void>;
  completeOnboarding: () => Promise<void>;
  reset: () => Promise<void>;
};

const KEY = 'experience.session';

type Persisted = Pick<
  SessionState,
  'destinationSlug' | 'destinationId' | 'destinationName' | 'destinationTimezone' | 'journeyId' | 'tripId' | 'locationPrecision' | 'onboarded'
>;

export const useSession = create<SessionState>((set, get) => {
  const persist = async () => {
    const s = get();
    const payload: Persisted = {
      destinationSlug: s.destinationSlug,
      destinationId: s.destinationId,
      destinationName: s.destinationName,
      destinationTimezone: s.destinationTimezone,
      journeyId: s.journeyId,
      tripId: s.tripId,
      locationPrecision: s.locationPrecision,
      onboarded: s.onboarded,
    };
    await AsyncStorage.setItem(KEY, JSON.stringify(payload));
  };

  return {
    ready: false,
    destinationSlug: null,
    destinationId: null,
    destinationName: null,
    destinationTimezone: null,
    journeyId: null,
    tripId: null,
    coords: null,
    locationPrecision: 'precise',
    onboarded: false,

    hydrate: async () => {
      const raw = await AsyncStorage.getItem(KEY);
      if (raw) {
        try {
          set({ ...(JSON.parse(raw) as Persisted), ready: true });
          return;
        } catch {
          /* fall through to a clean session */
        }
      }
      set({ ready: true });
    },

    setDestination: async (d) => {
      set({ destinationId: d.id, destinationSlug: d.slug, destinationName: d.name, destinationTimezone: d.timezone ?? null });
      await persist();
    },
    setJourney: async (journeyId) => {
      set({ journeyId });
      await persist();
    },
    setTrip: async (tripId) => {
      set({ tripId });
      await persist();
    },
    setCoords: async (coords, precision) => {
      set({ coords, ...(precision ? { locationPrecision: precision } : {}) });
      await persist();
    },
    setLocationPrecision: async (locationPrecision) => {
      set({ locationPrecision, ...(locationPrecision === 'off' ? { coords: null } : {}) });
      await persist();
    },
    completeOnboarding: async () => {
      set({ onboarded: true });
      await persist();
    },
    reset: async () => {
      await AsyncStorage.removeItem(KEY);
      set({
        destinationSlug: null,
        destinationId: null,
        destinationName: null,
        destinationTimezone: null,
        journeyId: null,
        tripId: null,
        coords: null,
        onboarded: false,
      });
    },
  };
});

/** The location + destination + journey block every discovery call needs. */
export function discoveryPayload(extra: Record<string, unknown> = {}) {
  const s = useSession.getState();

  return {
    ...(s.destinationId ? { destination_id: s.destinationId } : {}),
    ...(s.destinationSlug ? { destination: s.destinationSlug } : {}),
    ...(s.journeyId ? { journey_id: s.journeyId } : {}),
    ...(s.coords && s.locationPrecision !== 'off'
      ? { lat: s.coords.lat, lng: s.coords.lng, location_precision: s.locationPrecision }
      : {}),
    ...extra,
  };
}
