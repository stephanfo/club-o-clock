// Scénarios complémentaires — parcours critiques et cas limites (PLAN_TESTS.md §1 à §8).
// NON destructifs : chaque scénario restaure ce qu'il modifie. Voir destructif.mjs pour le reste.
import { launch, session, fiche, sql, seance, seanceFuture, barreMobile, Scenario, MOBILE, DESKTOP, BASE, repereJournaux, purgeJournaux } from './lib.mjs';

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

  // Cible dérivée, pas codée en dur : il faut une séance FUTURE portant le même tag de quota
  // qu'une séance à laquelle Marie participe déjà DANS LA MÊME SEMAINE — c'est cette collision qui
  // déclenche le dialog. L'ancienne version pointait les ids 8 et 36, dont la position dans la
  // semaine dépend du jour du seed.
  const marie = sql("SELECT id FROM users WHERE email='marie@demo.club'");
  const cible = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
      AND quota_tag_id IS NOT NULL
      -- Marie doit pouvoir s'y inscrire : la séance cible une de ses catégories actives (§4.5),
      -- sinon le bouton n'apparaît pas du tout et ce n'est plus le quota qu'on teste.
      AND EXISTS (SELECT 1 FROM session_category sc JOIN user_category uc ON uc.category_id=sc.category_id
                  WHERE sc.session_id=sessions.id AND uc.user_id=${marie})
      -- ... et son quota est déjà consommé cette semaine-là sur le même tag par une AUTRE séance.
      -- Le « s2.id <> sessions.id » est essentiel : sans lui, une séance à laquelle Marie participe
      -- déjà se sélectionne elle-même, le scénario supprime son inscription juste après, et le
      -- quota redevient libre — plus de dialog, l'inscription passe directement.
      AND EXISTS (
        SELECT 1 FROM registrations r2 JOIN sessions s2 ON s2.id = r2.session_id
        WHERE r2.user_id = ${marie} AND r2.status = 'participating'
          AND s2.id <> sessions.id
          AND s2.quota_tag_id = sessions.quota_tag_id
          AND YEARWEEK(s2.start_at, 3) = YEARWEEK(sessions.start_at, 3))`);

  const dejaNat = sql(`SELECT COUNT(*) n FROM registrations r JOIN sessions s ON s.id=r.session_id
      WHERE r.user_id=${marie} AND r.status='participating'
        AND s.quota_tag_id=(SELECT quota_tag_id FROM sessions WHERE id=${cible})
        AND YEARWEEK(s.start_at,3)=(SELECT YEARWEEK(start_at,3) FROM sessions WHERE id=${cible})`);
  s.check('prérequis : quota déjà consommé cette semaine-là', Number(dejaNat) >= 1, `n=${dejaNat}`);

  // On retire son éventuelle inscription sur la cible pour tester à froid.
  //
  // On mémorise le statut ET le MOTIF de file. La restauration ne reposait que sur `status` :
  // une inscription « waitlist / quota_exceeded » revenait en « waitlist / NULL », et comme la
  // vérification ne comparait elle aussi que `status`, la perte passait inaperçue. Le jeu de démo
  // ne contient qu'UNE inscription en file quota — celle de Marie, sur cette séance — donc S17,
  // qui la cherche plus bas, ne trouvait plus rien et faisait tomber tout le fichier.
  const avant = sql(`SELECT status FROM registrations WHERE session_id=${cible} AND user_id=${marie}`);
  const avantMotif = sql(`SELECT IFNULL(waitlist_reason,'') FROM registrations WHERE session_id=${cible} AND user_id=${marie}`);
  sql(`DELETE FROM registrations WHERE session_id=${cible} AND user_id=${marie}`);

  await fiche(page, cible);
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
  s.checkJs(page);
  const apresAnnul = sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${cible} AND user_id=${marie}`);
  s.check('annulation : aucune inscription créée', apresAnnul === '0', `n=${apresAnnul}`);

  // Remise en état : statut ET motif de file, sinon le scénario suivant hérite d'un jeu de démo
  // appauvri (cf. le commentaire de la sauvegarde ci-dessus).
  if (avant) {
    const motif = avantMotif ? `'${avantMotif}'` : 'NULL';
    sql(`INSERT INTO registrations (session_id, user_id, status, waitlist_reason, registered_at, created_at, updated_at) VALUES (${cible}, ${marie}, '${avant}', ${motif}, NOW(), NOW(), NOW())`);
  }
  const restaure = sql(`SELECT status FROM registrations WHERE session_id=${cible} AND user_id=${marie}`);
  const restaureMotif = sql(`SELECT IFNULL(waitlist_reason,'') FROM registrations WHERE session_id=${cible} AND user_id=${marie}`);
  s.check('état restauré (statut)', restaure === avant, `${restaure || 'aucun'} (attendu ${avant || 'aucun'})`);
  s.check('état restauré (motif de file)', restaureMotif === avantMotif, `${restaureMotif || 'aucun'} (attendu ${avantMotif || 'aucun'})`);

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
  // Cible dérivée : la séance annulée du jeu de démo n'a pas d'id stable (l'ancienne version
  // pointait la 15, qui n'est plus annulée sur une base fraîche — le scénario passait alors sur un
  // faux positif, « annul » matchant un autre mot de la page).
  const annulee = seance('cancelled_at IS NOT NULL AND start_at > NOW()');
  const s = new Scenario(`S10 · Séance annulée (${annulee}) — bandeau et gel des actions`);
  const { ctx, page } = await session(browser, 'marie@demo.club', MOBILE);
  await fiche(page, annulee);
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
  // Compétition PASSÉE à laquelle Marie a participé (l'onglet Débriefs suppose une compétition).
  const marie11 = Number(sql("SELECT id FROM users WHERE email='marie@demo.club'"));
  const passee = seance(`kind='competition' AND cancelled_at IS NULL AND start_at < NOW()
      AND EXISTS (SELECT 1 FROM registrations r WHERE r.session_id=sessions.id
                  AND r.user_id=${marie11} AND r.status='participating')`, 'start_at DESC');
  const s = new Scenario(`S11 · Séance passée (${passee}) — inscriptions closes, débrief ouvert`);
  const { ctx, page } = await session(browser, 'marie@demo.club', MOBILE);
  await fiche(page, passee);
  const barre = await barreMobile(page);
  s.check('mention « commencée »', /commencée|close/i.test(barre || ''), barre?.slice(0, 60));
  const txt = await page.locator('body').innerText();
  s.check('onglet Débriefs présent (compétition passée)', /débrief/i.test(txt));
  tous.push(s.report());
  await ctx.close();
}

// ── S12 · Parent pur : agit pour l'enfant, pas pour lui (PRD §4.2) ────
{
  const cible12 = seanceFuture(); // séance à venir : le refus doit porter sur le RÔLE, pas sur l'heure
  const s = new Scenario(`S12 · Olivier (parent pur, aucun rôle) — séance ${cible12}`);
  const { ctx, page } = await session(browser, 'olivier@demo.club', MOBILE);

  await page.goto(`${BASE}/enfants`, { waitUntil: 'networkidle' });
  const enfants = await page.locator('body').innerText();
  s.check('accède à « Mes enfants »', /théo|theo/i.test(enfants));

  await fiche(page, cible12);
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
  // Séance future encadrée par Vincent : le bouton « Inscrire un athlète » n'existe que sur une
  // séance non commencée dont il est staff. Dernier id en dur du harnais, il pointait une séance
  // déjà commencée selon l'heure du run.
  const vincent = Number(sql("SELECT id FROM users WHERE email='vincent@demo.club'"));
  const s15 = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
      AND EXISTS (SELECT 1 FROM session_coach sc WHERE sc.session_id=sessions.id AND sc.user_id=${vincent})`);
  const s = new Scenario(`S15 · Kevin (suspendu) absent du sélecteur « Inscrire un athlète » (séance ${s15})`);
  const { ctx, page } = await session(browser, 'vincent@demo.club', DESKTOP);
  await fiche(page, s15);
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
  const inscrits = sql(`SELECT CONCAT(u.first_name,' ',u.last_name) n FROM registrations r JOIN users u ON u.id=r.user_id WHERE r.session_id=${s15} AND r.status='participating'`).split('\n').filter(Boolean);
  s.check('déjà-inscrits exclus du sélecteur',
          inscrits.every(i => !noms.some(n => n.includes(i))), inscrits.join(', '));
  await s.shot(page, 's15-picker');
  tous.push(s.report());
  await ctx.close();
}

// ── S16 · Liste d'attente sur séance pleine (PRD §4.9) ────────────────
{
  // Séance dérivée : future, SATURÉE, ciblant une catégorie de Noah, et où il n'est pas inscrit.
  const noah = Number(sql("SELECT id FROM users WHERE email='noah.faure@demo.club'"));
  const pleine = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW() AND capacity IS NOT NULL
      AND (SELECT COUNT(*) FROM registrations r WHERE r.session_id=sessions.id AND r.status='participating') >= capacity
      AND EXISTS (SELECT 1 FROM session_category k JOIN user_category uc ON uc.category_id=k.category_id
                  WHERE k.session_id=sessions.id AND uc.user_id=${noah})
      AND NOT EXISTS (SELECT 1 FROM registrations r2 WHERE r2.session_id=sessions.id AND r2.user_id=${noah})`);

  const s = new Scenario(`S16 · Séance pleine (${pleine}) — rejoindre puis quitter la file`);
  const journaux = repereJournaux();
  const cap = sql(`SELECT capacity c FROM sessions WHERE id=${pleine}`);
  const pris = sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${pleine} AND status='participating'`);
  s.check('prérequis : séance saturée', Number(pris) >= Number(cap), `${pris}/${cap}`);
  const avant = sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${pleine} AND user_id=${noah}`);
  s.check('prérequis : Noah non inscrit', avant === '0');

  const { ctx, page } = await session(browser, 'noah.faure@demo.club', MOBILE);
  await fiche(page, pleine);
  const txt = await page.locator('body').innerText();
  s.check('séance annoncée complète', /complet/i.test(txt));

  const btn = page.getByRole('button', { name: /liste d'attente/i }).first();
  s.check('bouton « Rejoindre la liste d\'attente » proposé', await btn.isVisible().catch(() => false));
  await s.shot(page, 's16-complet');
  await btn.click();
  await page.waitForTimeout(1500);

  const statut = sql(`SELECT status FROM registrations WHERE session_id=${pleine} AND user_id=${noah}`);
  s.check('inscrit en liste d\'attente (pas participant)', statut === 'waitlist', `statut=${statut || 'aucun'}`);

  // Remise en état.
  sql(`DELETE FROM registrations WHERE session_id=${pleine} AND user_id=${noah}`);
  s.checkJs(page);
  purgeJournaux(journaux);
  s.check('état restauré',
         sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${pleine} AND user_id=${noah}`) === '0');
  s.check('journaux restaurés (audit, activité, envois)',
          sql(`SELECT (SELECT COUNT(*) FROM audit_logs WHERE id>${journaux.audit})
                    + (SELECT COUNT(*) FROM activity_logs WHERE id>${journaux.activite})
                    + (SELECT COUNT(*) FROM notification_outbox WHERE id>${journaux.envois}) n`) === '0');
  tous.push(s.report());
  await ctx.close();
}

// Ouvre un onglet de la fiche en MOBILE (onglets Alpine : x-data="{ tab: 'infos' }" ; sans ça le
// panneau reste en x-show=false et Playwright refuse de cliquer dedans). Le desktop n'a PAS
// d'onglets — tout y est déroulé — donc ce helper ne concerne que .fiche-mobile.
// Le libellé est suivi d'un badge collé (« Waitlist1 ») : on matche le début.
async function ongletMobile(page, nom) {
  const t = page.locator('.fiche-mobile .tabstrip .tab')
    .filter({ hasText: new RegExp('^' + nom, 'i') }).first();
  if (await t.count() === 0) return false;
  await t.click();
  await page.waitForTimeout(400);
  return ((await t.getAttribute('class')) || '').includes('on');
}

// ── S17 · Mécanisme C — déblocage coach de la file quota (PRD §4.10.4) ─
// Le bouton « Remplir avec la file quota » est rendu DEUX fois (mobile l.198, desktop l.352) :
// on vérifie les deux, sinon une dérive de l'un passerait inaperçue. Contrôle négatif apparié
// sur une séance dont la file « séance pleine » non vide doit désactiver le bouton.
{
  const s = new Scenario('S17 · Mécanisme C — remplir avec la file quota');

  // Préconditions de $canFillQuota (session-show.blade.php:54) : file capacity vide + places libres.
  // Cible dérivée : séance future avec file quota NON vide, file capacity VIDE et des places libres
  // — les préconditions exactes de $canFillQuota (session-show.blade.php:54).
  const sq = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
      AND EXISTS (SELECT 1 FROM registrations r WHERE r.session_id=sessions.id
                  AND r.status='waitlist' AND r.waitlist_reason='quota_exceeded')
      AND NOT EXISTS (SELECT 1 FROM registrations r2 WHERE r2.session_id=sessions.id
                      AND r2.status='waitlist' AND r2.waitlist_reason='capacity')
      AND (capacity IS NULL OR capacity > (SELECT COUNT(*) FROM registrations r3
                      WHERE r3.session_id=sessions.id AND r3.status='participating'))`);

  const wq = sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${sq} AND status='waitlist' AND waitlist_reason='quota_exceeded'`);
  const wcap = sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${sq} AND status='waitlist' AND waitlist_reason='capacity'`);
  s.check(`prérequis : file quota non vide (séance ${sq})`, Number(wq) >= 1, `n=${wq}`);
  s.check('prérequis : file « séance pleine » vide', wcap === '0', `n=${wcap}`);

  const promu = sql(`SELECT u.email FROM registrations r JOIN users u ON u.id=r.user_id WHERE r.session_id=${sq} AND r.waitlist_reason='quota_exceeded' ORDER BY r.registered_at LIMIT 1`);
  const prenom = sql(`SELECT u.first_name FROM registrations r JOIN users u ON u.id=r.user_id WHERE r.session_id=${sq} AND r.waitlist_reason='quota_exceeded' ORDER BY r.registered_at LIMIT 1`);
  const coachSq = sql(`SELECT u.email FROM session_coach sc JOIN users u ON u.id=sc.user_id WHERE sc.session_id=${sq} LIMIT 1`) || 'admin@demo.club';

  // — Contrôle négatif : file « séance pleine » NON vide → bouton rendu mais désactivé —
  // Une séance saturée n'a pas forcément de file quota : sans en fabriquer une, le bloc ne serait
  // pas rendu du tout et l'assertion ne prouverait rien. On l'ajoute puis on la retire.
  {
    const bloquee = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
        AND EXISTS (SELECT 1 FROM registrations r WHERE r.session_id=sessions.id
                    AND r.status='waitlist' AND r.waitlist_reason='capacity')`);
    const coachBl = sql(`SELECT u.email FROM session_coach sc JOIN users u ON u.id=sc.user_id WHERE sc.session_id=${bloquee} LIMIT 1`) || 'admin@demo.club';
    const cobaye = sql(`SELECT id FROM users WHERE id NOT IN (SELECT user_id FROM registrations WHERE session_id=${bloquee}) AND JSON_CONTAINS(roles, '"athlete"') LIMIT 1`);
    sql(`INSERT INTO registrations (session_id, user_id, status, waitlist_reason, registered_at, created_at, updated_at) VALUES (${bloquee}, ${cobaye}, 'waitlist', 'quota_exceeded', NOW(), NOW(), NOW())`);

    const { ctx, page } = await session(browser, coachBl, DESKTOP);
    await fiche(page, bloquee);
    const txt = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
    const btn29 = page.locator('.fiche-desktop button[wire\\:click="fillQuota"]').first();
    s.check('contrôle négatif : bouton quota rendu', await btn29.count() > 0);
    const cls = (await btn29.getAttribute('class')) || '';
    s.check('contrôle négatif : bouton DÉSACTIVÉ (file « séance pleine » non vide)', cls.includes('is-disabled'), cls);
    s.check('contrôle négatif : bouton non cliquable (attribut disabled)', await btn29.isDisabled().catch(() => false));
    s.check('contrôle négatif : la condition est expliquée à l\'écran', /séance pleine .{0,10} est vide/i.test(txt), txt.slice(0, 0));
    await s.shot(page, 's17-quota-desactive');
    await ctx.close();

    sql(`DELETE FROM registrations WHERE session_id=${bloquee} AND user_id=${cobaye}`);
    s.check('contrôle négatif : état restauré',
            sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${bloquee} AND user_id=${cobaye}`) === '0');
  }

  // Instantané AVANT l'action, pour une remise en état complète.
  //
  // `fillQuota` promeut TOUTE la file quota d'un coup (§4.10.4), pas seulement le premier : la
  // restauration ne portait que sur `promu` et laissait les autres en `participating`. Mesuré sur
  // une base fraîchement seedée : la file contenait marie ET laura, laura était restaurée, marie
  // restait promue — le jeu de démo perdait une entrée de file quota à CHAQUE run, définitivement,
  // et le run suivant partait donc d'un jeu appauvri.
  const fileAvant = sql(`SELECT IFNULL(GROUP_CONCAT(user_id ORDER BY user_id), '') v FROM registrations
      WHERE session_id=${sq} AND status='waitlist' AND waitlist_reason='quota_exceeded'`) || '0';

  // Repère dans la file d'envoi : la promotion notifie les promus. Sans repère, on ne saurait pas
  // distinguer les lignes créées par CE run de celles que le jeu de démo contient déjà — et on ne
  // peut pas purger toute la table sans détruire une partie du jeu.
  const journaux = repereJournaux();

  // — Cas positif, DESKTOP : le bouton est actif et la promotion s'exécute —
  const { ctx, page } = await session(browser, coachSq, DESKTOP);
  await fiche(page, sq);

  const bodyAvant = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  s.check('bloc « Quota dépassé » affiché', /quota dépassé/i.test(bodyAvant));
  s.check('l\'athlète en attente y est nommé', bodyAvant.includes(prenom), prenom);

  const btn = page.locator('.fiche-desktop button[wire\\:click="fillQuota"]').first();
  s.check('bouton « Remplir avec la file quota » présent (desktop)', await btn.count() > 0);
  const clsBtn = (await btn.getAttribute('class')) || '';
  s.check('bouton actif (préconditions réunies)', !clsBtn.includes('is-disabled'), clsBtn);
  await s.shot(page, 's17-quota-avant');

  // Timeout court + échec explicite : si le bouton est désactivé à tort, on veut un ❌ lisible
  // dans le rapport, pas un crash Playwright de 30 s qui interrompt toute la suite.
  const clique = await btn.click({ timeout: 5000 }).then(() => true).catch(() => false);
  s.check('le bouton est réellement cliquable', clique);
  await page.waitForTimeout(1800);

  // Effet en base : promotion tracée (promoted_by = le coach qui a cliqué).
  const apres = sql(`SELECT status FROM registrations WHERE session_id=${sq} AND user_id=(SELECT id FROM users WHERE email='${promu}')`);
  s.check('athlète promu en participating', apres === 'participating', `statut=${apres}`);
  const by = sql(`SELECT promoted_by FROM registrations WHERE session_id=${sq} AND user_id=(SELECT id FROM users WHERE email='${promu}')`);
  const karine = sql(`SELECT id FROM users WHERE email='${coachSq}'`);
  s.check('promotion attribuée au coach (promoted_by)', by === karine, `${by} (attendu ${karine})`);
  const audit = sql(`SELECT COUNT(*) n FROM audit_logs WHERE action='promote_quota_exceeded' AND session_id=${sq}`);
  s.check('AuditLog promote_quota_exceeded émis', Number(audit) >= 1, `n=${audit}`);

  // Effet à l'écran : le flash annonce la promotion, et l'athlète a changé de bloc.
  const bodyApres = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  s.check('flash de confirmation affiché', /promu/i.test(bodyApres), bodyApres.slice(0, 120));
  // La file quota étant vidée, le bloc « Quota dépassé » et son bouton disparaissent.
  s.check('le bloc « Quota dépassé » a disparu', !/quota dépassé/i.test(bodyApres));
  s.check('le bouton de déblocage a disparu',
          await page.locator('.fiche-desktop button[wire\\:click="fillQuota"]').count() === 0);
  // Contrôle positif apparié : la page n'est pas simplement vide, l'athlète est bien listé ailleurs.
  s.check('l\'athlète promu apparaît chez les inscrits', bodyApres.includes(prenom), prenom);
  await s.shot(page, 's17-quota-apres');
  await ctx.close();

  // — Remise en état, puis vérification du rendu MOBILE sur l'état restauré —
  // TOUTE la file, et pas le seul `promu` : cf. l'instantané plus haut.
  sql(`UPDATE registrations SET status='waitlist', waitlist_reason='quota_exceeded', promoted_at=NULL, promoted_by=NULL
       WHERE session_id=${sq} AND user_id IN (${fileAvant})`);
  purgeJournaux(journaux);

  const restaure = sql(`SELECT CONCAT(status,'/',IFNULL(waitlist_reason,'-'),'/',IFNULL(promoted_by,'-')) v FROM registrations WHERE session_id=${sq} AND user_id=(SELECT id FROM users WHERE email='${promu}')`);
  s.check('état restauré', restaure === 'waitlist/quota_exceeded/-', restaure);
  const fileApres = sql(`SELECT IFNULL(GROUP_CONCAT(user_id ORDER BY user_id), '') v FROM registrations
      WHERE session_id=${sq} AND status='waitlist' AND waitlist_reason='quota_exceeded'`);
  s.check('état restauré : TOUTE la file quota, pas seulement le promu',
          fileApres === fileAvant, `${fileApres || 'vide'} (attendu ${fileAvant})`);
  s.check('état restauré : aucune trace de promotion sur les inscriptions de la file',
          sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${sq} AND user_id IN (${fileAvant}) AND promoted_at IS NOT NULL`) === '0');
  s.check('journaux restaurés (audit, activité, envois)',
          sql(`SELECT (SELECT COUNT(*) FROM audit_logs WHERE id>${journaux.audit})
                    + (SELECT COUNT(*) FROM activity_logs WHERE id>${journaux.activite})
                    + (SELECT COUNT(*) FROM notification_outbox WHERE id>${journaux.envois}) n`) === '0');

  {
    const { ctx, page } = await session(browser, coachSq, MOBILE);
    await fiche(page, sq);
    s.check('onglet Waitlist ouvert (mobile)', await ongletMobile(page, 'waitlist'));
    const btnM = page.locator('.fiche-mobile button[wire\\:click="fillQuota"]').first();
    s.check('bouton présent aussi en mobile', await btnM.count() > 0);
    const clsM = (await btnM.getAttribute('class')) || '';
    s.check('bouton actif en mobile', !clsM.includes('is-disabled'), clsM);
    // Pas de débordement horizontal sur la barre d'action.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    s.check('pas de débordement horizontal (mobile)', !overflow);
    await s.shot(page, 's17-quota-mobile');
    await ctx.close();
  }

  // — Refus : un athlète simple ne peut pas déclencher le mécanisme C —
  {
    const { ctx, page } = await session(browser, 'marie@demo.club', DESKTOP);
    await fiche(page, sq);
    const n = await page.locator('button[wire\\:click="fillQuota"]').count();
    s.check('athlète simple : aucun bouton de déblocage', n === 0, `n=${n}`);
    await ctx.close();
  }

  tous.push(s.report());
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES PARCOURS PASSENT' : '❌ AU MOINS UN PARCOURS ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
