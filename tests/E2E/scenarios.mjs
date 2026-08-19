import { launch, session, fiche, sql, barreMobile, Scenario, MOBILE, DESKTOP } from './lib.mjs';

const browser = await launch();
const tous = [];

// ───────────────────────────────────────────────────────────────────
// S1 — Coach+athlète bascule vers athlète, de bout en bout.
//      Attendu : segment visible → clic → dialog → confirmation →
//                inscrit en base ET retiré de l'encadrement.
// ───────────────────────────────────────────────────────────────────
{
  const s = new Scenario('S1 · Mathieu bascule coach → athlète (séance 8)');
  const { ctx, page } = await session(browser, 'mathieu@demo.club', MOBILE);
  await fiche(page, 8);

  const avantCoach = sql("SELECT COUNT(*) n FROM session_coach WHERE session_id=8 AND user_id=5");
  s.check('état initial : Mathieu encadre', avantCoach === '1', `coach=${avantCoach}`);
  s.check('segment de rôle affiché', await page.locator('.seg-roles').count() > 0);
  s.check('« J\'encadre » est un état, pas un bouton',
          await page.locator('span.seg-item.on').count() > 0, 'span, non cliquable');

  const btn = page.getByRole('button', { name: 'Je participe' });
  s.check('« Je participe » cliquable', await btn.isVisible());
  await s.shot(page, 's1-1-avant');

  await btn.click();
  await page.waitForTimeout(1000);
  const dlg = page.locator('.dialog, [role="dialog"]').first();
  s.check('dialog de confirmation ouvert', await dlg.isVisible());
  const texteDlg = (await dlg.innerText()).replace(/\s+/g, ' ');
  s.check('conséquence annoncée', /retirée/i.test(texteDlg), texteDlg.slice(0, 60));
  await s.shot(page, 's1-2-dialog');

  await dlg.getByRole('button', { name: /repasser athlète/i }).click();
  await page.waitForTimeout(1500);

  const apresCoach = sql("SELECT COUNT(*) n FROM session_coach WHERE session_id=8 AND user_id=5");
  const apresInsc  = sql("SELECT status FROM registrations WHERE session_id=8 AND user_id=5 AND status IN ('participating','waitlist')");
  s.check('retiré de l\'encadrement', apresCoach === '0', `coach=${apresCoach}`);
  s.check('inscrit comme athlète', apresInsc === 'participating', `statut=${apresInsc || 'aucun'}`);
  await s.shot(page, 's1-3-apres');

  // Remise en état : on annule l'inscription et on le remet coach.
  sql("DELETE FROM registrations WHERE session_id=8 AND user_id=5");
  sql("INSERT IGNORE INTO session_coach (session_id, user_id) VALUES (8,5)");
  const restaure = sql("SELECT COUNT(*) n FROM session_coach WHERE session_id=8 AND user_id=5");
  s.check('état restauré après test', restaure === '1');

  tous.push(s.report());
  await ctx.close();
}

// ───────────────────────────────────────────────────────────────────
// S2 — Séance hors catégorie : le bouton doit DISPARAÎTRE au profit
//      d'un message, et la barre fixe ne doit pas être une bande vide.
// ───────────────────────────────────────────────────────────────────
{
  const s = new Scenario('S2 · Mathieu sur séance jeunes (64) — hors sa catégorie');
  const { ctx, page } = await session(browser, 'mathieu@demo.club', MOBILE);
  await fiche(page, 64);

  s.check('« Je participe » absent', await page.getByRole('button', { name: 'Je participe' }).count() === 0);
  const barre = await barreMobile(page);
  s.check('barre d\'action non vide', !!barre && barre.length > 0, JSON.stringify(barre));
  s.check('motif de refus explicite', /catégorie/i.test(barre || ''), barre?.slice(0, 70));

  // La bande fixe ne doit pas être une bordure vide : on mesure sa hauteur réelle.
  const h = await page.locator('.fiche-actions-m').first().evaluate(el => el.getBoundingClientRect().height);
  s.check('hauteur de barre plausible (> 40px)', h > 40, `${Math.round(h)}px`);
  await s.shot(page, 's2-hors-categorie');

  tous.push(s.report());
  await ctx.close();
}

// ───────────────────────────────────────────────────────────────────
// S3 — Athlète suspendu : aucune action, message explicite.
// ───────────────────────────────────────────────────────────────────
{
  const s = new Scenario('S3 · Kevin (accès suspendu) sur séance 8');
  const { ctx, page } = await session(browser, 'kevin@demo.club', MOBILE);
  await fiche(page, 8);

  const barre = await barreMobile(page);
  s.check('message de suspension affiché', /suspendu/i.test(barre || ''), barre?.slice(0, 70));
  const boutons = await page.locator('.fiche-actions-m button:visible').count();
  s.check('aucun bouton d\'action', boutons === 0, `${boutons} bouton(s)`);
  await s.shot(page, 's3-suspendu');

  tous.push(s.report());
  await ctx.close();
}

// ───────────────────────────────────────────────────────────────────
// S4 — Parent garant : sélectionner un enfant ne doit PAS altérer
//      ses propres actions (régression trouvée en revue de code).
// ───────────────────────────────────────────────────────────────────
{
  const s = new Scenario('S4 · Sandrine (parent + athlète) — sujet enfant vs soi');
  const { ctx, page } = await session(browser, 'sandrine@demo.club', MOBILE);

  await fiche(page, 8);
  const soiAvant = await barreMobile(page);
  s.check('en son nom : action d\'inscription offerte', /inscrire/i.test(soiAvant || ''), soiAvant?.slice(0, 50));

  // Bascule sur l'enfant Jade (#35, Benjamins) — séance 8 est Adulte/Master.
  await page.goto('http://127.0.0.1:8000/seances/8?as=35', { waitUntil: 'networkidle' });
  const sujetEnfant = await barreMobile(page);
  s.check('sujet enfant : message accordé au prénom',
          /catégorie de Jade/i.test(sujetEnfant || ''), sujetEnfant?.slice(0, 70));
  await s.shot(page, 's4-sujet-enfant');

  // Retour à soi : les actions propres doivent revenir.
  await page.goto('http://127.0.0.1:8000/seances/8?as=34', { waitUntil: 'networkidle' });
  const soiApres = await barreMobile(page);
  s.check('retour à soi : action retrouvée', /inscrire/i.test(soiApres || ''), soiApres?.slice(0, 50));
  s.check('NON-RÉGRESSION : sujet enfant n\'a pas cassé la voie parent', soiAvant === soiApres);

  tous.push(s.report());
  await ctx.close();
}

// ───────────────────────────────────────────────────────────────────
// S5 — Admin pur : ni inscription athlète, ni CTA coach (bug corrigé).
// ───────────────────────────────────────────────────────────────────
{
  const s = new Scenario('S5 · Admin pur (ni coach ni athlète) — séances 8 et 29');
  const { ctx, page } = await session(browser, 'admin@demo.club', MOBILE);

  await fiche(page, 8);
  const b8 = await barreMobile(page);
  s.check('message « pas athlète »', /n'est pas athlète|pas athlète/i.test(b8 || ''), b8?.slice(0, 60));
  s.check('pas de bouton « M\'inscrire comme coach »',
          await page.getByRole('button', { name: /inscrire comme coach/i }).count() === 0);

  // Séance 29 : SANS aucun coach — c'est là que le CTA fautif apparaissait.
  await fiche(page, 29);
  s.check('séance sans coach : toujours pas de CTA coach',
          await page.getByRole('button', { name: /inscrire comme coach/i }).count() === 0);
  await s.shot(page, 's5-admin-pur');

  tous.push(s.report());
  await ctx.close();
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES SCÉNARIOS PASSENT' : '❌ AU MOINS UN SCÉNARIO ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
