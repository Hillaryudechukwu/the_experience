import { create } from 'zustand';

type Draft = {
  reason: string | null;
  familiarity: string;
  mission: string;
  interests: string[];
  adults: number;
  children: number;
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
};

export const useOnboardingDraft = create<Draft>((set) => ({
  ...initial,
  set: (patch) => set(patch),
  clear: () => set(initial),
}));
