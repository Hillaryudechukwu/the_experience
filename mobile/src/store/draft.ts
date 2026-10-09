import { create } from 'zustand';

/** Calendar days in the city (arrival inclusive). Matches itinerary planner presets. */
export type StayDays = 1 | 3 | 7 | 14;

type Draft = {
  reason: string | null;
  familiarity: string;
  mission: string;
  interests: string[];
  adults: number;
  children: number;
  stayDays: StayDays;
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
  stayDays: 3 as StayDays,
  pace: 'moderate' as const,
  budget: 'moderate' as const,
  touristStyle: 60,
};

export const useOnboardingDraft = create<Draft>((set) => ({
  ...initial,
  set: (patch) => set(patch),
  clear: () => set(initial),
}));
