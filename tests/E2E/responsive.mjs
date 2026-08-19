import { launch, session, fiche, sql, seance, Scenario, MOBILE, DESKTOP } from './lib.mjs';

const browser = await launch();
const s = new Scenario('S6 · Point de rupture 768px — mobile vs desktop');

// Cible dérivée : le segment de rôle (enroll-actions.blade.php) n'apparaît que si Mathieu ENCADRE
// la séance, qu'elle est future, non annulée, de type training, et qu'elle cible une de ses
// catégories actives. Un id en dur rendait S6 rouge dès que la séance visée avait commencé.
const mathieu = sql("SELECT id FROM users WHERE email='mathieu@demo.club'");
const cible = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
    AND EXISTS (SELECT 1 FROM session_coach sc WHERE sc.session_id=sessions.id AND sc.user_id=${mathieu})
    AND EXISTS (SELECT 1 FROM session_category sc2 JOIN user_category uc ON uc.category_id=sc2.category_id
                WHERE sc2.session_id=sessions.id AND uc.user_id=${mathieu})`);

for (const [nom, vp] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
  const { ctx, page } = await session(browser, 'mathieu@demo.club', vp);
  await fiche(page, cible);

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
