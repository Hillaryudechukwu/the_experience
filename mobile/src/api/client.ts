import AsyncStorage from '@react-native-async-storage/async-storage';
import Constants from 'expo-constants';
import { Platform } from 'react-native';

const GUEST_TOKEN_KEY = 'experience.guest_token';
const AUTH_TOKEN_KEY = 'experience.auth_token';

const FALLBACK_API_URL = 'http://127.0.0.1:8099/api';

function isLoopback(url: string): boolean {
  return /^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])(:|\/|$)/i.test(url);
}

/**
 * Works out where the API actually is.
 *
 * The trap this avoids: on a physical phone, `127.0.0.1` is the phone, not the
 * machine running the API, so a loopback URL can never reach it however
 * correctly the backend is running. The symptom is an unreachable API and no
 * clue why.
 *
 * Expo already solved the addressing problem — the device downloaded this
 * bundle from the dev server over the LAN — so when the configured URL is
 * loopback and we are not on web, we borrow that host and keep the API port.
 * An explicit non-loopback URL always wins, so staging and production are
 * untouched.
 */
function resolveApiUrl(): string {
  const configured = process.env.EXPO_PUBLIC_API_URL?.replace(/\/$/, '');

  if (configured && !isLoopback(configured)) return configured;

  // On web the browser is on the same machine, so loopback is correct.
  if (Platform.OS === 'web') return configured ?? FALLBACK_API_URL;

  const devHost = Constants.expoConfig?.hostUri?.split(':')[0];

  if (devHost && devHost !== 'localhost' && devHost !== '127.0.0.1') {
    const base = configured ?? FALLBACK_API_URL;
    const port = base.match(/:(\d+)/)?.[1] ?? '8099';
    const path = base.replace(/^https?:\/\/[^/]+/, '') || '/api';

    return `http://${devHost}:${port}${path}`;
  }

  return configured ?? FALLBACK_API_URL;
}

export const API_URL = resolveApiUrl();

let guestToken: string | null = null;
let authToken: string | null = null;
let hydrated = false;

async function hydrate() {
  if (hydrated) return;
  const [g, a] = await Promise.all([
    AsyncStorage.getItem(GUEST_TOKEN_KEY),
    AsyncStorage.getItem(AUTH_TOKEN_KEY),
  ]);
  guestToken = g;
  authToken = a;
  hydrated = true;
}

export async function setAuthToken(token: string | null) {
  authToken = token;
  token ? await AsyncStorage.setItem(AUTH_TOKEN_KEY, token) : await AsyncStorage.removeItem(AUTH_TOKEN_KEY);
}

export async function getAuthToken() {
  await hydrate();
  return authToken;
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly body: unknown,
  ) {
    super(message);
  }
}

type RequestOptions = { method?: string; body?: unknown; headers?: Record<string, string> };

/**
 * The single HTTP entry point.
 *
 * Guest-first: the API mints a guest token on first contact and returns it in a
 * header. We persist it and send it back, so browsing, saving and planning all
 * work before there is an account.
 */
export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  await hydrate();

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Client-Platform': 'mobile',
    ...options.headers,
  };

  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (guestToken) headers['X-Guest-Token'] = guestToken;
  if (authToken) headers.Authorization = `Bearer ${authToken}`;

  let response: Response;
  try {
    response = await fetch(`${API_URL}${path}`, {
      method: options.method ?? 'GET',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
  } catch (cause) {
    throw new ApiError(
      `Could not reach the API at ${API_URL}. Is the backend running?`,
      0,
      cause,
    );
  }

  const returned = response.headers.get('x-guest-token');
  if (returned && returned !== guestToken) {
    guestToken = returned;
    AsyncStorage.setItem(GUEST_TOKEN_KEY, returned).catch(() => {});
  }

  const text = await response.text();
  const payload = text ? safeParse(text) : null;

  if (!response.ok) {
    const message =
      (payload as { message?: string } | null)?.message ?? `Request failed (${response.status})`;
    throw new ApiError(message, response.status, payload);
  }

  return payload as T;
}

function safeParse(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return { message: text.slice(0, 200) };
  }
}

export const http = {
  get: <T>(path: string) => api<T>(path),
  post: <T>(path: string, body?: unknown, headers?: Record<string, string>) =>
    api<T>(path, { method: 'POST', body: body ?? {}, headers }),
  patch: <T>(path: string, body: unknown) => api<T>(path, { method: 'PATCH', body }),
  delete: <T>(path: string) => api<T>(path, { method: 'DELETE' }),
};
