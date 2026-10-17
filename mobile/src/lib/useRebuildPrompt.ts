import { useGenerateItinerary, useTrip } from '../api/hooks';
import { confirmAction } from './alert';
import { stayRangeLabel } from './format';

/**
 * Asking before a plan is thrown away.
 *
 * A plan built for 9-11 October means nothing once the trip is 20-27, but
 * rebuilding silently discards days the traveller may have been working from:
 * generate-itinerary demotes the current itinerary, it does not merge into it.
 * So whenever dates move, the days about to be replaced are named and the
 * question is asked.
 *
 * This lives here because two screens now move dates — the trip's edit screen
 * and the chip on Home — and a rule about destroying someone's itinerary is
 * not one to keep two copies of.
 */
export function useRebuildPrompt(tripId: string | null) {
  const { data: trip } = useTrip(tripId);
  const rebuild = useGenerateItinerary(tripId ?? '');

  /**
   * Returns true when it has taken over — a prompt is on screen and the caller
   * should stop. False means there was no plan to disturb, so carry on.
   */
  const askToRebuild = (
    previous: { startsOn: string | null; endsOn: string | null },
    handlers: { onDone: () => void; onFailed: (message: string) => void },
  ): boolean => {
    if (!tripId || !trip?.itinerary) return false;

    /* Name the days being replaced when we know them. A journey that had no
       dates still has a plan — built from the planner's own fallback — and
       skipping the prompt there would leave exactly the contradiction it
       exists to prevent. stayRangeLabel throws on null, hence two wordings. */
    const covers =
      previous.startsOn && previous.endsOn
        ? `Your plan covers ${stayRangeLabel(previous.startsOn, previous.endsOn)}.`
        : 'Your current plan was built for different days.';

    confirmAction('Rebuild your plan?', `${covers} Rebuilding it for the new dates replaces what is there now.`, {
      confirmLabel: 'Rebuild',
      cancelLabel: 'Keep it for now',
      onConfirm: async () => {
        try {
          await rebuild.mutateAsync({});
          handlers.onDone();
        } catch (cause) {
          /* The dates saved; only the rebuild failed. Saying nothing would
             leave the new range above the old plan — and neither screen can
             retry it, because once the journey refetches the dates match and
             there is nothing left to save. So the message names the control
             that can: Rebuild, on the trip. */
          const why = cause instanceof Error ? ` (${cause.message})` : '';

          handlers.onFailed(
            `Your dates are saved, but the plan did not rebuild${why}. Use Rebuild on your trip to try again.`,
          );
        }
      },
      onCancel: handlers.onDone,
    });

    return true;
  };

  return { askToRebuild };
}
