import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Location from 'expo-location';
import { create } from 'zustand';

export type Coords = { lat: number; lng: number } | null;
export type LocationSource = 'destination' | 'device';

type DestinationSelectionOptions = {
  /** Keep using the device position because this city was chosen via "I'm already here". */
  useDeviceLocation?: boolean;
};

type SessionState = {
  ready: boolean;
  destinationSlug: string | null;
  destinationId: string | null;
  destinationName: string | null;
  destinationTimezone: string | null;
  destinationImportId: string | null;
  journeyId: string | null;
  tripId: string | null;
  coords: Coords;
  /** When `coords` was captured, so a stale position can be discarded. */
  coordsAt: number | null;
  locationPrecision: 'precise' | 'approximate' | 'off';
  /** Whether discovery is about the chosen city or the traveller's live position. */
  locationSource: LocationSource;
  onboarded: boolean;

  hydrate: () => Promise<void>;
  setDestination: (
    d: { id: string; slug: string; name: string; timezone?: string },
    options?: DestinationSelectionOptions,
  ) => Promise<void>;
  setDestinationImport: (id: string | null) => Promise<void>;
  setJourney: (journeyId: string | null) => Promise<void>;
  setTrip: (tripId: string | null) => Promise<void>;
  setCoords: (coords: Coords, precision?: 'precise' | 'approximate') => Promise<void>;
  refreshLocation: () => Promise<void>;
  setLocationPrecision: (p: 'precise' | 'approximate' | 'off') => Promise<void>;
  completeOnboarding: () => Promise<void>;
  reset: () => Promise<void>;
};

const KEY = 'experience.session';

type Persisted = Pick<
  SessionState,
  'destinationSlug' | 'destinationId' | 'destinationName' | 'destinationTimezone' | 'destinationImportId' | 'journeyId' | 'tripId' | 'locationPrecision' | 'onboarded'
> & {
  coords: Coords;
  coordsAt: number | null;
  /** Optional because installed builds wrote sessions before the source existed. */
  locationSource?: LocationSource;
};

/**
 * How long a stored position is worth reusing.
 *
 * Coordinates were not persisted at all, so a traveller granted location once
 * during onboarding and the app forgot it the moment they closed it — and
 * nothing outside onboarding ever asks again, so every later session ran at
 * city-centre precision no matter what they had agreed to.
 *
 * Persisting them forever is the opposite mistake: this product answers "what
 * is near me now", and yesterday's coordinate is confidently wrong in a way no
 * coordinate at all is not. Thirty minutes is long enough to survive closing
 * the app over lunch and short enough that it cannot follow someone to another
 * city.
 */
const COORDS_TTL_MS = 30 * 60 * 1000;

/**
 * The shortest gap between two reads of the device position.
 *
 * refreshLocation now runs whenever the app returns to the foreground as well
 * as when Discover opens, and those overlap constantly — switching to the map
 * and back, answering a message mid-trip. Without a floor, each of those is a
 * GPS fix, which costs battery to learn something that has not changed. Two
 * minutes is shorter than any walk that would alter what is recommended.
 */
const COORDS_MIN_INTERVAL_MS = 2 * 60 * 1000;

export const useSession = create<SessionState>((set, get) => {
  const persist = async () => {
    const s = get();
    const payload: Persisted = {
      destinationSlug: s.destinationSlug,
      destinationId: s.destinationId,
      destinationName: s.destinationName,
      destinationTimezone: s.destinationTimezone,
      destinationImportId: s.destinationImportId,
      journeyId: s.journeyId,
      tripId: s.tripId,
      locationPrecision: s.locationPrecision,
      locationSource: s.locationSource,
      onboarded: s.onboarded,
      coords: s.coords,
      coordsAt: s.coordsAt,
    };
    await AsyncStorage.setItem(KEY, JSON.stringify(payload));
  };

  return {
    ready: false,
    destinationSlug: null,
    destinationId: null,
    destinationName: null,
    destinationTimezone: null,
    destinationImportId: null,
    journeyId: null,
    tripId: null,
    coords: null,
    coordsAt: null,
    locationPrecision: 'precise',
    locationSource: 'destination',
    onboarded: false,

    hydrate: async () => {
      const raw = await AsyncStorage.getItem(KEY);
      if (raw) {
        try {
          const stored = JSON.parse(raw) as Persisted;
          /* typeof, not a null check: a session written before this field
             existed has no coordsAt at all, and undefined would pass !== null
             and then poison the arithmetic. */
          const fresh =
            typeof stored.coordsAt === 'number' && Date.now() - stored.coordsAt < COORDS_TTL_MS;
          const locationSource: LocationSource = stored.locationSource === 'device'
            ? 'device'
            : 'destination';
          const useStoredCoords = locationSource === 'device' && fresh;

          set({
            ...stored,
            destinationImportId: stored.destinationImportId ?? null,
            locationSource,
            /* A stale position is discarded rather than trusted. Falling back
               to the city centre is a known approximation; a coordinate from
               another city is a wrong answer delivered with confidence.

               Sessions written before locationSource existed are deliberately
               migrated to destination mode. We cannot know whether their
               cached coordinate belonged to the city they later selected, so
               retaining it would preserve the weather bug and make the less
               private interpretation without the traveller choosing it. */
            coords: useStoredCoords ? stored.coords : null,
            coordsAt: useStoredCoords ? stored.coordsAt : null,
            ready: true,
          });

          /* Rewrite migrated/stale sessions once so the discarded device
             position is removed from durable storage as well as memory. */
          if (
            stored.locationSource === undefined ||
            (!useStoredCoords && (stored.coords !== null || stored.coordsAt !== null))
          ) {
            await persist();
          }

          return;
        } catch {
          /* fall through to a clean session */
        }
      }
      set({ ready: true });
    },

    setDestination: async (d, options) => {
      const useDeviceLocation = options?.useDeviceLocation === true;

      set({
        destinationId: d.id,
        destinationSlug: d.slug,
        destinationName: d.name,
        destinationTimezone: d.timezone ?? null,
        destinationImportId: null,
        locationSource: useDeviceLocation ? 'device' : 'destination',
        /* Choosing a city means "show me that city", even if the traveller
           previously used GPS elsewhere. Removing the cached coordinate also
           prevents it from surviving in local storage unnecessarily. */
        ...(useDeviceLocation ? {} : { coords: null, coordsAt: null }),
      });
      await persist();
    },
    setDestinationImport: async (destinationImportId) => {
      set({ destinationImportId });
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
      set({
        coords,
        coordsAt: coords === null ? null : Date.now(),
        ...(coords !== null ? { locationSource: 'device' as const } : {}),
        ...(precision ? { locationPrecision: precision } : {}),
      });
      await persist();
    },
    /**
     * Re-reads the device position, but only if that can be done silently.
     *
     * Location was asked for exactly once, on the onboarding screen, and never
     * again — so a traveller who agreed on Monday ran on a Monday coordinate
     * all week, and once it expired, on none at all. No other control in the
     * app offers to look again.
     *
     * It never prompts. getForegroundPermissionsAsync reads the existing grant
     * rather than requesting one, so a traveller who declined is left alone and
     * one who agreed is kept current without being asked twice. A failure is
     * silent on purpose: the city centre is a working fallback, and an error
     * about it would be noise.
     */
    refreshLocation: async () => {
      const state = get();

      if (state.locationSource !== 'device' || state.locationPrecision === 'off') return;

      /* Recent enough to still be true. */
      if (
        typeof state.coordsAt === 'number' &&
        Date.now() - state.coordsAt < COORDS_MIN_INTERVAL_MS
      ) {
        return;
      }

      try {
        const { status } = await Location.getForegroundPermissionsAsync();
        if (status !== 'granted') return;

        const position = await Location.getCurrentPositionAsync({
          accuracy: Location.Accuracy.Balanced,
        });

        await get().setCoords({
          lat: position.coords.latitude,
          lng: position.coords.longitude,
        });
      } catch {
        /* Keep whatever we had. The fallback already works. */
      }
    },
    setLocationPrecision: async (locationPrecision) => {
      set({
        locationPrecision,
        locationSource: locationPrecision === 'off' ? 'destination' : 'device',
        /* The timestamp goes with the coordinate. Leaving it behind would let
           a later hydrate treat a null position as freshly captured. */
        ...(locationPrecision === 'off' ? { coords: null, coordsAt: null } : {}),
      });
      await persist();

      /* This reads an existing permission only; it never opens a permission
         prompt from the settings screen. If there is no grant, discovery
         continues to fall back to the selected city's centre. */
      if (locationPrecision !== 'off') await get().refreshLocation();
    },
    completeOnboarding: async () => {
      set({ onboarded: true });
      await persist();
    },
    reset: async () => {
      await AsyncStorage.removeItem(KEY);
      set({
        coordsAt: null,
        destinationSlug: null,
        destinationId: null,
        destinationName: null,
        destinationTimezone: null,
        destinationImportId: null,
        journeyId: null,
        tripId: null,
        coords: null,
        locationSource: 'destination',
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
    ...(s.locationSource === 'device' && s.coords && s.locationPrecision !== 'off'
      ? { lat: s.coords.lat, lng: s.coords.lng, location_precision: s.locationPrecision }
      : {}),
    ...extra,
  };
}
