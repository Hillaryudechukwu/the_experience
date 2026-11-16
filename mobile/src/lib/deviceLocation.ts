import * as Location from 'expo-location';

/** Long enough for a cold GPS fix; short enough that Explore cannot hang forever. */
const FIX_TIMEOUT_MS = 8_000;

export type DeviceCoords = { lat: number; lng: number };

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

/**
 * One-shot device position for city-scale discovery.
 *
 * `getCurrentPositionAsync` is known to hang or take tens of seconds on iOS
 * (and sometimes Android). Race a timeout, prefer a recent last-known fix, then
 * retry once at low accuracy before giving up.
 */
export async function getDeviceCoords(): Promise<DeviceCoords> {
  const balanced = { accuracy: Location.Accuracy.Balanced };

  try {
    return fromFix(await withTimeout(Location.getCurrentPositionAsync(balanced), FIX_TIMEOUT_MS));
  } catch {
    const last = await Location.getLastKnownPositionAsync({
      maxAge: 15 * 60_000,
      requiredAccuracy: 2_000,
    });
    if (last) return fromFix(last);

    return fromFix(
      await withTimeout(
        Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Low }),
        FIX_TIMEOUT_MS,
      ),
    );
  }
}
