import { launch, session, fiche, Scenario, MOBILE, DESKTOP } from './lib.mjs';

const browser = await launch();
const s = new Scenario('S6 · Point de rupture 768px — mobile vs desktop');

for (const [nom, vp] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
  const { ctx, page } = await session(browser, 'mathieu@demo.club', vp);
  await fiche(page, 8);

  const mVis = await page.locator('.fiche-mobile').first().isVisible().catch(() => false);
  const dVis = await page.locator('.fiche-desktop').first().isVisible().catch(() => false);

  if (nom === 'mobile') {
    s.check('mobile : coquille mobile visible', mVis);
    s.check('mobile : coquille desktop masquée', !dVis);
  } else {
    s.check('desktop : coquille desktop visible', dVis);
    s.check('desktop : coquille mobile masquée', !mVis);
  }

  // Débordement horizontal = design cassé.
  const overflow = await page.evaluate(() =>
    document.documentElement.scrollWidth - document.documentElement.clientWidth);
  s.check(`${nom} : pas de débordement horizontal`, overflow <= 1, `${overflow}px`);

  // Le segment de rôle doit exister dans les DEUX formats.
  const seg = await page.locator('.seg-roles').count();
  s.check(`${nom} : contrôle segmenté présent`, seg > 0, `${seg} occurrence(s)`);

  await page.screenshot({ path: new URL(`./shots/s6-${nom}.png`, import.meta.url).pathname, fullPage: false });
  await ctx.close();
}

const ok = s.report();
await browser.close();
process.exit(ok ? 0 : 1);
