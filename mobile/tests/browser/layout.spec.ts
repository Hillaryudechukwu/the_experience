import { test, expect, settle } from './fixtures';
import { ROUTES } from './routes';

/**
 * Defects that only a layout engine can see.
 *
 * Each check here corresponds to something that actually shipped in this
 * project, not to a category someone imagined. A type checker cannot catch any
 * of them, and neither can a screenshot read by a person — two of the three are
 * invisible precisely because the element is still there.
 */

/**
 * Text present in the DOM but crushed below a legible height.
 *
 * The failure is specific to a fixed-height flex container whose contents
 * outgrow it: React Native Web shrinks whichever child has no height of its
 * own rather than overflowing visibly. The element keeps its width, keeps its
 * text, and still reads correctly to a screen reader, so the only evidence is
 * the measured height.
 *
 * It has happened twice — the five tab bar labels at 0px, and a Creative
 * Commons photo credit at 2px, which was also a licensing problem.
 */
test.describe('text is not silently crushed', () => {
  for (const path of ROUTES) {
    test(path, async ({ seeded: page }) => {
      await settle(page, path);

      const crushed = await page.evaluate(() => {
        const found: { text: string; height: number; fontSize: number }[] = [];

        for (const el of document.querySelectorAll('div,span,p')) {
          if (el.children.length > 0) continue;

          const text = (el as HTMLElement).innerText?.trim();
          if (!text) continue;

          const box = el.getBoundingClientRect();
          if (box.width <= 0) continue; // unmounted or display:none

          const fontSize = parseFloat(getComputedStyle(el).fontSize) || 12;

          /* A rendered line is 1.2 to 1.6 times its font size. Anything under
             0.6 is not a tight line height, it is a collapsed box. */
          if (box.height < fontSize * 0.6) {
            found.push({ text: text.slice(0, 60), height: +box.height.toFixed(1), fontSize });
          }
        }

        return found;
      });

      expect(crushed, `crushed text on ${path}`).toEqual([]);
    });
  }
});

/**
 * One interactive control inside another.
 *
 * The browser rejects a button inside a button, but the reason it matters is
 * that a keyboard or screen-reader user cannot reach the inner one — so the
 * save control on a tappable card worked for pointers only. Making the card
 * surface incapable of being a button removed the class of bug; this makes
 * sure it stays removed.
 */
test.describe('no nested interactive controls', () => {
  for (const path of ROUTES) {
    test(path, async ({ seeded: page }) => {
      await settle(page, path);

      const nested = await page.evaluate(() =>
        [...document.querySelectorAll('button,[role="button"]')]
          .filter((el) => el.querySelector('button,[role="button"]'))
          .map((el) => ({
            outer: el.getAttribute('aria-label') || (el as HTMLElement).innerText?.trim().slice(0, 40) || '(unlabelled)',
            inner:
              el.querySelector('button,[role="button"]')?.getAttribute('aria-label') ??
              '(unlabelled)',
          })),
      );

      expect(nested, `nested controls on ${path}`).toEqual([]);
    });
  }
});

/**
 * The checks above assert an empty list, which is exactly the shape of
 * assertion that passes when it has stopped looking.
 *
 * This suite has already produced one false green: before the session fixture
 * existed, every route redirected to the welcome screen and eighteen tests
 * reported success while measuring the same page. So each detector is pointed
 * at a deliberately broken element and required to find it. If a selector
 * drifts, or React Native Web changes what it emits, these fail before the
 * real checks start passing for the wrong reason.
 */
test.describe('the detectors can fail', () => {
  /*
   * The one that would have caught the false green. Every check in this file
   * runs against whatever the router decided to show, and with no session that
   * is the welcome screen for all eighteen routes — so the suite measured one
   * page, found nothing wrong with it, and reported the app as sound.
   */
  test('the fixture reaches the app rather than onboarding', async ({ seeded: page }) => {
    await settle(page, '/');

    expect(page.url()).not.toContain('/onboarding');

    /* The tab bar exists only inside the app, so it is the cheapest proof that
       the session took and the gate let us through. */
    await expect(page.getByRole('tab', { name: 'Home' })).toBeVisible();

    /* And real data arrived: the checks are about rendered content, and an
       empty screen has nothing to get wrong. */
    expect(await page.locator('img').count()).toBeGreaterThan(0);
  });

  test('crushed text is detected', async ({ seeded: page }) => {
    await settle(page, '/');

    const found = await page.evaluate(() => {
      const victim = document.createElement('div');
      victim.textContent = 'crushed on purpose';
      victim.setAttribute('style', 'height:2px;width:200px;font-size:14px;overflow:hidden');
      document.body.appendChild(victim);

      const hits = [...document.querySelectorAll('div,span,p')].filter((el) => {
        if (el.children.length > 0) return false;
        const text = (el as HTMLElement).innerText?.trim();
        if (!text) return false;
        const box = el.getBoundingClientRect();
        if (box.width <= 0) return false;
        const fontSize = parseFloat(getComputedStyle(el).fontSize) || 12;

        return box.height < fontSize * 0.6;
      }).map((el) => (el as HTMLElement).innerText.trim());

      victim.remove();

      return hits;
    });

    expect(found).toContain('crushed on purpose');
  });

  test('nested controls are detected', async ({ seeded: page }) => {
    await settle(page, '/');

    const found = await page.evaluate(() => {
      const outer = document.createElement('button');
      outer.setAttribute('aria-label', 'outer on purpose');
      const inner = document.createElement('button');
      inner.setAttribute('aria-label', 'inner on purpose');
      outer.appendChild(inner);
      document.body.appendChild(outer);

      const hits = [...document.querySelectorAll('button,[role="button"]')]
        .filter((el) => el.querySelector('button,[role="button"]'))
        .map((el) => el.getAttribute('aria-label') ?? '(unlabelled)');

      outer.remove();

      return hits;
    });

    expect(found).toContain('outer on purpose');
  });
});
