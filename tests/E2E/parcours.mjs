// Scénarios complémentaires — parcours critiques et cas limites (PLAN_TESTS.md §1 à §8).
// NON destructifs : chaque scénario restaure ce qu'il modifie. Voir destructif.mjs pour le reste.
import { launch, session, fiche, sql, barreMobile, Scenario, MOBILE, DESKTOP, BASE } from './lib.mjs';

const browser = await launch();
const tous = [];

// ── S7 · Cloisonnement des pages d'info par rôle (PRD §4.19) ──────────
{
  const s = new Scenario('S7 · Pages d\'info — cloisonnement par rôle');
  const attendus = [
    ['marie@demo.club',   'athlète', 2, ['Sport Attitude', 'Aquagliss'], ['portail', 'extranet']],
    ['vincent@demo.club', 'coach',   3, ['Sport Attitude', 'Aquagliss', 'portail'], ['extranet']],
    ['admin@demo.club',   'admin',   4, ['Sport Attitude', 'Aquagliss', 'portail', 'extranet'], []],
  ];
  for (const [email, role, n, visibles, invisibles] of attendus) {
    const { ctx, page } = await session(browser, email, MOBILE);
    await page.goto(`${BASE}/infos`, { waitUntil: 'networkidle' });
    const txt = (await page.locator('body').innerText()).toLowerCase();
    for (const v of visibles) s.check(`${role} voit « ${v} »`, txt.includes(v.toLowerCase()));
    for (const i of invisibles) s.check(`${role} ne voit PAS « ${i} »`, !txt.includes(i.toLowerCase()));
    await ctx.close();
  }
  tous.push(s.report());
}

// ── S8 · Quota hebdomadaire : dialog puis file « quota » (PRD §4.9) ───
{
  const s = new Scenario('S8 · Quota NAT (1/sem) — dialog de dépassement');
  const { ctx, page } = await session(browser, 'marie@demo.club', MOBILE);

  // Marie participe déjà à la natation du 19/08 (séance 8). La séance 36 est une
  // 2ᵉ natation de la même semaine → quota atteint.
  const dejaNat = sql("SELECT COUNT(*) n FROM registrations r JOIN sessions s ON s.id=r.session_id WHERE r.user_id=(SELECT id FROM users WHERE email='marie@demo.club') AND s.id=8 AND r.status='participating'");
  s.check('prérequis : 1 natation déjà validée', dejaNat === '1', `n=${dejaNat}`);

  // On retire sa waitlist sur 36 pour tester l'inscription à froid.
  const avant = sql("SELECT status FROM registrations WHERE session_id=36 AND user_id=(SELECT id FROM users WHERE email='marie@demo.club')");
  sql("DELETE FROM registrations WHERE session_id=36 AND user_id=(SELECT id FROM users WHERE email='marie@demo.club')");

  await fiche(page, 36);
  const btn = page.getByRole('button', { name: /s'inscrire|liste d'attente/i }).first();
  s.check('action d\'inscription proposée', await btn.isVisible().catch(() => false));
  await btn.click();
  await page.waitForTimeout(1200);

  const dlg = page.locator('.dialog, [role="dialog"]').first();
  const dlgVisible = await dlg.isVisible().catch(() => false);
  s.check('dialog de quota ouvert', dlgVisible);
  if (dlgVisible) {
    const t = (await dlg.innerText()).replace(/\s+/g, ' ');
    s.check('le dialog parle bien du quota', /quota/i.test(t), t.slice(0, 80));
    await s.shot(page, 's8-quota-dialog');
    // On annule : aucune inscription ne doit être créée.
    const annuler = dlg.getByRole('button', { name: /annuler/i }).first();
    if (await annuler.count()) { await annuler.click(); await page.waitForTimeout(800); }
  }
  const apresAnnul = sql("SELECT COUNT(*) n FROM registrations WHERE session_id=36 AND user_id=(SELECT id FROM users WHERE email='marie@demo.club')");
  s.check('annulation : aucune inscription créée', apresAnnul === '0', `n=${apresAnnul}`);

  // Remise en état : on restaure la waitlist d'origine.
  if (avant) sql(`INSERT INTO registrations (session_id, user_id, status, registered_at, created_at, updated_at) SELECT 36, id, '${avant}', NOW(), NOW(), NOW() FROM users WHERE email='marie@demo.club'`);
  const restaure = sql("SELECT status FROM registrations WHERE session_id=36 AND user_id=(SELECT id FROM users WHERE email='marie@demo.club')");
  s.check('état restauré', restaure === avant, `${restaure || 'aucun'} (attendu ${avant || 'aucun'})`);

  tous.push(s.report());
  await ctx.close();
}

// ── S9 · Filtrage catégoriel du planning (PRD §4.5) ───────────────────
{
  const s = new Scenario('S9 · Planning — filtrage par catégorie');
  // Marie (Adulte) ne doit PAS voir les séances jeunes ; Enzo (Cadets) l'inverse.
  for (const [email, qui, doitVoir, neDoitPasVoir] of [
    ['marie@demo.club', 'Marie (Adulte)', 'Natation samedi matin — adultes', 'Natation samedi matin — jeunes'],
    ['enzo@demo.club',  'Enzo (Cadets)',  'Natation samedi matin — jeunes',  'Natation samedi matin — adultes'],
  ]) {
    const { ctx, page } = await session(browser, email, MOBILE);
    await page.goto(`${BASE}/planning`, { waitUntil: 'networkidle' });
    // Vue semaine du 22/08 : on navigue jusqu'à trouver les séances du samedi.
    const txt = await page.locator('body').innerText();
    s.check(`${qui} voit « ${doitVoir.slice(-8)} »`, txt.includes(doitVoir), '');
    s.check(`${qui} ne voit PAS « ${neDoitPasVoir.slice(-8)} »`, !txt.includes(neDoitPasVoir), '');
    await ctx.close();
  }
  tous.push(s.report());
}

// ── S10 · Séance annulée : bandeau, aucune action (PRD §4.7) ──────────
{
  const s = new Scenario('S10 · Séance annulée (15) — bandeau et gel des actions');
  const { ctx, page } = await session(browser, 'marie@demo.club', MOBILE);
  await fiche(page, 15);
  const txt = (await page.locator('body').innerText()).toLowerCase();
  s.check('bandeau d\'annulation présent', /annul/i.test(txt));
  const barre = await barreMobile(page);
  s.check('pas d\'action d\'inscription', !/s'inscrire|se désinscrire/i.test(barre || ''), barre?.slice(0, 60));
  await s.shot(page, 's10-annulee');
  tous.push(s.report());
  await ctx.close();
}

// ── S11 · Séance passée : inscriptions closes (PRD §4.9) ──────────────
{
  const s = new Scenario('S11 · Séance passée (71) — inscriptions closes, débrief ouvert');
  const { ctx, page } = await session(browser, 'marie@demo.club', MOBILE);
  await fiche(page, 71);
  const barre = await barreMobile(page);
  s.check('mention « commencée »', /commencée|close/i.test(barre || ''), barre?.slice(0, 60));
  const txt = await page.locator('body').innerText();
  s.check('onglet Débriefs présent (compétition passée)', /débrief/i.test(txt));
  tous.push(s.report());
  await ctx.close();
}

// ── S12 · Parent pur : agit pour l'enfant, pas pour lui (PRD §4.2) ────
{
  const s = new Scenario('S12 · Olivier (parent pur, aucun rôle) — séance 8');
  const { ctx, page } = await session(browser, 'olivier@demo.club', MOBILE);

  await page.goto(`${BASE}/enfants`, { waitUntil: 'networkidle' });
  const enfants = await page.locator('body').innerText();
  s.check('accède à « Mes enfants »', /théo|theo/i.test(enfants));

  await fiche(page, 8);
  const barre = await barreMobile(page);
  s.check('ne peut pas s\'inscrire lui-même', /n'est pas athlète|pas athlète/i.test(barre || ''), barre?.slice(0, 60));
  await s.shot(page, 's12-parent-pur');
  tous.push(s.report());
  await ctx.close();
}

// ── S13 · Cloisonnement admin : un coach est refusé (PRD §4.17) ───────
{
  const s = new Scenario('S13 · Écrans admin — 403 pour un coach');
  const { ctx, page } = await session(browser, 'vincent@demo.club', DESKTOP);
  for (const p of ['/admin/dashboard', '/admin/adherents', '/admin/journaux', '/admin/parametres', '/admin/infos']) {
    const resp = await page.goto(BASE + p, { waitUntil: 'domcontentloaded' });
    s.check(`coach refusé sur ${p}`, resp.status() === 403, `HTTP ${resp.status()}`);
  }
  tous.push(s.report());
  await ctx.close();
}

// ── S14 · Admin : écrans clés accessibles et non vides ────────────────
{
  const s = new Scenario('S14 · Admin — écrans clés rendus');
  const { ctx, page } = await session(browser, 'admin@demo.club', DESKTOP);
  for (const [p, attendu] of [
    ['/admin/dashboard', /tableau de bord|dashboard|séances/i],
    ['/admin/adherents', /adhérent|membre/i],
    ['/admin/journaux',  /journal|audit|activité/i],
    ['/admin/modeles',   /modèle|génération/i],
    ['/admin/envois',    /envoi|notification/i],
  ]) {
    const resp = await page.goto(BASE + p, { waitUntil: 'networkidle' });
    const txt = await page.locator('body').innerText();
    s.check(`${p} → 200 et contenu attendu`, resp.status() === 200 && attendu.test(txt), `HTTP ${resp.status()}`);
  }
  await s.shot(page, 's14-admin-dashboard');
  tous.push(s.report());
  await ctx.close();
}

// ── S15 · Suspendu invisible dans le picker coach (PLAN_TESTS §2/§3.4) ─
{
  const s = new Scenario('S15 · Kevin (suspendu) absent du sélecteur « Inscrire un athlète »');
  const { ctx, page } = await session(browser, 'vincent@demo.club', DESKTOP);
  await fiche(page, 8);
  const btn = page.getByRole('button', { name: /inscrire un athlète/i }).first();
  s.check('bouton « Inscrire un athlète » présent', await btn.isVisible().catch(() => false));
  await btn.click();
  await page.waitForTimeout(1200);
  const modale = page.locator('.dialog, [role="dialog"]').first();
  const brut = await modale.innerText().catch(() => '');
  // Une liste tronquée ferait passer « Kevin absent » pour de mauvaises raisons :
  // on isole les vrais noms et on exige un volume plausible.
  const noms = brut.split('\n').filter(l => l.includes(' ') && /^[A-ZÉÈÀ]/.test(l));
  s.check('sélecteur ouvert et peuplé', noms.length > 10, `${noms.length} athlètes`);
  s.check('Kevin (suspendu) absent', !noms.some(n => /kevin/i.test(n)));
  s.check('prérequis : Kevin est bien suspendu en base',
          sql("SELECT athlete_access_suspended s FROM users WHERE email='kevin@demo.club'") === '1');
  s.check('contrôle positif : un athlète éligible est proposé', noms.some(n => /camille/i.test(n)));
  // Les déjà-inscrits doivent aussi être exclus (§4.9.7).
  const inscrits = sql("SELECT CONCAT(u.first_name,' ',u.last_name) n FROM registrations r JOIN users u ON u.id=r.user_id WHERE r.session_id=8 AND r.status='participating'").split('\n').filter(Boolean);
  s.check('déjà-inscrits exclus du sélecteur',
          inscrits.every(i => !noms.some(n => n.includes(i))), inscrits.join(', '));
  await s.shot(page, 's15-picker');
  tous.push(s.report());
  await ctx.close();
}

// ── S16 · Liste d'attente sur séance pleine (PRD §4.9) ────────────────
{
  const s = new Scenario('S16 · Séance pleine (29) — rejoindre puis quitter la file');
  // Noah (Cadets) est éligible à la séance jeunes et non inscrit.
  const cap = sql("SELECT capacity c FROM sessions WHERE id=29");
  const pris = sql("SELECT COUNT(*) n FROM registrations WHERE session_id=29 AND status='participating'");
  s.check('prérequis : séance saturée', cap === pris, `${pris}/${cap}`);
  const avant = sql("SELECT COUNT(*) n FROM registrations WHERE session_id=29 AND user_id=(SELECT id FROM users WHERE email='noah.faure@demo.club')");
  s.check('prérequis : Noah non inscrit', avant === '0');

  const { ctx, page } = await session(browser, 'noah.faure@demo.club', MOBILE);
  await fiche(page, 29);
  const txt = await page.locator('body').innerText();
  s.check('séance annoncée complète', /complet/i.test(txt));

  const btn = page.getByRole('button', { name: /liste d'attente/i }).first();
  s.check('bouton « Rejoindre la liste d\'attente » proposé', await btn.isVisible().catch(() => false));
  await s.shot(page, 's16-complet');
  await btn.click();
  await page.waitForTimeout(1500);

  const statut = sql("SELECT status FROM registrations WHERE session_id=29 AND user_id=(SELECT id FROM users WHERE email='noah.faure@demo.club')");
  s.check('inscrit en liste d\'attente (pas participant)', statut === 'waitlist', `statut=${statut || 'aucun'}`);

  // Remise en état.
  sql("DELETE FROM registrations WHERE session_id=29 AND user_id=(SELECT id FROM users WHERE email='noah.faure@demo.club')");
  s.check('état restauré',
         sql("SELECT COUNT(*) n FROM registrations WHERE session_id=29 AND user_id=(SELECT id FROM users WHERE email='noah.faure@demo.club')") === '0');
  tous.push(s.report());
  await ctx.close();
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES PARCOURS PASSENT' : '❌ AU MOINS UN PARCOURS ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
