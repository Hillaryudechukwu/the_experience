import { useBuildItinerary, useCreateTrip, useTrip } from '../api/hooks';
import { useSession } from '../store/session';
import { confirmAction } from './alert';
import { stayRangeLabel } from './format';

/**
 * Making the plan match the dates, once the dates have moved.
 *
 * Two different situations, and they deserve different manners.
 *
 * When a plan already exists, rebuilding destroys days the traveller may have
 * been working from — generate-itinerary demotes the current itinerary, it
 * does not merge into it. So the days about to be replaced are named and the
 * question is asked.
 *
 * When there is no plan at all, there is nothing to destroy and nothing to
 * ask about: saying "when" and then being told to go to another tab and press
 * a button is a worse answer than simply building it. The trip itself is
 * created first if it has to be.
 *
 * This lives here because both the trip's edit screen and the chip on Home
 * move dates, and a rule about destroying someone's itinerary is not one to
 * keep two copies of.
 */
export function usePlanForDates(journeyId: string | null, tripId: string | null) {
  const { data: trip } = useTrip(tripId);
  const build = useBuildItinerary();
  const createTrip = useCreateTrip();
  const setTrip = useSession((s) => s.setTrip);

  const working = build.isPending || createTrip.isPending;

  /*
   * Whether we actually know there is no plan, as opposed to not having been
   * told yet. With a trip selected but its query still in flight, `trip` is
   * undefined and looks exactly like "no itinerary" — and acting on that would
   * silently rebuild the plan it was supposed to ask about. So an unknown is
   * treated as a plan: the worst case is a question about a plan that turns
   * out not to exist, which costs a tap.
   */
  const planUnknown = !!tripId && trip === undefined;

  const syncPlanToDates = (
    previous: { startsOn: string | null; endsOn: string | null },
    handlers: { onDone: () => void; onFailed: (message: string) => void },
  ): void => {
    const run = async () => {
      try {
        let id = tripId;

        if (!id) {
          if (!journeyId) {
            handlers.onDone();
            return;
          }

          const created = await createTrip.mutateAsync(journeyId);
          await setTrip(created.id);
          id = created.id;
        }

        await build.mutateAsync({ tripId: id });
        handlers.onDone();
      } catch (cause) {
        /* The dates saved; only the plan did not. Saying nothing would leave
           the new range above the old plan — and neither screen can retry,
           because once the journey refetches the dates match and there is
           nothing left to save. So name the control that can. */
        const why = cause instanceof Error ? ` (${cause.message})` : '';

        handlers.onFailed(
          `Your dates are saved, but the plan did not build${why}. Use Rebuild on your trip to try again.`,
        );
      }
    };

    if (!trip?.itinerary && !planUnknown) {
      void run();
      return;
    }

    /* Name the days being replaced when we know them. stayRangeLabel throws on
       null, hence two wordings rather than one. */
    const covers =
      previous.startsOn && previous.endsOn
        ? `Your plan covers ${stayRangeLabel(previous.startsOn, previous.endsOn)}.`
        : 'Your current plan was built for different days.';

    confirmAction('Rebuild your plan?', `${covers} Rebuilding it for the new dates replaces what is there now.`, {
      confirmLabel: 'Rebuild',
      cancelLabel: 'Keep it for now',
      onConfirm: run,
      onCancel: handlers.onDone,
    });
  };

  return { syncPlanToDates, working };
}
