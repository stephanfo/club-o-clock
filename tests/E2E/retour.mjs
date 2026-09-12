// Navigation « retour » — les deux racines auditées en #25 et #26.
//
// Ces deux défauts sont INVISIBLES à `composer check` par construction : ils vivent dans la pile
// d'historique et dans le cache de snapshots de `wire:navigate`, que PHPUnit ne voit pas. La règle
// « une correction de bug apporte un test qui échouait avant » (CLAUDE.md) passe donc ici par le
// navigateur.
//
// NON destructifs : chaque scénario restaure ce qu'il modifie (inscriptions, titres, journaux).
import { launch, session, fiche, sql, seanceFuture, ligne, Scenario, MOBILE, DESKTOP, BASE, repereJournaux, purgeJournaux } from './lib.mjs';

const browser = await launch();
const tous = [];

// ── R1 · #25 · Retour au planning après inscription : l'état doit être à jour ──
{
  const s = new Scenario('R1 · Retour au planning après inscription — la carte dit « Tu participes »');
  const marie = Number(sql("SELECT id FROM users WHERE email='marie@demo.club'"));

  // Séance future ouverte à Marie, avec de la place, sans collision de quota ET sans chevauchement
  // horaire : le dialog de dépassement comme le `wire:confirm` de chevauchement rendraient
  // l'inscription conditionnelle, et ce n'est pas le sujet ici.
  const cible = seanceFuture(`
    EXISTS (SELECT 1 FROM session_category c JOIN user_category u ON u.category_id=c.category_id
            WHERE c.session_id=sessions.id AND u.user_id=${marie})
    AND (capacity IS NULL OR (SELECT COUNT(*) FROM registrations r
         WHERE r.session_id=sessions.id AND r.status='participating') < capacity)
    AND (quota_tag_id IS NULL OR NOT EXISTS (
         SELECT 1 FROM registrations r JOIN sessions s2 ON s2.id=r.session_id
         WHERE r.user_id=${marie} AND r.status='participating'
           AND s2.quota_tag_id = sessions.quota_tag_id
           AND YEARWEEK(s2.start_at,3) = YEARWEEK(sessions.start_at,3)))
    AND NOT EXISTS (SELECT 1 FROM registrations r JOIN sessions s3 ON s3.id=r.session_id
         WHERE r.user_id=${marie} AND r.status='participating'
           AND s3.start_at < DATE_ADD(sessions.start_at, INTERVAL sessions.duration_min MINUTE)
           AND DATE_ADD(s3.start_at, INTERVAL s3.duration_min MINUTE) > sessions.start_at)`);

  const [jour] = ligne(`SELECT DATE(start_at) FROM sessions WHERE id=${cible}`, 'la date de la séance');
  const avant = sql(`SELECT status FROM registrations WHERE session_id=${cible} AND user_id=${marie}`);
  sql(`DELETE FROM registrations WHERE session_id=${cible} AND user_id=${marie}`);
  const repere = repereJournaux();

  const { ctx, page } = await session(browser, 'marie@demo.club', MOBILE);
  await page.goto(`${BASE}/planning?view=week&anchor=${jour}`, { waitUntil: 'networkidle' });

  const carte = page.locator(`a[href*="/seances/${cible}"]:visible`).first();
  s.check('la carte de la séance est sur le planning', await carte.isVisible().catch(() => false));
  // Assertion négative appariée à son contrôle positif : la carte est là, et elle ne porte pas
  // encore l'état de participation.
  s.check('avant inscription, la carte ne dit pas « participes »',
          !/participes/i.test(await carte.innerHTML()));

  await carte.click();
  await page.waitForTimeout(1500);
  const inscrire = page.getByRole('button', { name: /s'inscrire/i }).first();
  s.check('la fiche propose de s\'inscrire', await inscrire.isVisible().catch(() => false));
  await inscrire.click();
  await page.waitForTimeout(1500);

  const statut = sql(`SELECT status FROM registrations WHERE session_id=${cible} AND user_id=${marie}`);
  s.check('inscription enregistrée en base', statut === 'participating', `statut=${statut || 'aucun'}`);

  // Le geste de l'issue : le chevron de la topbar.
  await page.locator('a[onclick*="clubBack"]:visible').first().click();
  await page.waitForTimeout(2000);

  s.check('on est bien revenu sur le planning', new URL(page.url()).pathname === '/planning', page.url());
  const carteApres = page.locator(`a[href*="/seances/${cible}"]:visible`).first();
  s.check('au retour, la carte porte « Tu participes »',
          /participes/i.test(await carteApres.innerHTML().catch(() => '')));
  // La capture doit MONTRER la carte à jour : sans ce cadrage, elle tombait sur le haut de la
  // semaine et ne prouvait rien à l'œil.
  await carteApres.evaluate((el) => el.scrollIntoView({ block: 'center' })).catch(() => {});
  await page.waitForTimeout(500);
  await s.shot(page, 'r1-retour-planning-a-jour');
  s.checkJs(page);
  await ctx.close();

  // Remise en état.
  sql(`DELETE FROM registrations WHERE session_id=${cible} AND user_id=${marie}`);
  if (avant) {
    sql(`INSERT INTO registrations (session_id, user_id, status, registered_at, created_at, updated_at)
         VALUES (${cible}, ${marie}, '${avant}', NOW(), NOW(), NOW())`);
  }
  purgeJournaux(repere);
  tous.push(s.report());
}

// ── R2 · #26 · Après enregistrement d'une édition, « Planning » mène au planning ──
{
  const s = new Scenario('R2 · Édition de séance enregistrée — le retour « Planning » tient parole');
  const admin = Number(sql("SELECT id FROM users WHERE email='admin@demo.club'"));
  const cible = seanceFuture('1=1');
  const [titreAvant, jour] = ligne(`SELECT title, DATE(start_at) FROM sessions WHERE id=${cible}`, 'la séance à éditer');
  const repere = repereJournaux();

  const { ctx, page } = await session(browser, 'admin@demo.club', DESKTOP);
  // On ARRIVE par le planning : c'est le parcours de l'issue, et c'est ce que le libellé promet.
  await page.goto(`${BASE}/planning?view=week&anchor=${jour}`, { waitUntil: 'networkidle' });
  await page.locator(`a[href*="/seances/${cible}"]:visible`).first().click();
  await page.waitForTimeout(1500);
  await page.goto(`${BASE}/seances/${cible}/modifier`, { waitUntil: 'networkidle' });

  await page.locator('input[wire\\:model\\.blur="title"], input[wire\\:model="title"]').first()
    .fill(titreAvant + ' (R2)');
  await page.waitForTimeout(700);
  await page.getByRole('button', { name: /^enregistrer$/i }).first().click();
  await page.waitForTimeout(1500);
  // Édition structurelle → dialog « Notifier les inscrits ? ». On enregistre SANS notifier.
  const dlg = page.locator('.dialog, [role="dialog"]').first();
  if (await dlg.isVisible().catch(() => false)) {
    await dlg.getByRole('button', { name: /sans notifier|ne pas notifier|silencieux/i }).first().click();
    await page.waitForTimeout(1500);
  }

  s.check('enregistrement effectif', sql(`SELECT title FROM sessions WHERE id=${cible}`).endsWith('(R2)'));
  s.check('on est bien sur la fiche après enregistrement',
          new URL(page.url()).pathname === `/seances/${cible}`, page.url());

  // Le lien de RETOUR, pas l'entrée « Planning » du menu latéral : on cible le motif imposé par
  // CLAUDE.md (onclick clubBack + href de repli).
  const retour = page.locator('a[onclick*="clubBack"]:visible').first();
  s.check('le bouton de retour annonce le planning', /planning/i.test(await retour.innerText()));
  await retour.click();
  await page.waitForTimeout(2000);
  s.check('le retour « Planning » mène au planning, pas au formulaire',
          new URL(page.url()).pathname === '/planning', page.url());
  await s.shot(page, 'r2-retour-planning-apres-edition');
  s.checkJs(page);
  await ctx.close();

  sql(`UPDATE sessions SET title=${JSON.stringify(titreAvant).replace(/^"|"$/g, "'")} WHERE id=${cible}`);
  purgeJournaux(repere);
  tous.push(s.report());
}

// ── R3 · #26 · Enregistrements successifs d'un parcours : un seul retour suffit ──
{
  const s = new Scenario('R3 · Parcours enregistré trois fois — un seul retour sort de l\'écran');
  const [routeId, routeNom] = ligne(
    'SELECT id, name FROM gpx_routes WHERE archived_at IS NULL ORDER BY id LIMIT 1', 'un parcours');
  const repere = repereJournaux();

  const { ctx, page } = await session(browser, 'admin@demo.club', DESKTOP);
  await page.goto(`${BASE}/parcours`, { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/parcours/${routeId}/modifier`, { waitUntil: 'networkidle' });

  for (let i = 0; i < 3; i++) {
    await page.getByRole('button', { name: /^enregistrer$/i }).first().click();
    await page.waitForTimeout(1500);
  }
  s.check('les trois enregistrements ont abouti', /parcours/i.test(new URL(page.url()).pathname), page.url());
  // Contrôle positif : le parcours est intact, on n'a pas cassé l'enregistrement lui-même.
  s.check('le parcours garde son nom', sql(`SELECT name FROM gpx_routes WHERE id=${routeId}`) === routeNom);

  const retour = page.locator('a[onclick*="clubBack"]:visible').first();
  s.check('le bouton de retour annonce les parcours', /parcours/i.test(await retour.innerText()));
  await retour.click();
  await page.waitForTimeout(2000);
  s.check('un seul retour sort du formulaire',
          new URL(page.url()).pathname !== `/parcours/${routeId}/modifier`, page.url());
  await s.shot(page, 'r3-retour-apres-enregistrements');
  s.checkJs(page);
  await ctx.close();

  purgeJournaux(repere);
  tous.push(s.report());
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES RETOURS PASSENT' : '❌ AU MOINS UN RETOUR ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
