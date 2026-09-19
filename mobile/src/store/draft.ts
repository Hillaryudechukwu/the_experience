import { create } from 'zustand';

type Draft = {
  reason: string | null;
  familiarity: string;
  mission: string;
  interests: string[];
  adults: number;
  children: number;
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
  pace: 'moderate' as const,
  budget: 'moderate' as const,
  touristStyle: 60,
};

export const useOnboardingDraft = create<Draft>((set) => ({
  ...initial,
  set: (patch) => set(patch),
  clear: () => set(initial),
}));
