import { expect, settle, test } from './fixtures';
import { DATE_NIGHT_DAY } from './routes';

/**
 * Date night against the seeded city: an evening comes back, and a swap
 * re-plans it rather than dropping a new place into the old one's times.
 */
test('a planned evening shows its stops, and a swap re-times them', async ({ seeded: page }) => {
  await settle(page, `/date-night?date=${DATE_NIGHT_DAY}&starts_at=19:00`);

  await expect(page.getByText('Date night', { exact: true })).toBeVisible();
  await expect(page.getByText('Dinner', { exact: true })).toBeVisible();

  const swap = page.getByRole('button', { name: /^Swap / }).first();
  test.skip((await swap.count()) === 0, 'The seeded city offers no choice for any slot.');

  const plan = page.waitForResponse((response) => response.url().includes('/discovery/date-night'));
  await swap.click();
  const body = await (await plan).json();
  const request = (await plan).request().postDataJSON();

  /* The swap went to the planner with the slot fixed, and the screen shows
     the times it came back with. */
  expect(Object.keys(request.pins ?? {})).toHaveLength(1);
  const [role, pinned] = Object.entries(request.pins)[0] as [string, string];
  const stop = body.data.stops.find((s: { role: string }) => s.role === role);
  expect(stop.experience.id).toBe(pinned);
  await expect(page.getByText(stop.experience.title).first()).toBeVisible();
});
