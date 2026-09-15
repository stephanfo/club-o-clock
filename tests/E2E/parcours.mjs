// Scénarios complémentaires — parcours critiques et cas limites (PLAN_TESTS.md §1 à §8).
// NON destructifs : chaque scénario restaure ce qu'il modifie. Voir destructif.mjs pour le reste.
import { launch, session, fiche, sql, seance, seanceFuture, ligne, barreMobile, Scenario, MOBILE, DESKTOP, BASE, repereJournaux, purgeJournaux } from './lib.mjs';
import { writeFileSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

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

  // Le scénario CONSTRUIT sa collision de quota au lieu de l'espérer dans le jeu de démo (#46).
  //
  // L'ancienne version cherchait une séance future dont le quota était DÉJÀ consommé par une
  // inscription du seed. Or le seed place l'inscription de Marie en début de semaine : la collision
  // n'existe que tant qu'il reste, la même semaine, une séance future au même tag — soit du lundi au
  // mercredi. Le scénario était vert en début de semaine et rouge ensuite, sans qu'une ligne change.
  //
  // On prend donc une PAIRE de séances futures (même tag, même semaine ISO, toutes deux ouvertes à
  // Marie et non pleines) : A sert à consommer le quota, B est la cible. Les deux sont restaurées en
  // fin de scénario. Paire et non deux `seance()` séparés : leur cohérence mutuelle est justement
  // ce qui déclenche le dialog.
  const marie = sql("SELECT id FROM users WHERE email='marie@demo.club'");
  const place = (t) => `(${t}.capacity IS NULL OR (SELECT COUNT(*) FROM registrations r
      WHERE r.session_id=${t}.id AND r.status='participating') < ${t}.capacity)`;
  const ouverte = (t) => `EXISTS (SELECT 1 FROM session_category c JOIN user_category u
      ON u.category_id=c.category_id WHERE c.session_id=${t}.id AND u.user_id=${marie})`;

  const [consomme, cible] = ligne(`
    SELECT a.id aid, b.id bid
    FROM sessions a
    JOIN sessions b ON b.quota_tag_id = a.quota_tag_id
                   AND YEARWEEK(b.start_at, 3) = YEARWEEK(a.start_at, 3)
                   AND b.id <> a.id
    WHERE a.kind='training' AND a.cancelled_at IS NULL AND a.start_at > NOW() AND a.quota_tag_id IS NOT NULL
      AND b.kind='training' AND b.cancelled_at IS NULL AND b.start_at > NOW()
      AND ${ouverte('a')} AND ${ouverte('b')} AND ${place('a')} AND ${place('b')}
    ORDER BY a.start_at, b.start_at LIMIT 1`,
    'une paire de séances en collision de quota').map(Number);

  // État de Marie sur A, pour le rendre tel quel ensuite. '' = elle n'y était pas inscrite.
  const etatA = sql(`SELECT status, IFNULL(waitlist_reason,'') m FROM registrations
      WHERE session_id=${consomme} AND user_id=${marie}`);
  const [statutA, motifA] = etatA ? etatA.split(' | ') : ['', ''];

  // On consomme le quota sur A — c'est ce que le scénario suppose, et il l'établit lui-même.
  if (!etatA) {
    sql(`INSERT INTO registrations (session_id, user_id, status, registered_at, created_at, updated_at)
         VALUES (${consomme}, ${marie}, 'participating', NOW(), NOW(), NOW())`);
  } else if (statutA !== 'participating') {
    sql(`UPDATE registrations SET status='participating', waitlist_reason=NULL
         WHERE session_id=${consomme} AND user_id=${marie}`);
  }

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

  // ... et la séance A, dont le scénario s'est servi pour consommer le quota.
  if (!etatA) {
    sql(`DELETE FROM registrations WHERE session_id=${consomme} AND user_id=${marie}`);
  } else {
    sql(`UPDATE registrations SET status='${statutA}', waitlist_reason=${motifA ? `'${motifA}'` : 'NULL'}
         WHERE session_id=${consomme} AND user_id=${marie}`);
  }
  const restaureA = sql(`SELECT status, IFNULL(waitlist_reason,'') m FROM registrations
      WHERE session_id=${consomme} AND user_id=${marie}`);
  s.check('état restauré (séance ayant consommé le quota)', restaureA === etatA,
          `${restaureA || 'aucune'} (attendu ${etatA || 'aucune'})`);

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
  // Le scénario ANNULE lui-même sa séance au lieu d'en chercher une annulée (#35, #46).
  //
  // Le jeu de démo n'annule qu'une séance, et `start_at > NOW()` la disqualifie dès qu'elle est
  // passée — le scénario levait alors, comme S8 et S16 avant lui. Contrôler l'état apporte en prime
  // ce qui manquait : l'assertion « pas d'action d'inscription » ne vaut rien sans la preuve qu'une
  // action était proposée AVANT l'annulation (convention du harnais : toute assertion négative
  // s'apparie à un contrôle positif). Sur une séance annulée trouvée telle quelle, cette preuve
  // était hors d'atteinte.
  const marie10 = Number(sql("SELECT id FROM users WHERE email='marie@demo.club'"));
  const annulee = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
      AND (capacity IS NULL OR (SELECT COUNT(*) FROM registrations r
           WHERE r.session_id=sessions.id AND r.status='participating') < capacity)
      AND EXISTS (SELECT 1 FROM session_category c JOIN user_category u ON u.category_id=c.category_id
                  WHERE c.session_id=sessions.id AND u.user_id=${marie10})
      AND NOT EXISTS (SELECT 1 FROM registrations r2 WHERE r2.session_id=sessions.id AND r2.user_id=${marie10})`);
  const s = new Scenario(`S10 · Séance annulée (${annulee}) — bandeau et gel des actions`);
  const { ctx, page } = await session(browser, 'marie@demo.club', MOBILE);

  // Contrôle positif, AVANT l'annulation : la séance propose bien une inscription.
  await fiche(page, annulee);
  const barreAvant = await barreMobile(page);
  s.check('contrôle positif : inscription proposée avant annulation',
          /s'inscrire|liste d'attente/i.test(barreAvant || ''), barreAvant?.slice(0, 60));

  const admin = Number(sql("SELECT id FROM users WHERE email='admin@demo.club'"));
  sql(`UPDATE sessions SET cancelled_at=NOW(), cancelled_by=${admin} WHERE id=${annulee}`);

  await fiche(page, annulee);
  const txt = (await page.locator('body').innerText()).toLowerCase();
  s.check('bandeau d\'annulation présent', /annul/i.test(txt));
  const barre = await barreMobile(page);
  s.check('pas d\'action d\'inscription', !/s'inscrire|se désinscrire/i.test(barre || ''), barre?.slice(0, 60));
  await s.shot(page, 's10-annulee');

  sql(`UPDATE sessions SET cancelled_at=NULL, cancelled_by=NULL WHERE id=${annulee}`);
  s.check('état restauré (séance à nouveau active)',
          sql(`SELECT COUNT(*) n FROM sessions WHERE id=${annulee} AND cancelled_at IS NULL`) === '1');
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
  // Contrôle positif apparié à « Kevin absent » : sans lui, l'assertion négative passerait aussi
  // sur un sélecteur vide ou cassé. Les candidats se DÉRIVENT de la base — nommer quelqu'un en dur
  // (« Camille ») rendait le contrôle dépendant du tirage d'inscriptions du seeder, qui varie avec
  // l'heure du re-seed : le jour où Camille se trouvait déjà inscrite à cette séance, donc exclue
  // du sélecteur à bon droit, le contrôle échouait sans que rien ne soit cassé.
  const eligibles = sql(`SELECT CONCAT(u.first_name,' ',u.last_name) n FROM users u
      WHERE u.is_active=1 AND u.anonymized_at IS NULL AND u.athlete_access_suspended=0
        AND JSON_CONTAINS(u.roles, '"athlete"')
        AND EXISTS (SELECT 1 FROM user_category uc JOIN session_category sc ON sc.category_id=uc.category_id
                    WHERE uc.user_id=u.id AND sc.session_id=${s15})
        AND NOT EXISTS (SELECT 1 FROM registrations r WHERE r.session_id=${s15} AND r.user_id=u.id
                        AND r.status IN ('participating','waitlist'))
        AND NOT EXISTS (SELECT 1 FROM session_coach sc2 WHERE sc2.session_id=${s15} AND sc2.user_id=u.id)
      ORDER BY u.id LIMIT 5`).split('\n').filter(Boolean);
  const propose = eligibles.find(e => noms.some(n => n.includes(e)));
  s.check('contrôle positif : un athlète éligible est proposé', propose !== undefined,
          propose ?? `aucun des ${eligibles.length} éligibles en base`);
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
  // Le scénario SATURE lui-même sa séance au lieu d'en chercher une déjà pleine (#35).
  //
  // Le jeu de démo ne sature qu'une séance — « Natation samedi matin — jeunes » — et `seance()`
  // exige `start_at > NOW()` : dès ce samedi passé, plus rien ne satisfaisait le prédicat et le
  // scénario levait, emportant la fin du fichier. On prend donc une séance future à capacité
  // ouverte à Noah, et on la remplit : capacité abaissée au nombre de participants, complété d'un
  // inscrit si elle était vide (une capacité à 0 saturerait aussi, mais testerait un cas dégénéré
  // que l'application ne produit jamais). Tout est restauré en fin de scénario.
  const noah = Number(sql("SELECT id FROM users WHERE email='noah.faure@demo.club'"));
  const pleine = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW() AND capacity IS NOT NULL
      AND EXISTS (SELECT 1 FROM session_category k JOIN user_category uc ON uc.category_id=k.category_id
                  WHERE k.session_id=sessions.id AND uc.user_id=${noah})
      AND NOT EXISTS (SELECT 1 FROM registrations r2 WHERE r2.session_id=sessions.id AND r2.user_id=${noah})`);

  const s = new Scenario(`S16 · Séance pleine (${pleine}) — rejoindre puis quitter la file`);
  const journaux = repereJournaux();
  const capOrigine = sql(`SELECT capacity c FROM sessions WHERE id=${pleine}`);

  // Le bouche-trou est un membre RÉELLEMENT éligible à la séance : saturer avec n'importe qui
  // laisserait un état que l'application ne peut pas produire, et fausserait les écrans.
  let bouchon = null;
  if (sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${pleine} AND status='participating'`) === '0') {
    bouchon = ligne(`SELECT u.id uid FROM users u
        JOIN user_category uc ON uc.user_id = u.id
        JOIN session_category k ON k.category_id = uc.category_id AND k.session_id = ${pleine}
        WHERE u.id <> ${noah} AND u.is_active = 1 AND u.athlete_access_suspended = 0
          AND NOT EXISTS (SELECT 1 FROM registrations r WHERE r.session_id = ${pleine} AND r.user_id = u.id)
        LIMIT 1`, `un membre éligible à la séance ${pleine}`)[0];
    sql(`INSERT INTO registrations (session_id, user_id, status, registered_at, created_at, updated_at)
         VALUES (${pleine}, ${bouchon}, 'participating', NOW(), NOW(), NOW())`);
  }
  sql(`UPDATE sessions SET capacity = (SELECT COUNT(*) FROM registrations r
       WHERE r.session_id = ${pleine} AND r.status = 'participating') WHERE id = ${pleine}`);

  const cap = sql(`SELECT capacity c FROM sessions WHERE id=${pleine}`);
  const pris = sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${pleine} AND status='participating'`);
  s.check('prérequis : séance saturée', Number(pris) >= Number(cap) && Number(cap) > 0, `${pris}/${cap}`);
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

  // Remise en état : l'inscription de Noah, le bouche-trou, puis la capacité d'origine.
  sql(`DELETE FROM registrations WHERE session_id=${pleine} AND user_id=${noah}`);
  if (bouchon) sql(`DELETE FROM registrations WHERE session_id=${pleine} AND user_id=${bouchon}`);
  sql(`UPDATE sessions SET capacity=${capOrigine} WHERE id=${pleine}`);
  s.checkJs(page);
  purgeJournaux(journaux);
  s.check('état restauré (inscription de Noah)',
         sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${pleine} AND user_id=${noah}`) === '0');
  s.check('état restauré (capacité de la séance)',
         sql(`SELECT capacity c FROM sessions WHERE id=${pleine}`) === capOrigine, `capacité=${capOrigine}`);
  s.check('état restauré (aucun inscrit ajouté)',
         sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${pleine} AND status='participating'`)
           === (bouchon ? '0' : pris), bouchon ? 'bouche-trou retiré' : 'aucun ajout');
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

// ── S17 · Mécanisme C — déblocage du quota jusqu'à la séance (PRD §4.10.4, #66) ─
// Le déblocage est un ÉTAT de la séance : le coach l'ouvre par un dialog (liste des promu·e·s,
// accusé de réception), la chip « Quota débloqué » le montre à tous, et « Refermer le quota » le
// retire. Le geste est rendu dans les deux coquilles : desktop (colonne Gestion) et mobile (bloc
// Gestion de l'onglet Infos) — on vérifie les deux, et le refus côté athlète.
{
  const s = new Scenario('S17 · Mécanisme C — débloquer puis refermer le quota');

  // La file quota est POSÉE par le scénario, pas cherchée dans le jeu de démo (#35, #46). On ne
  // garde du seed que les préconditions structurelles : séance taguée, future, non débloquée,
  // file capacity vide et places libres (sinon le déblocage ne promeut personne).
  const sq = seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW()
      AND quota_tag_id IS NOT NULL AND quota_released_at IS NULL
      AND NOT EXISTS (SELECT 1 FROM registrations r2 WHERE r2.session_id=sessions.id
                      AND r2.status='waitlist' AND r2.waitlist_reason='capacity')
      AND (capacity IS NULL OR capacity > (SELECT COUNT(*) FROM registrations r3
                      WHERE r3.session_id=sessions.id AND r3.status='participating'))`);

  // L'athlète mis en file est réellement éligible à la séance : le dialog le nomme, et un inscrit
  // hors catégorie serait un état que l'application ne produit jamais. Marie est écartée : c'est
  // elle qui joue l'athlète simple du contrôle de refus.
  const [enFile, prenom] = ligne(`SELECT u.id uid, u.first_name prenom FROM users u
      JOIN user_category uc ON uc.user_id = u.id
      JOIN session_category k ON k.category_id = uc.category_id AND k.session_id = ${sq}
      WHERE u.is_active = 1 AND u.athlete_access_suspended = 0 AND JSON_CONTAINS(u.roles, '"athlete"')
        AND u.email <> 'marie@demo.club'
        AND NOT EXISTS (SELECT 1 FROM registrations r WHERE r.session_id = ${sq} AND r.user_id = u.id)
      LIMIT 1`, `un athlète éligible à la séance ${sq}`);

  // Instantané de la file AVANT l'action : le déblocage promeut TOUTE la file d'un coup, et la
  // remise en état doit la reposer entière (S17 a déjà appauvri le jeu de démo à chaque run).
  const fileAvant = sql(`SELECT IFNULL(GROUP_CONCAT(user_id ORDER BY user_id), '') v FROM registrations
      WHERE session_id=${sq} AND status='waitlist' AND waitlist_reason='quota_exceeded'`);
  const journaux = repereJournaux();
  sql(`INSERT INTO registrations (session_id, user_id, status, waitlist_reason, registered_at, created_at, updated_at)
       VALUES (${sq}, ${enFile}, 'waitlist', 'quota_exceeded', NOW(), NOW(), NOW())`);
  const file = [fileAvant, enFile].filter(Boolean).join(',');

  const coachSq = sql(`SELECT u.email FROM session_coach sc JOIN users u ON u.id=sc.user_id WHERE sc.session_id=${sq} LIMIT 1`) || 'admin@demo.club';
  const coachId = sql(`SELECT id FROM users WHERE email='${coachSq}'`);

  // — Refus : un athlète simple ne voit ni le geste ni la chip (quota encore fermé) —
  {
    const { ctx, page } = await session(browser, 'marie@demo.club', DESKTOP);
    await fiche(page, sq);
    const txt = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
    s.check('athlète : fiche rendue (contrôle positif)', /inscrit/i.test(txt));
    s.check('athlète : aucun bouton de déblocage',
            await page.locator('button[wire\\:click="openReleaseConfirm"]').count() === 0);
    s.check('athlète : pas de chip tant que le quota est fermé', !/quota débloqué/i.test(txt));
    await ctx.close();
  }

  // — DESKTOP coach : dialog, accusé de réception, déblocage —
  {
    const { ctx, page } = await session(browser, coachSq, DESKTOP);
    await fiche(page, sq);

    const btn = page.locator('.fiche-desktop button[wire\\:click="openReleaseConfirm"]');
    s.check('bouton « Débloquer le quota » présent (desktop)', await btn.count() > 0);
    await btn.first().click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(900);

    const dlg = page.locator('.dialog:visible');
    const txtDlg = ((await dlg.innerText().catch(() => '')) || '').replace(/\s+/g, ' ');
    s.check('dialog ouvert', txtDlg.length > 0);
    s.check('le dialog nomme l\'athlète promu', txtDlg.includes(prenom), prenom);
    s.check('accusé de réception chiffré présent', /je comprends que \d+ athlète/i.test(txtDlg), txtDlg.slice(0, 160));
    const armeAvant = await dlg.locator('.dialog-foot button[wire\\:click="releaseQuota"]').count();
    s.check('bouton non armé tant que la case n\'est pas cochée', armeAvant === 0, `n=${armeAvant}`);
    await s.shot(page, 's17-quota-dialog');

    await dlg.locator('#txt-debloquer-quota').click();
    await page.waitForTimeout(700);
    const valider = dlg.locator('.dialog-foot button[wire\\:click="releaseQuota"]');
    s.check('case cochée : bouton armé', await valider.count() === 1);
    await valider.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1800);

    s.check('séance débloquée en base (quota_released_by = le coach)',
            sql(`SELECT IFNULL(quota_released_by, '-') v FROM sessions WHERE id=${sq}`) === coachId);
    s.check('athlète promu en participating, attribué au coach',
            sql(`SELECT CONCAT(status,'/',IFNULL(promoted_by,'-')) v FROM registrations WHERE session_id=${sq} AND user_id=${enFile}`) === `participating/${coachId}`);
    s.check('AuditLog quota_release puis promote_quota_exceeded',
            sql(`SELECT COUNT(DISTINCT action) n FROM audit_logs WHERE id>${journaux.audit} AND session_id=${sq}
                 AND action IN ('quota_release','promote_quota_exceeded')`) === '2');

    const body = (await page.locator('.fiche-desktop').innerText()).replace(/\s+/g, ' ');
    s.check('chip « Quota débloqué » affichée', /quota débloqué/i.test(body));
    s.check('le bouton devient « Refermer le quota »',
            await page.locator('.fiche-desktop button[wire\\:click="closeQuota"]').count() === 1);
    s.check('l\'athlète promu apparaît chez les inscrits', body.includes(prenom), prenom);
    await s.shot(page, 's17-quota-debloque');
    await ctx.close();
  }

  // — Athlète sur la séance débloquée : la chip est là, pas le geste —
  {
    const { ctx, page } = await session(browser, 'marie@demo.club', MOBILE);
    await fiche(page, sq);
    const txt = (await page.locator('.fiche-mobile').innerText()).replace(/\s+/g, ' ');
    s.check('athlète : chip « Quota débloqué » visible (mobile)', /quota débloqué/i.test(txt));
    s.check('athlète : pas de « Refermer le quota »',
            await page.locator('button[wire\\:click="closeQuota"]').count() === 0);
    await s.shot(page, 's17-quota-athlete-mobile');
    await ctx.close();
  }

  // — MOBILE coach : refermer depuis le bloc Gestion de l'onglet Infos —
  {
    const { ctx, page } = await session(browser, coachSq, MOBILE);
    await fiche(page, sq);
    const fermer = page.locator('.fiche-mobile button[wire\\:click="closeQuota"]:visible').first();
    s.check('« Refermer le quota » présent en mobile (onglet Infos)', await fermer.count() === 1);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    s.check('pas de débordement horizontal (mobile)', !overflow);
    await fermer.scrollIntoViewIfNeeded().catch(() => {});
    await s.shot(page, 's17-quota-mobile');
    page.once('dialog', (d) => d.accept());   // wire:confirm
    await fermer.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1500);
    s.check('quota refermé en base',
            sql(`SELECT IFNULL(quota_released_at, '-') v FROM sessions WHERE id=${sq}`) === '-');
    s.check('le promu reste inscrit après fermeture',
            sql(`SELECT status FROM registrations WHERE session_id=${sq} AND user_id=${enFile}`) === 'participating');
    s.check('AuditLog quota_close émis',
            sql(`SELECT COUNT(*) n FROM audit_logs WHERE id>${journaux.audit} AND session_id=${sq} AND action='quota_close'`) === '1');
    s.check('le bouton redevient « Débloquer le quota »',
            await page.locator('.fiche-mobile button[wire\\:click="openReleaseConfirm"]:visible').count() > 0);

    // Rouvrir juste après, SANS recharger : le morphing réutilisait le <button> de « Refermer », et
    // le gestionnaire wire:confirm posé à son initialisation survivait au retrait de l'attribut —
    // la confirmation « Refermer le quota ? » s'affichait avant le dialog de déblocage.
    let confirmNatif = null;
    page.once('dialog', (d) => { confirmNatif = d.message(); d.dismiss(); });
    await page.locator('.fiche-mobile button[wire\\:click="openReleaseConfirm"]:visible').first().click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1200);
    page.removeAllListeners('dialog');
    s.check('rouvrir : aucune confirmation native résiduelle', confirmNatif === null, confirmNatif || '');
    s.check('rouvrir : le dialog de déblocage s\'ouvre', await page.locator('.dialog:visible').count() === 1);
    await ctx.close();
  }

  // — Remise en état : file entière, état de séance, journaux, entrée posée par le scénario —
  sql(`UPDATE registrations SET status='waitlist', waitlist_reason='quota_exceeded', promoted_at=NULL, promoted_by=NULL
       WHERE session_id=${sq} AND user_id IN (${file})`);
  sql(`DELETE FROM registrations WHERE session_id=${sq} AND user_id=${enFile}`);
  sql(`UPDATE sessions SET quota_released_at=NULL, quota_released_by=NULL WHERE id=${sq}`);
  purgeJournaux(journaux);

  s.check('état restauré : TOUTE la file quota d\'avant',
          sql(`SELECT IFNULL(GROUP_CONCAT(user_id ORDER BY user_id), '') v FROM registrations
               WHERE session_id=${sq} AND status='waitlist' AND waitlist_reason='quota_exceeded'`) === fileAvant,
          `attendu « ${fileAvant} »`);
  s.check('état restauré : entrée posée par le scénario retirée',
          sql(`SELECT COUNT(*) n FROM registrations WHERE session_id=${sq} AND user_id=${enFile}`) === '0');
  s.check('journaux restaurés (audit, activité, envois)',
          sql(`SELECT (SELECT COUNT(*) FROM audit_logs WHERE id>${journaux.audit})
                    + (SELECT COUNT(*) FROM activity_logs WHERE id>${journaux.activite})
                    + (SELECT COUNT(*) FROM notification_outbox WHERE id>${journaux.envois}) n`) === '0');

  tous.push(s.report());
}

// ── S21 · Alertes d'un garant lui-même athlète (PRD §4.15.5) ──────────
// Sandrine est le cas exact que le rendu générique rendait illisible : garante de deux enfants ET
// athlète. Ses notifications et celles de ses enfants arrivent sur le même compte — rien ne les
// distinguait. On pose deux alertes déterministes plutôt que d'espérer les bonnes dans le jeu de
// démo, et on les retire ensuite (règle « restaurer l'état »).
{
  const s = new Scenario('S21 · Alertes — un garant distingue les siennes de celles de ses enfants');
  const repere = repereJournaux();

  const sandrine = Number(sql("SELECT id FROM users WHERE email='sandrine@demo.club'"));
  const jade = Number(sql("SELECT id FROM users WHERE first_name='Jade' AND guardian_id=" + sandrine));
  const cible = seanceFuture();
  const titre = sql(`SELECT title FROM sessions WHERE id=${cible}`);

  // Ouvrir la page marque TOUT lu : sans ce relevé, le run laisserait le jeu de démo sans badge
  // d'alertes non lues — une trace invisible, et le premier écran du pitch appauvri.
  const nonLues = sql(`SELECT IFNULL(GROUP_CONCAT(id),'') v FROM notification_outbox WHERE user_id=${sandrine} AND read_at IS NULL`);

  const alerte = (payload) => sql(
    "INSERT INTO notification_outbox (type, channel, payload, user_id, status, attempts, sent_at, created_at, updated_at) "
    + `VALUES ('session_cancelled', 'push', '${payload}', ${sandrine}, 'sent', 1, NOW(), NOW(), NOW())`
  );

  alerte(`{"session_id":${cible},"subject_id":${jade},"subject_first_name":"Jade"}`); // pour son enfant
  alerte(`{"session_id":${cible}}`);                                                  // pour elle-même

  const { ctx, page } = await session(browser, 'sandrine@demo.club', MOBILE);
  await page.goto(`${BASE}/alertes`, { waitUntil: 'networkidle' });
  const txt = await page.locator('body').innerText();

  s.check('l\'alerte de l\'enfant est nommée', /Jade · Annulation de séance/.test(txt));
  s.check('la séance concernée est nommée', txt.includes(titre), titre);
  // Assertion négative appariée au contrôle positif ci-dessus : la liste n'est pas vide, et sa
  // propre alerte y figure bien — sans être attribuée à quelqu'un d'autre.
  s.check('sa propre alerte reste sans prénom', !/Sandrine · Annulation/.test(txt)
    && (txt.match(/Annulation de séance/g) ?? []).length >= 2);
  s.checkJs(page);
  await s.shot(page, 's21-alertes-garant-mobile');

  await page.setViewportSize(DESKTOP);
  await page.reload({ waitUntil: 'networkidle' });
  await s.shot(page, 's21-alertes-garant-desktop');
  await ctx.close();

  purgeJournaux(repere);
  if (nonLues) sql(`UPDATE notification_outbox SET read_at=NULL WHERE id IN (${nonLues})`);
  tous.push(s.report());
}

// ── S22 · Retirer un GPX déposé (issue #43) ───────────────────────────
//
// Le composant Alpine `gpxField` est partagé entre le formulaire de séance et celui de la
// bibliothèque : son bouton « retirer » appelle `$wire.removeGpx()` sur les deux hôtes. Tant que la
// méthode n'existait que côté séance, le clic levait `MethodNotFoundException` — une modale
// « erreur 500 » que PHPUnit ne peut pas voir, puisque le chemin part d'un clic Alpine.
//
// NON destructif : on dépose un fichier et on le retire, sans jamais enregistrer. Rien n'atteint la
// base ni le disque (le temporaire Livewire est balayé par sa propre purge).
{
  const s = new Scenario('S22 · Parcours — retirer le GPX déposé');

  const gpx = join(tmpdir(), `coc-e2e-${process.pid}.gpx`);
  writeFileSync(gpx, `<?xml version="1.0"?><gpx version="1.1" creator="e2e"><trk><name>E2E</name><trkseg>
<trkpt lat="47.5500" lon="1.3000"><ele>62</ele></trkpt>
<trkpt lat="47.5600" lon="1.3200"><ele>110</ele></trkpt>
<trkpt lat="47.5700" lon="1.3400"><ele>95</ele></trkpt>
</trkseg></trk></gpx>`);

  const { ctx, page } = await session(browser, 'vincent@demo.club', DESKTOP);

  const erreurs5xx = [];
  page.on('response', r => { if (r.status() >= 500) erreurs5xx.push(`${r.status()} ${r.url()}`); });

  const retirer = page.locator('button[aria-label="Retirer le GPX"]');

  // ── Création : le retrait ramène le champ à vide ──
  await page.goto(`${BASE}/parcours/creer`, { waitUntil: 'networkidle' });
  await page.setInputFiles('input[type=file][accept=".gpx"]', gpx);
  await page.waitForTimeout(2500);

  // Contrôle positif : sans dépôt effectif, l'assertion de retrait ne vaudrait rien.
  const depose = s.check('création — le GPX est déposé (bouton « retirer » présent)',
    await retirer.isVisible().catch(() => false));

  if (depose) {
    await retirer.click();
    await page.waitForTimeout(1500);
    s.check('création — aucune réponse HTTP 5xx au retrait', erreurs5xx.length === 0, erreurs5xx.join(' | '));
    s.check('création — le champ est revenu à l\'état « aucun fichier »',
      !(await retirer.isVisible().catch(() => false)));
    await s.shot(page, 's22-retrait-creation');
  }

  // ── Édition : le retrait d'un remplaçant ne vide pas la fiche ──
  // Le parcours enregistré garde sa trace ; seul le fichier en attente est oublié.
  const [routeId, routeNom] = ligne('SELECT id, name FROM gpx_routes WHERE archived_at IS NULL ORDER BY id LIMIT 1',
    'un parcours de la bibliothèque');
  erreurs5xx.length = 0;

  await page.goto(`${BASE}/parcours/${routeId}/modifier`, { waitUntil: 'networkidle' });
  await page.setInputFiles('input[type=file][accept=".gpx"]', gpx);
  await page.waitForTimeout(2500);

  if (s.check('édition — le remplaçant est déposé', await retirer.isVisible().catch(() => false))) {
    await retirer.click();
    await page.waitForTimeout(1500);
    s.check('édition — aucune réponse HTTP 5xx au retrait', erreurs5xx.length === 0, erreurs5xx.join(' | '));

    const txt = await page.locator('body').innerText();
    s.check('édition — la fiche garde le parcours enregistré', txt.includes(routeNom), routeNom);
    await s.shot(page, 's22-retrait-edition');
  }

  s.checkJs(page);
  await ctx.close();

  // Le formulaire n'a jamais été enregistré : rien à restaurer en base, seul le fichier local part.
  unlinkSync(gpx);
  tous.push(s.report());
}

// ── S23 · Admin modèles : l'écran ne promet plus de génération qui n'a pas lieu (#40) ─
{
  // Non destructif : on ouvre la modale de relance et on la referme sans jamais générer.
  const [tplId, tplLabel] = ligne(
    "SELECT id, label FROM session_templates WHERE status='active' ORDER BY label LIMIT 1",
    'un modèle actif');
  const [debut, fin] = ligne(
    `SELECT generation_start_date, generation_end_date FROM session_templates WHERE id=${tplId}`,
    'la plage du modèle');
  const s = new Scenario(`S23 · Admin modèles — plus de « Générer & enregistrer » (modèle ${tplId})`);
  // Écran admin : desktop assumé (doctrine projet « Admin sur mobile : assumé desktop »).
  const { ctx, page } = await session(browser, 'admin@demo.club', DESKTOP);

  await page.goto(`${BASE}/admin/modeles`, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: new RegExp(tplLabel.slice(0, 20).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i') }).first().click();
  await page.waitForTimeout(900);

  const panneau = await page.locator('body').innerText();
  s.check('le bouton « Générer & enregistrer » a disparu', !/générer\s*&\s*enregistrer/i.test(panneau));
  // Contrôle positif apparié : le panneau de détail est bien rendu, pas vide.
  s.check('le panneau garde « Relancer / prolonger »', /relancer\s*\/\s*prolonger/i.test(panneau));
  // innerText applique text-transform : les field-label remontent en MAJUSCULES.
  const panneauMin = panneau.toLowerCase();
  for (const champ of ['Type', 'Discipline', 'Lieu', 'Capacité', 'Catégories ciblées']) {
    s.check(`le panneau affiche « ${champ} »`, panneauMin.includes(champ.toLowerCase()));
  }
  await s.shot(page, 's23-panneau-detail');

  // La modale de relance dit la vérité : rejouer la plage COURANTE ne crée rien.
  await page.getByRole('button', { name: /relancer \/ prolonger/i }).first().click();
  await page.waitForTimeout(900);
  const modale = page.locator('.dialog, [role="dialog"]').first();
  const dates = modale.locator('input[type=date]');
  await dates.nth(0).fill(String(debut).slice(0, 10));
  await page.waitForTimeout(700);
  await dates.nth(1).fill(String(fin).slice(0, 10));
  await page.waitForTimeout(1200);

  const txtModale = await modale.innerText();
  s.check('plage déjà générée → 0 nouvelle séance annoncée',
          /\b0\b/.test(txtModale) && /déjà entièrement générée/i.test(txtModale), txtModale.slice(0, 120));
  const boutonRelancer = modale.getByRole('button', { name: /relancer ·/i }).first();
  s.check('le bouton de relance est refusé à 0', await boutonRelancer.isDisabled().catch(() => true));
  await s.shot(page, 's23-modale-relance-zero');
  await modale.getByRole('button', { name: /annuler/i }).first().click();
  await page.waitForTimeout(600);

  // L'écran d'édition n'annonce plus de génération.
  await page.goto(`${BASE}/admin/modeles/${tplId}/modifier`, { waitUntil: 'networkidle' });
  const edition = await page.locator('body').innerText();
  s.check("l'édition n'annonce plus « À l'enregistrement »", !/à l'enregistrement/i.test(edition));
  s.check("l'édition dit ce qu'elle fait vraiment",
          /aucune séance n'est créée ni modifiée/i.test(edition));
  s.check('les dates sont relabellisées en plage de référence', /plage de référence/i.test(edition));
  await s.shot(page, 's23-edition-sans-generation');

  s.checkJs(page);
  await ctx.close();
  tous.push(s.report());
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES PARCOURS PASSENT' : '❌ AU MOINS UN PARCOURS ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
