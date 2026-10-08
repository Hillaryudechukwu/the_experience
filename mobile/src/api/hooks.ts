import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { clearIdentity, http, setAuthToken } from './client';
import { showAlert } from '../lib/alert';
import type {
  AssistantReply,
  Destination,
  DestinationActivation,
  DestinationDetail,
  DestinationImport,
  DiscoveryResponse,
  ExperienceCard,
  ExperienceDetail,
  ExperienceDna,
  Itinerary,
  Journey,
  Passport,
  ProviderAvailability,
  TravellerProfile,
  UncoveredPlace,
  Trip,
} from './types';
import { discoveryPayload, useSession } from '../store/session';

/** Stable per-intent key so a retried tap cannot create a second booking. */
export function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `idem-${Date.now()}-${Math.random().toString(36).slice(2, 11)}`;
}

const wrapped = <T>(p: Promise<{ data: T }>) => p.then((r) => r.data);

export const keys = {
  destinations: (q?: string, worldwide = false) => ['destinations', q ?? '', worldwide] as const,
  destination: (slug: string) => ['destination', slug] as const,
  destinationImport: (id: string) => ['destination-import', id] as const,
  profile: () => ['profile'] as const,
  dna: () => ['dna'] as const,
  journey: (id: string) => ['journey', id] as const,
  trip: (id: string) => ['trip', id] as const,
  experience: (id: string) => ['experience', id] as const,
  saved: () => ['saved'] as const,
  passport: () => ['passport'] as const,
  journal: (id: string) => ['journal', id] as const,
  recap: (journeyId: string) => ['recap', journeyId] as const,
  discovery: (surface: string, payload: unknown) => ['discovery', surface, payload] as const,
};

/**
 * Covered cities, and — when none match — the places that exist anyway.
 *
 * The two come back under separate keys and stay separate here. A geocoded
 * city has no experiences behind it, so merging them would let a screen offer
 * one as though it were bookable.
 */
export function useDestinations(q?: string, worldwide = false) {
  return useQuery({
    queryKey: keys.destinations(q, worldwide),
    queryFn: async () => {
      const params = new URLSearchParams();
      if (q) params.set('q', q);
      if (worldwide) params.set('worldwide', '1');
      const path = `/destinations${params.size > 0 ? `?${params.toString()}` : ''}`;
      const body = await http.get<{ data: Destination[]; elsewhere?: UncoveredPlace[] }>(path);

      return { covered: body.data ?? [], elsewhere: body.elsewhere ?? [] };
    },
  });
}

export function useActivateDestination() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (candidateToken: string) =>
      wrapped(http.post<{ data: DestinationActivation }>('/destinations/activate', {
        candidate_token: candidateToken,
      })),
    onSuccess: () => client.invalidateQueries({ queryKey: ['destinations'] }),
    onError: reportFailure('prepare that city'),
  });
}

export function useDestinationImport(id: string | null) {
  return useQuery({
    enabled: !!id,
    queryKey: keys.destinationImport(id ?? ''),
    queryFn: () => wrapped(http.get<{ data: DestinationImport }>(`/destination-imports/${id}`)),
    refetchInterval: (query) => {
      const data = query.state.data;
      return data && ['queued', 'running'].includes(data.status)
        ? (data.poll_after_seconds ?? 3) * 1000
        : false;
    },
  });
}

export function useRetryDestinationImport() {
  return useMutation({
    mutationFn: (id: string) => wrapped(http.post<{ data: { import_id: string } }>(`/destination-imports/${id}/retry`)),
    onError: reportFailure('retry that city'),
  });
}

export function useDestination(slug: string | null) {
  return useQuery({
    enabled: !!slug,
    queryKey: keys.destination(slug ?? ''),
    queryFn: () => wrapped(http.get<{ data: DestinationDetail }>(`/destinations/${slug}`)),
  });
}

/**
 * Tells the traveller when a write did not land.
 *
 * These mutations were fired and forgotten: no onError anywhere, and no
 * optimistic update either, so a failed request meant the tap simply did
 * nothing. Tapping an interest and watching the chip not move is
 * indistinguishable from the control being broken — and quietly losing a
 * stated preference matters more here than in most apps, because the whole
 * product is built on them.
 *
 * It lives on the hooks rather than at each call site so that a new screen
 * cannot forget it.
 */
function reportFailure(what: string) {
  return (cause: unknown) => {
    showAlert(
      `Could not ${what}`,
      cause instanceof Error ? cause.message : 'The connection dropped. Please try again.',
    );
  };
}

export function useProfile() {
  return useQuery({
    queryKey: keys.profile(),
    queryFn: () => wrapped(http.get<{ data: TravellerProfile }>('/traveller/profile')),
  });
}

export function useExperienceDna() {
  return useQuery({
    queryKey: keys.dna(),
    queryFn: () => wrapped(http.get<{ data: ExperienceDna }>('/traveller/experience-dna')),
  });
}

export function useUpdateProfile() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      wrapped(http.patch<{ data: TravellerProfile }>('/traveller/profile', body)),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: keys.profile() });
      client.invalidateQueries({ queryKey: keys.dna() });
      client.invalidateQueries({ queryKey: ['discovery'] });
    },
    onError: reportFailure('save that change'),
  });
}

/** The three context-engine surfaces: now, time-boxed, mood. */
export function useDiscovery(
  surface: 'now' | 'time-boxed' | 'mood',
  payload: Record<string, unknown> = {},
  enabled = true,
) {
  const session = useSession();
  const body = discoveryPayload(payload);

  return useQuery({
    enabled: enabled && session.ready && (!!session.destinationId || !!session.coords),
    queryKey: keys.discovery(surface, body),
    queryFn: () => http.post<DiscoveryResponse>(`/discovery/${surface}`, body),
    staleTime: 60_000,
  });
}

export function useSearch(query: string, enabled: boolean) {
  const body = discoveryPayload({ q: query });

  return useQuery({
    enabled: enabled && query.trim().length > 2,
    queryKey: keys.discovery('search', body),
    queryFn: () => http.post<DiscoveryResponse>('/discovery/search', body),
  });
}

export function useSurpriseMe() {
  return useMutation({
    mutationFn: (payload: Record<string, unknown> = {}) =>
      http.post<{ data: ExperienceCard | null; message?: string }>('/discovery/surprise-me', discoveryPayload(payload)),
  });
}

export function useExperience(id: string) {
  return useQuery({
    queryKey: keys.experience(id),
    queryFn: () => wrapped(http.get<{ data: ExperienceDetail }>(`/experiences/${id}?${queryString(discoveryPayload())}`)),
  });
}

export function useSaved() {
  return useQuery({
    queryKey: keys.saved(),
    queryFn: () => wrapped(http.get<{ data: ExperienceCard[] }>('/experiences/saved')),
  });
}

export function useToggleSave(id: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (saved: boolean) =>
      saved ? http.delete(`/experiences/${id}/save`) : http.post(`/experiences/${id}/save`),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: keys.experience(id) });
      client.invalidateQueries({ queryKey: keys.saved() });
    },
    onError: reportFailure('update your saved list'),
  });
}

/**
 * Erases the traveller (Apple 5.1.1(v)).
 *
 * The whole query cache is cleared rather than invalidated: invalidation would
 * refetch, and there is nothing left to fetch. A guest sends no password —
 * there is no account and nothing to prove.
 */
export function useDeleteAccount() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (password?: string) =>
      http.delete<{ status: string; retained_bookings: number }>(
        '/auth/account',
        password ? { password } : undefined,
      ),
    onSuccess: async () => {
      await clearIdentity();
      client.clear();
    },
  });
}

export function useMarkComplete(id: string) {
  const client = useQueryClient();
  const journeyId = useSession((s) => s.journeyId);

  return useMutation({
    mutationFn: () =>
      http.post(`/experiences/${id}/complete`, {
        ...(journeyId ? { journey_id: journeyId } : {}),
      }),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: keys.experience(id) });
      client.invalidateQueries({ queryKey: keys.passport() });
      if (journeyId) {
        client.invalidateQueries({ queryKey: keys.recap(journeyId) });
      }
    },
    onError: reportFailure('mark that as done'),
  });
}

export type JournalInput = {
  rating: number;
  would_recommend?: boolean;
  best_part?: string | null;
  private_note?: string | null;
  is_public?: boolean;
};

export type JournalEntry = {
  id: string;
  experience_id: string;
  rating: number;
  would_recommend: boolean | null;
  best_part: string | null;
  private_note: string | null;
  is_public: boolean;
  has_private_note: boolean;
};

export function useJournal(experienceId: string, enabled = false) {
  return useQuery({
    enabled: enabled && !!experienceId,
    queryKey: keys.journal(experienceId),
    queryFn: () => wrapped(http.get<{ data: JournalEntry | null }>(`/passport/journal/${experienceId}`)),
  });
}

export function useWriteJournal(experienceId: string) {
  const client = useQueryClient();
  const journeyId = useSession((s) => s.journeyId);

  return useMutation({
    mutationFn: (body: JournalInput) =>
      wrapped(http.post<{ data: JournalEntry }>(`/passport/journal/${experienceId}`, body)),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: keys.passport() });
      client.invalidateQueries({ queryKey: keys.experience(experienceId) });
      client.invalidateQueries({ queryKey: keys.journal(experienceId) });
      if (journeyId) {
        client.invalidateQueries({ queryKey: keys.recap(journeyId) });
      }
    },
    onError: reportFailure('save that journal note'),
  });
}

export type JourneyRecap = {
  destination: string;
  days: number | null;
  experiences: number;
  iconic: number;
  hidden_gems: number;
  food_experiences: number;
  average_rating: number | null;
  highlights: { experience_id: string; rating: number; best_part: string | null }[];
};

export function useJourneyRecap(journeyId: string | null) {
  return useQuery({
    enabled: !!journeyId,
    queryKey: keys.recap(journeyId ?? ''),
    queryFn: () => wrapped(http.get<{ data: JourneyRecap }>(`/passport/recap/${journeyId}`)),
  });
}

export function useExportPrivacyData() {
  return useMutation({
    mutationFn: () => wrapped(http.get<{ data: Record<string, unknown> }>('/privacy/export')),
    onError: reportFailure('export your data'),
  });
}

export function useJourney(id: string | null) {
  return useQuery({
    enabled: !!id,
    queryKey: keys.journey(id ?? ''),
    queryFn: () => wrapped(http.get<{ data: Journey }>(`/journeys/${id}`)),
  });
}

export function useCreateJourney() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (body: Record<string, unknown>) => wrapped(http.post<{ data: Journey }>('/journeys', body)),
    onSuccess: () => client.invalidateQueries({ queryKey: ['discovery'] }),
  });
}

export function useAddAnchor(journeyId: string) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (body: Record<string, unknown>) => http.post(`/journeys/${journeyId}/anchors`, body),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: keys.journey(journeyId) });
      client.invalidateQueries({ queryKey: ['discovery'] });
    },
  });
}

export function useTrip(id: string | null) {
  return useQuery({
    enabled: !!id,
    queryKey: keys.trip(id ?? ''),
    queryFn: () => wrapped(http.get<{ data: Trip }>(`/trips/${id}`)),
  });
}

export function useCreateTrip() {
  return useMutation({
    mutationFn: (journeyId: string) => wrapped(http.post<{ data: Trip }>('/trips', { journey_id: journeyId })),
  });
}

export function useGenerateItinerary(tripId: string | null) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (body: Record<string, unknown> = {}) =>
      wrapped(http.post<{ data: Itinerary }>(`/trips/${tripId}/generate-itinerary`, body)),
    onSuccess: () => client.invalidateQueries({ queryKey: keys.trip(tripId ?? '') }),
  });
}

export type ReplanResult = {
  message: string;
  trigger: { type: string; condition?: string; affects?: string } | null;
  changes: { direction: string; title: string; was?: string; now?: string }[];
  blocked_by_confirmed_bookings: unknown[];
  proposal: Itinerary | null;
};

export function useReplan(tripId: string | null) {
  return useMutation({
    mutationFn: () => wrapped(http.post<{ data: ReplanResult }>(`/trips/${tripId}/replan`, {})),
  });
}

export function useAcceptReplan(tripId: string | null) {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (itineraryId: string) =>
      wrapped(http.post<{ data: Itinerary }>(`/trips/${tripId}/itineraries/${itineraryId}/accept`, {})),
    onSuccess: () => client.invalidateQueries({ queryKey: keys.trip(tripId ?? '') }),
  });
}

export type BookingSummary = {
  id: string;
  reference: string;
  state: string;
  provider: string;
  fulfilment: string;
  redirect_url: string | null;
  provider_booking_id: string | null;
  total: { minor: number; currency: string; formatted: string } | null;
  cancellation_policy: string | null;
  failure_reason: string | null;
  replayed?: boolean;
  items: { id: string; title: string; experience_id: string | null; quantity: number; starts_at: string | null }[];
};

export type CreateBookingInput = {
  provider: string;
  provider_product_id: string;
  quantity?: number;
  starts_at?: string | null;
  journey_id?: string | null;
  trip_id?: string | null;
  idempotency_key?: string;
};

export type CancelBookingResult = BookingSummary & {
  cancellable_here?: boolean;
  message?: string;
  refund_expected?: boolean;
};

export function useBookings() {
  return useQuery({
    queryKey: ['bookings'],
    queryFn: () => wrapped(http.get<{ data: BookingSummary[] }>('/bookings')),
  });
}

export function useAvailability(experienceId: string, enabled = false) {
  return useQuery({
    enabled: enabled && !!experienceId,
    queryKey: ['availability', experienceId],
    queryFn: () =>
      wrapped(http.get<{ data: ProviderAvailability[] }>(`/experiences/${experienceId}/availability`)),
    staleTime: 30_000,
  });
}

export function useCreateBooking() {
  const client = useQueryClient();
  const session = useSession();

  return useMutation({
    mutationFn: (input: CreateBookingInput) => {
      const key = input.idempotency_key ?? newIdempotencyKey();
      const { idempotency_key: _omit, ...body } = input;

      return wrapped(
        http.post<{ data: BookingSummary }>(
          '/bookings',
          {
            ...body,
            quantity: body.quantity ?? 1,
            journey_id: body.journey_id ?? session.journeyId,
            trip_id: body.trip_id ?? session.tripId,
          },
          { 'Idempotency-Key': key },
        ),
      );
    },
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['bookings'] });
    },
    onError: reportFailure('complete that booking'),
  });
}

export function useCancelBooking() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: (bookingId: string) =>
      wrapped(http.post<{ data: CancelBookingResult }>(`/bookings/${bookingId}/cancel`, {})),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['bookings'] });
    },
    onError: reportFailure('cancel that booking'),
  });
}

export type AuthUser = { id: number; name: string; email: string };

export function useRegister() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (body: { name: string; email: string; password: string }) => {
      const result = await http.post<{ token: string; user: AuthUser }>('/auth/register', body);
      await setAuthToken(result.token);
      return result.user;
    },
    onSuccess: () => {
      client.invalidateQueries();
    },
    /* Screens surface field errors inline; a second system alert stacks badly
       behind the keyboard on small phones. */
  });
}

export function useLogin() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (body: { email: string; password: string }) => {
      const result = await http.post<{ token: string; user: AuthUser }>('/auth/login', body);
      await setAuthToken(result.token);
      return result.user;
    },
    onSuccess: () => {
      client.invalidateQueries();
    },
  });
}

export function useLogout() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      try {
        await http.post('/auth/logout');
      } finally {
        await clearIdentity();
      }
    },
    onSuccess: () => {
      client.clear();
    },
    onError: reportFailure('sign out'),
  });
}

export function usePassport() {
  return useQuery({
    queryKey: keys.passport(),
    queryFn: () => wrapped(http.get<{ data: Passport }>('/passport')),
  });
}

export function useAssistant() {
  return useMutation({
    mutationFn: (input: { message: string; conversationId?: string | null }) =>
      wrapped(
        http.post<{ data: AssistantReply }>(
          '/assistant/message',
          discoveryPayload({ message: input.message, conversation_id: input.conversationId ?? undefined }),
        ),
      ),
  });
}

export function recordEvents(events: Record<string, unknown>[]) {
  /* Analytics must never block or break a screen. */
  http.post('/events', { events }).catch(() => {});
}

function queryString(payload: Record<string, unknown>): string {
  return Object.entries(payload)
    .filter(([, v]) => v !== undefined && v !== null)
    .map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`)
    .join('&');
}
