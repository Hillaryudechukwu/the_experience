import { create } from 'zustand';

/*
 * The dates of the trip, as plain YYYY-MM-DD calendar strings.
 *
 * This was `stayDays: 1 | 3 | 7 | 14`, which could only ever describe a trip
 * starting today — there was no way to plan one three weeks out, and the
 * three-day default was what every traveller silently got. Null means not yet
 * answered; style.tsx falls back to the old behaviour on skip.
 */

type Draft = {
  reason: string | null;
  familiarity: string;
  mission: string;
  interests: string[];
  adults: number;
  children: number;
  startsOn: string | null;
  endsOn: string | null;
  pace: 'slow' | 'moderate' | 'fast';
  budget: 'budget' | 'moderate' | 'premium' | 'luxury';
  touristStyle: number;
  set: (patch: Partial<Omit<Draft, 'set' | 'clear'>>) => void;
  clear: () => void;
};

const initial = {
  reason: null,
  familiarity: 'never',
  mission: '',
  interests: [] as string[],
  adults: 1,
  children: 0,
  startsOn: null as string | null,
  endsOn: null as string | null,
  pace: 'moderate' as const,
  budget: 'moderate' as const,
  touristStyle: 60,
};

export const useOnboardingDraft = create<Draft>((set) => ({
  ...initial,
  set: (patch) => set(patch),
  clear: () => set(initial),
}));
