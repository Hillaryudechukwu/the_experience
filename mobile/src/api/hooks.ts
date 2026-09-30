import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { clearIdentity, http } from './client';
import type {
  AssistantReply,
  Destination,
  DestinationDetail,
  DiscoveryResponse,
  ExperienceCard,
  ExperienceDetail,
  ExperienceDna,
  Itinerary,
  Journey,
  Passport,
  TravellerProfile,
  UncoveredPlace,
  Trip,
} from './types';
import { discoveryPayload, useSession } from '../store/session';

const wrapped = <T>(p: Promise<{ data: T }>) => p.then((r) => r.data);

export const keys = {
  destinations: (q?: string) => ['destinations', q ?? ''] as const,
  destination: (slug: string) => ['destination', slug] as const,
  profile: () => ['profile'] as const,
  dna: () => ['dna'] as const,
  journey: (id: string) => ['journey', id] as const,
  trip: (id: string) => ['trip', id] as const,
  experience: (id: string) => ['experience', id] as const,
  saved: () => ['saved'] as const,
  passport: () => ['passport'] as const,
  discovery: (surface: string, payload: unknown) => ['discovery', surface, payload] as const,
};

/**
 * Covered cities, and — when none match — the places that exist anyway.
 *
 * The two come back under separate keys and stay separate here. A geocoded
 * city has no experiences behind it, so merging them would let a screen offer
 * one as though it were bookable.
 */
export function useDestinations(q?: string) {
  return useQuery({
    queryKey: keys.destinations(q),
    queryFn: async () => {
      const path = `/destinations${q ? `?q=${encodeURIComponent(q)}` : ''}`;
      const body = await http.get<{ data: Destination[]; elsewhere?: UncoveredPlace[] }>(path);

      return { covered: body.data ?? [], elsewhere: body.elsewhere ?? [] };
    },
  });
}

export function useDestination(slug: string | null) {
  return useQuery({
    enabled: !!slug,
    queryKey: keys.destination(slug ?? ''),
    queryFn: () => wrapped(http.get<{ data: DestinationDetail }>(`/destinations/${slug}`)),
  });
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

  return useMutation({
    mutationFn: () => http.post(`/experiences/${id}/complete`),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: keys.experience(id) });
      client.invalidateQueries({ queryKey: keys.passport() });
    },
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
  items: { id: string; title: string; experience_id: string | null; quantity: number; starts_at: string | null }[];
};

export function useBookings() {
  return useQuery({
    queryKey: ['bookings'],
    queryFn: () => wrapped(http.get<{ data: BookingSummary[] }>('/bookings')),
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
