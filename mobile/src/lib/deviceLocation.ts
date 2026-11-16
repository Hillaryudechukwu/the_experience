import * as Location from 'expo-location';
import { Platform } from 'react-native';

/** Long enough for a cold GPS fix; short enough that Explore cannot hang forever. */
const FIX_TIMEOUT_MS = 8_000;
const PERMISSION_CHECK_MS = 4_000;
/** System and browser prompts sit until the traveller answers; only bound them loosely. */
const PROMPT_TIMEOUT_MS = 120_000;

export type DeviceCoords = { lat: number; lng: number };

/**
 * `unavailable` is not a refusal: location services are off or the OS did not
 * answer. Callers must not record it as the traveller saying no.
 */
export type LocationPermissionOutcome = 'granted' | 'ask' | 'denied' | 'unavailable';

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('location_timeout')), ms);
    promise.then(
      (value) => {
        clearTimeout(timer);
        resolve(value);
      },
      (error) => {
        clearTimeout(timer);
        reject(error);
      },
    );
  });
}

function fromFix(fix: Location.LocationObject): DeviceCoords {
  return { lat: fix.coords.latitude, lng: fix.coords.longitude };
}

function isPermissionDeniedError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false;
  const code = 'code' in error ? (error as { code?: number | string }).code : undefined;
  /* Browser GeolocationPositionError.PERMISSION_DENIED === 1 */
  return code === 1 || code === 'E_LOCATION_UNAUTHORIZED';
}

/**
 * Decide whether we can read the phone position, should explain first, or
 * cannot ask again.
 *
 * Web is special: some browsers throw from the Permissions API (notably older
 * Safari), and `requestForegroundPermissionsAsync` itself calls geolocation
 * without a timeout — so on web we only *query* when possible, and let
 * `getDeviceCoords` own the actual browser prompt.
 */
export async function checkOrRequestLocationPermission(prompt: boolean): Promise<LocationPermissionOutcome> {
  try {
    const servicesOn = await withTimeout(Location.hasServicesEnabledAsync(), PERMISSION_CHECK_MS);
    if (!servicesOn) return 'unavailable';
  } catch {
    /* If we cannot even ask the OS, still offer the in-app button. */
    return prompt ? 'unavailable' : 'ask';
  }

  if (Platform.OS === 'web') {
    return webPermission(prompt);
  }

  try {
    const permission = await withTimeout(
      prompt ? Location.requestForegroundPermissionsAsync() : Location.getForegroundPermissionsAsync(),
      prompt ? PROMPT_TIMEOUT_MS : PERMISSION_CHECK_MS,
    );

    if (permission.status === 'granted') return 'granted';
    if (prompt || permission.canAskAgain === false) return 'denied';
    return 'ask';
  } catch {
    return prompt ? 'unavailable' : 'ask';
  }
}

async function webPermission(prompt: boolean): Promise<LocationPermissionOutcome> {
  try {
    const permission = await withTimeout(Location.getForegroundPermissionsAsync(), PERMISSION_CHECK_MS);

    if (permission.status === 'granted') return 'granted';
    if (permission.status === 'denied') {
      /* Expo web always sets canAskAgain true; permanent blocks still need the ask → denied path. */
      return prompt ? 'denied' : 'ask';
    }

    /* Undetermined: show the reason first, or proceed so geolocation shows the browser prompt. */
    return prompt ? 'granted' : 'ask';
  } catch {
    /* Permissions API missing — still show ask, or go straight to geolocation. */
    return prompt ? 'granted' : 'ask';
  }
}

/**
 * One-shot device position for city-scale discovery.
 *
 * `getCurrentPositionAsync` is known to hang on iOS and Android. Race a
 * timeout, prefer a recent last-known fix, then retry once at low accuracy.
 * On web, this call is also what surfaces the browser location prompt, so
 * until the site is granted the first read waits for the traveller's answer
 * rather than the GPS timeout.
 */
export async function getDeviceCoords(): Promise<DeviceCoords> {
  const balanced = { accuracy: Location.Accuracy.Balanced };
  const firstTimeout = (await mayShowBrowserPrompt()) ? PROMPT_TIMEOUT_MS : FIX_TIMEOUT_MS;

  try {
    return fromFix(await withTimeout(Location.getCurrentPositionAsync(balanced), firstTimeout));
  } catch (error) {
    if (isPermissionDeniedError(error)) throw error;

    const last = await Location.getLastKnownPositionAsync({
      maxAge: 15 * 60_000,
      requiredAccuracy: 2_000,
    });
    if (last) return fromFix(last);

    return fromFix(
      await withTimeout(Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Low }), FIX_TIMEOUT_MS),
    );
  }
}

async function mayShowBrowserPrompt(): Promise<boolean> {
  if (Platform.OS !== 'web') return false;

  try {
    const permission = await withTimeout(Location.getForegroundPermissionsAsync(), PERMISSION_CHECK_MS);
    return permission.status !== 'granted';
  } catch {
    /* No Permissions API: we cannot tell, so assume a prompt may be showing. */
    return true;
  }
}

export { isPermissionDeniedError };
