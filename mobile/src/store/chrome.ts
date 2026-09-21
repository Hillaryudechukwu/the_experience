import { create } from 'zustand';

type ChromeState = {
  /** True while the traveller is scrolling down through content. */
  guideHidden: boolean;
  setGuideHidden: (hidden: boolean) => void;
};

/**
 * Whether the floating guide button should be out of the way.
 *
 * The button lives in the tab layout and the scrolling happens inside each
 * screen, so the two cannot talk through props. This is the whole conversation
 * between them: one boolean, set by whichever screen is scrolling.
 */
export const useChrome = create<ChromeState>((set) => ({
  guideHidden: false,
  setGuideHidden: (hidden) => set((state) => (state.guideHidden === hidden ? state : { guideHidden: hidden })),
}));
