import { launch, session, fiche, sql, seance, seanceFuture, barreMobile, Scenario, MOBILE, DESKTOP, BASE, repereJournaux, purgeJournaux } from './lib.mjs';

const browser = await launch();
const tous = [];

// ───────────────────────────────────────────────────────────────────
// S1 — Coach+athlète bascule vers athlète, de bout en bout.
//      Attendu : segment visible → clic → dialog → confirmation →
//                inscrit en base ET retiré de l'encadrement.
// ───────────────────────────────────────────────────────────────────
{
  // Cible dérivée (cf. lib.seance) : Mathieu doit ENCADRER une séance future d'entraînement ciblant
  // une de ses catégories. Un id en dur pointait une séance déjà commencée selon l'heure du run.
  const mathieu = Number(sql("SELECT id FROM users WHERE email='mathieu@demo.club'"));
  const s8 = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
      AND EXISTS (SELECT 1 FROM session_coach sc WHERE sc.session_id=sessions.id AND sc.user_id=${mathieu})
      AND EXISTS (SELECT 1 FROM session_category k JOIN user_category uc ON uc.category_id=k.category_id
                  WHERE k.session_id=sessions.id AND uc.user_id=${mathieu})`);

  const s = new Scenario(`S1 · Mathieu bascule coach → athlète (séance ${s8})`);
  const journaux = repereJournaux();   // la bascule écrit dans les trois journaux (cf. purgeJournaux)
  const { ctx, page } = await session(browser, 'mathieu@demo.club', MOBILE);
  await fiche(page, s8);

  const avantCoach = sql(`SELECT COUNT(*) n FROM session_coach WHERE session_id=${s8} AND user_id=${mathieu}`);
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

  const apresCoach = sql(`SELECT COUNT(*) n FROM session_coach WHERE session_id=${s8} AND user_id=${mathieu}`);
  const apresInsc  = sql(`SELECT status FROM registrations WHERE session_id=${s8} AND user_id=${mathieu} AND status IN ('participating','waitlist')`);
  s.check('retiré de l\'encadrement', apresCoach === '0', `coach=${apresCoach}`);
  s.check('inscrit comme athlète', apresInsc === 'participating', `statut=${apresInsc || 'aucun'}`);
  await s.shot(page, 's1-3-apres');

  // Remise en état : on annule l'inscription et on le remet coach.
  sql(`DELETE FROM registrations WHERE session_id=${s8} AND user_id=${mathieu}`);
  sql(`INSERT IGNORE INTO session_coach (session_id, user_id) VALUES (${s8},${mathieu})`);
  s.checkJs(page);
  purgeJournaux(journaux);
  const restaure = sql(`SELECT COUNT(*) n FROM session_coach WHERE session_id=${s8} AND user_id=${mathieu}`);
  s.check('état restauré après test', restaure === '1');
  s.check('journaux restaurés (audit, activité, envois)',
          sql(`SELECT (SELECT COUNT(*) FROM audit_logs WHERE id>${journaux.audit})
                    + (SELECT COUNT(*) FROM activity_logs WHERE id>${journaux.activite})
                    + (SELECT COUNT(*) FROM notification_outbox WHERE id>${journaux.envois}) n`) === '0');

  tous.push(s.report());
  await ctx.close();
}

// ───────────────────────────────────────────────────────────────────
// S2 — Séance hors catégorie : le bouton doit DISPARAÎTRE au profit
//      d'un message, et la barre fixe ne doit pas être une bande vide.
// ───────────────────────────────────────────────────────────────────
{
  // Séance future qui ne cible AUCUNE catégorie de Mathieu (§4.5).
  const m2 = Number(sql("SELECT id FROM users WHERE email='mathieu@demo.club'"));
  const hors = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
      AND EXISTS (SELECT 1 FROM session_category k WHERE k.session_id=sessions.id)
      AND NOT EXISTS (SELECT 1 FROM session_category k JOIN user_category uc ON uc.category_id=k.category_id
                      WHERE k.session_id=sessions.id AND uc.user_id=${m2})`);
  const s = new Scenario(`S2 · Mathieu sur séance hors sa catégorie (${hors})`);
  const { ctx, page } = await session(browser, 'mathieu@demo.club', MOBILE);
  await fiche(page, hors);

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
  // Séance future ciblant la catégorie de Kevin : le refus doit venir de la SUSPENSION (§4.4),
  // pas d'un motif de catégorie ni d'une séance déjà commencée. Et une séance où il n'est PAS
  // déjà inscrit : un athlète suspendu garde le droit de se désinscrire de ce qu'il a réservé
  // avant sa suspension, donc « SE DÉSINSCRIRE » y est légitime et le scénario testait l'inverse
  // de son intention selon la séance que le jeu de démo lui attribuait en premier.
  const kevin = Number(sql("SELECT id FROM users WHERE email='kevin@demo.club'"));
  const sK = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
      AND EXISTS (SELECT 1 FROM session_category k JOIN user_category uc ON uc.category_id=k.category_id
                  WHERE k.session_id=sessions.id AND uc.user_id=${kevin})
      AND NOT EXISTS (SELECT 1 FROM registrations r WHERE r.session_id=sessions.id
                      AND r.user_id=${kevin} AND r.status <> 'cancelled')`);
  const s = new Scenario(`S3 · Kevin (accès suspendu) sur séance ${sK}`);
  const { ctx, page } = await session(browser, 'kevin@demo.club', MOBILE);
  await fiche(page, sK);

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
  // Cibles dérivées : Sandrine et son enfant Jade, et une séance future que Sandrine peut rejoindre
  // mais qui ne cible PAS la catégorie de Jade (c'est ce décalage que le scénario exerce).
  const sandrine = Number(sql("SELECT id FROM users WHERE email='sandrine@demo.club'"));
  const jade = Number(sql(`SELECT id FROM users WHERE guardian_id=${sandrine} AND first_name='Jade'`));
  const s4 = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
      AND EXISTS (SELECT 1 FROM session_category k JOIN user_category uc ON uc.category_id=k.category_id
                  WHERE k.session_id=sessions.id AND uc.user_id=${sandrine})
      AND NOT EXISTS (SELECT 1 FROM session_category k JOIN user_category uc ON uc.category_id=k.category_id
                      WHERE k.session_id=sessions.id AND uc.user_id=${jade})`);

  const s = new Scenario(`S4 · Sandrine (parent + athlète) — sujet enfant vs soi (séance ${s4})`);
  const { ctx, page } = await session(browser, 'sandrine@demo.club', MOBILE);

  await fiche(page, s4);
  const soiAvant = await barreMobile(page);
  s.check('en son nom : action d\'inscription offerte', /inscrire/i.test(soiAvant || ''), soiAvant?.slice(0, 50));

  await page.goto(`${BASE}/seances/${s4}?as=${jade}`, { waitUntil: 'networkidle' });
  const sujetEnfant = await barreMobile(page);
  s.check('sujet enfant : message accordé au prénom',
          /catégorie de Jade/i.test(sujetEnfant || ''), sujetEnfant?.slice(0, 70));
  await s.shot(page, 's4-sujet-enfant');

  // Retour à soi : les actions propres doivent revenir.
  await page.goto(`${BASE}/seances/${s4}?as=${sandrine}`, { waitUntil: 'networkidle' });
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
  const sA = seanceFuture(); // le refus porte sur les RÔLES de l'admin, pas sur l'heure
  const s = new Scenario(`S5 · Admin pur (ni coach ni athlète) — séance ${sA}`);
  const { ctx, page } = await session(browser, 'admin@demo.club', MOBILE);

  await fiche(page, sA);
  const b8 = await barreMobile(page);
  s.check('message « pas athlète »', /n'est pas athlète|pas athlète/i.test(b8 || ''), b8?.slice(0, 60));
  s.check('pas de bouton « M\'inscrire comme coach »',
          await page.getByRole('button', { name: /inscrire comme coach/i }).count() === 0);

  // Séance SANS aucun coach — c'est là que le CTA fautif apparaissait. Dérivée : ce qui compte est
  // l'absence d'encadrant, pas l'identité de la séance.
  const sansCoach = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
      AND NOT EXISTS (SELECT 1 FROM session_coach sc WHERE sc.session_id=sessions.id)`);
  await fiche(page, sansCoach);
  s.check('séance sans coach : toujours pas de CTA coach',
          await page.getByRole('button', { name: /inscrire comme coach/i }).count() === 0);
  await s.shot(page, 's5-admin-pur');

  tous.push(s.report());
  await ctx.close();
}

// ───────────────────────────────────────────────────────────────────
// S23 — Suppression définitive d'une séance annulée (§4.7, admin).
//       Le scénario CRÉE son propre sujet : la suppression est alors
//       sa propre restauration — rien à remettre en place ensuite.
//       Ce que PHPUnit ne voit pas : que l'entrée n'existe qu'à l'état
//       annulé, que le bouton reste désarmé tant que la case n'est pas
//       cochée, et qu'on atterrit sur le planning et non sur une fiche
//       dont le modèle vient de disparaître.
// ───────────────────────────────────────────────────────────────────
{
  const s = new Scenario('S23 · Suppression définitive d\'une séance annulée');
  const journaux = repereJournaux();

  // Sujet jetable, jamais le jeu de démo : on va l'effacer pour de bon.
  const admin = sql("SELECT id FROM users WHERE email='admin@demo.club'");
  const titre = 'Doublon E2E à effacer';
  sql(`INSERT INTO sessions (kind, title, start_at, duration_min, visibility, created_by, created_at, updated_at)
       VALUES ('training', '${titre}', DATE_ADD(NOW(), INTERVAL 21 DAY), 60, 'all', ${admin}, NOW(), NOW())`);
  const cible = Number(sql(`SELECT id FROM sessions WHERE title='${titre}' ORDER BY id DESC LIMIT 1`));

  // Alerte déjà « envoyée » ne portant QUE le session_id — le cas que la suppression doit rendre
  // autonome (les event_created de production n'ont rien d'autre dans leur payload).
  sql(`INSERT INTO notification_outbox (type, channel, payload, user_id, status, sent_at, created_at, updated_at)
       VALUES ('event_created', 'push', '{"session_id": ${cible}}', ${admin}, 'sent', NOW(), NOW(), NOW())`);
  const alerte = Number(sql('SELECT MAX(id) FROM notification_outbox'));

  // Desktop : l'entrée de suppression est un geste d'administration, absent du format mobile.
  const { ctx, page } = await session(browser, 'admin@demo.club', DESKTOP);

  await fiche(page, cible);
  s.check('séance vivante : pas d\'entrée de suppression',
          await page.locator('button[wire\\:click="openDeleteConfirm"]').count() === 0);

  // On annule par l'écran, pas en base : c'est le premier temps du geste, et il conditionne le second.
  await page.locator('button[wire\\:click="openCancelConfirm"]:visible').first().click();
  await page.waitForTimeout(700);
  await page.locator('[wire\\:click="$toggle(\'cancelCheck\')"]:visible').first().click();
  await page.waitForTimeout(400);
  await page.locator('button[wire\\:click="cancel"]:visible').first().click();
  await page.waitForTimeout(1500);

  s.check('la séance est annulée en base',
          sql(`SELECT cancelled_at IS NOT NULL FROM sessions WHERE id=${cible}`) === '1');
  s.check('l\'entrée de suppression apparaît une fois annulée',
          await page.locator('button[wire\\:click="openDeleteConfirm"]').count() === 1);
  await s.shot(page, 's23-annulee-avant-suppression');

  await page.locator('button[wire\\:click="openDeleteConfirm"]:visible').first().click();
  await page.waitForTimeout(700);
  const dlg = page.locator('.dialog, [role="dialog"]').first();
  s.check('le dialog annonce l\'irréversibilité',
          /irréversible/i.test((await dlg.innerText()).replace(/\s+/g, ' ')));
  s.check('bouton non armé sans accusé de réception',
          await dlg.locator('button[wire\\:click="delete"]').count() === 0);
  await s.shot(page, 's23-dialog-suppression');

  await dlg.locator('[wire\\:click="$toggle(\'deleteCheck\')"]').first().click();
  await page.waitForTimeout(500);
  s.check('la case cochée arme le bouton',
          await dlg.locator('button[wire\\:click="delete"]').count() === 1);

  await dlg.locator('button[wire\\:click="delete"]').first().click();
  await page.waitForTimeout(1800);

  s.check('on atterrit sur le planning, pas sur une fiche morte',
          /\/planning\/?$/.test(new URL(page.url()).pathname + '/') || page.url().includes('/planning'),
          page.url());
  s.check('la séance a disparu de la base',
          sql(`SELECT COUNT(*) FROM sessions WHERE id=${cible}`) === '0');
  s.check('la trace d\'audit survit avec sa cible et le titre',
          sql(`SELECT COUNT(*) FROM audit_logs WHERE action='delete_session' AND target_type='session' AND target_id=${cible}`) === '1' &&
          sql(`SELECT motif FROM audit_logs WHERE action='delete_session' AND target_id=${cible}`).includes(titre));
  // JSON_UNQUOTE : Laravel échappe l'unicode à l'écriture (« \\u00e0 »), JSON_EXTRACT rend la
  // forme échappée. Sans le décodage, l'assertion échouerait sur un titre accentué alors que le
  // payload est juste — et tous les vrais titres de séance en portent.
  s.check('l\'alerte garde son titre et perd son lien',
          sql(`SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$.session_title')) FROM notification_outbox WHERE id=${alerte}`).includes(titre) &&
          sql(`SELECT JSON_CONTAINS_PATH(payload,'one','$.session_id') FROM notification_outbox WHERE id=${alerte}`) === '0');
  await s.shot(page, 's23-apres-suppression');
  s.checkJs(page);

  // Restauration : la séance s'est effacée elle-même, restent les journaux et l'alerte de test.
  purgeJournaux(journaux);
  s.check('état restauré (séance jetable et journaux)',
          sql(`SELECT COUNT(*) FROM sessions WHERE title='${titre}'`) === '0' &&
          sql(`SELECT COUNT(*) FROM notification_outbox WHERE id=${alerte}`) === '0');

  tous.push(s.report());
  await ctx.close();
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES SCÉNARIOS PASSENT' : '❌ AU MOINS UN SCÉNARIO ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
