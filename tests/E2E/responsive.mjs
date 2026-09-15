import { launch, session, fiche, sql, seance, ligne, Scenario, MOBILE, DESKTOP, BASE } from './lib.mjs';

const browser = await launch();
const tous = [];
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

tous.push(s.report());

// ── S24 · #33 · Vue Semaine mobile : la carte porte la plage horaire, pas la date ──
{
  const s24 = new Scenario('S24 · Semaine mobile — la carte dit la plage horaire, plus le jour');
  // Un jour à PLUSIEURS séances : deux lignes d'heures alignées sont justement le cas où le
  // rendu peut se lire comme une plage unique.
  const [jour] = ligne(`SELECT DATE(start_at) j FROM sessions WHERE cancelled_at IS NULL
      GROUP BY j HAVING COUNT(*) > 1 ORDER BY ABS(DATEDIFF(j, CURDATE())) LIMIT 1`,
    'un jour à plusieurs séances');

  const { ctx, page } = await session(browser, 'marie@demo.club', MOBILE);
  await page.goto(`${BASE}/planning?view=week&anchor=${jour}`, { waitUntil: 'networkidle' });

  const colonnes = page.locator('.plan-daylist-m .scard-row-date');
  const n = await colonnes.count();
  s24.check('des cartes sont rendues en semaine mobile', n > 0, `${n} carte(s)`);

  const texte = (await colonnes.first().innerText()).trim();
  // Deux heures, rien d'autre : plus de jour abrégé ni de numéro de quantième.
  s24.check('la colonne porte deux heures', /^\d{1,2}:\d{2}\s*\n\s*\d{1,2}:\d{2}$/.test(texte),
            JSON.stringify(texte));
  // La fin est postérieure au début — on rend bien une plage, pas deux fois la même heure.
  const [debut, fin] = texte.split('\n').map((l) => l.trim());
  s24.check('la fin suit le début', fin > debut, `${debut} → ${fin}`);

  // L'en-tête de jour, lui, ne bouge pas : c'est lui qui porte la date (l'issue le dit).
  const entete = (await page.locator('.plan-dayhead-m').first().innerText()).toLowerCase();
  s24.check("l'en-tête de jour garde la date", /\d/.test(entete) && /lun|mar|mer|jeu|ven|sam|dim/.test(entete),
            entete.replace(/\s+/g, ' ').slice(0, 40));
  await page.screenshot({ path: new URL('./shots/s24-semaine-mobile-plage.png', import.meta.url).pathname });

  // Contrôle positif apparié : ailleurs, la colonne garde la date — c'est elle qui situe la
  // séance dans une liste non groupée par jour.
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  const accueil = page.locator('.scard-row-date');
  if (s24.check("l'accueil rend au moins une carte datée", await accueil.count() > 0)) {
    const t = (await accueil.first().innerText()).trim().toLowerCase();
    s24.check("l'accueil garde le jour dans la colonne", /lun|mar|mer|jeu|ven|sam|dim/.test(t),
              JSON.stringify(t));
  }
  s24.checkJs(page);
  await ctx.close();
  tous.push(s24.report());
}

// ── S25 · #36 · Dialog destructif : la sortie sûre n'est pas sous l'action irréversible ──
{
  const s25 = new Scenario('S25 · Dialog destructif — la sortie sûre au-dessus sur mobile');
  // Compte dérivé : le bouton est masqué pour le dernier admin et pour un garant de mineur sans
  // compte propre (deux gardes du formulaire), et il disparaît si une demande est déjà en cours.
  const [email] = ligne(`SELECT email FROM users WHERE is_active=1 AND deletion_requested_at IS NULL
      AND JSON_SEARCH(roles, 'one', 'admin') IS NULL
      AND id NOT IN (SELECT guardian_id FROM users WHERE guardian_id IS NOT NULL)
      ORDER BY id LIMIT 1`, 'un compte pouvant demander sa suppression');

  for (const [nom, vp] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
    const { ctx, page } = await session(browser, email, vp);
    await page.goto(`${BASE}/profil?tab=connexion`, { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: /supprimer mon compte/i }).first().click();
    await page.waitForTimeout(1200);

    // Plusieurs dialogs coexistent dans le DOM de cet onglet (déconnexion des autres appareils) :
    // on cible celui-ci par son titre, pas par sa position.
    // Les deux coquilles (mobile et desktop) rendent chacune leur dialog ; une seule est visible,
    // et l'onglet en contient d'autres (déconnexion des appareils). On cible par visibilité ET titre.
    const modale = page.locator('.dialog:visible').filter({ hasText: 'Supprimer mon compte ?' }).first();
    const pied = modale.locator('.dialog-foot').first();
    const sortie = pied.getByRole('button', { name: /annuler/i }).first();
    const destructif = pied.getByRole('button', { name: /envoyer la demande/i }).first();
    const bSortie = await sortie.boundingBox();
    const bDestructif = await destructif.boundingBox();

    if (s25.check(`${nom} : le pied du dialog est mesurable`, !!bSortie && !!bDestructif)) {
      if (nom === 'mobile') {
        // Le cœur de #36 : empilés, l'irréversible ne doit pas surplomber la sortie sûre.
        s25.check('mobile : la sortie sûre est AU-DESSUS de l\'action irréversible',
                  bSortie.y < bDestructif.y, `annuler y=${bSortie.y} · envoyer y=${bDestructif.y}`);
      } else {
        // Contrôle apparié : l'ordre desktop n'a pas bougé — même rangée, sûre à gauche.
        s25.check('desktop : les deux actions restent sur la même rangée',
                  Math.abs(bSortie.y - bDestructif.y) < 4, `${bSortie.y} vs ${bDestructif.y}`);
        s25.check('desktop : la sortie sûre reste à gauche', bSortie.x < bDestructif.x);
      }
    }
    await page.screenshot({ path: new URL(`./shots/s25-dialog-danger-${nom}.png`, import.meta.url).pathname });

    // On referme sans rien envoyer : scénario non destructif.
    await sortie.click();
    await page.waitForTimeout(800);
    s25.checkJs(page);
    await ctx.close();
  }

  s25.check('aucune demande de suppression n\'a été créée',
            sql(`SELECT COUNT(*) n FROM users WHERE deletion_requested_at IS NOT NULL AND email='${email}'`) === '0');
  tous.push(s25.report());
}

// ── S26 · #50 · Identité des cartes : après navigation, chaque carte rend SA séance ──
{
  const s26 = new Scenario('S26 · Morphing — la carte rend bien la séance de son lien');

  // Vérité en base : id → titre. C'est à ELLE qu'on confronte le rendu, pas à un instantané
  // précédent — un contenu croisé par morphing produit justement une carte cohérente avec
  // elle-même, mais menteuse sur le lien qu'elle porte.
  const titres = new Map(sql('SELECT id, title FROM sessions').split('\n')
    .filter(Boolean).map((l) => l.split(' | ').map((c) => c.trim())));

  // Cartes VISIBLES uniquement : les deux coquilles sont dans le DOM, une seule est rendue.
  // Les variantes `row` et `week` portent le titre ; la variante `pill` (vue Mois) ne le porte
  // pas — le contrôle de contenu ne vaut donc que sur la vue Semaine.
  const releve = (page) => page.$$eval('a.scard:visible', (els) => els.map((e) => ({
    id: (e.getAttribute('href').match(/\/seances\/(\d+)/) || [])[1],
    texte: e.innerText,
    cle: e.getAttribute('wire:key'),
  })));

  // Retourne le libellé de la première incohérence, ou null.
  const croisee = (cartes) => {
    for (const c of cartes) {
      const attendu = titres.get(c.id);
      if (!attendu) return `/seances/${c.id} inconnue en base`;
      if (!c.texte.includes(attendu)) {
        return `/seances/${c.id} devrait dire « ${attendu} », rend ${JSON.stringify(c.texte.replace(/\s+/g, ' ').slice(0, 60))}`;
      }
    }
    return null;
  };

  const clesUniques = (cartes) => {
    const vues = cartes.map((c) => c.cle);
    return vues.every(Boolean) && new Set(vues).size === vues.length;
  };

  for (const [nom, vp] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
    const { ctx, page } = await session(browser, 'marie@demo.club', vp);
    await page.goto(`${BASE}/planning?view=week`, { waitUntil: 'networkidle' });

    const avant = await releve(page);
    // Contrôle positif : sans cartes, tout ce qui suit serait vrai à vide.
    if (!s26.check(`${nom} : la semaine rend des cartes`, avant.length > 0, `${avant.length} carte(s)`)) {
      await ctx.close();
      continue;
    }
    s26.check(`${nom} : chaque carte porte une wire:key distincte`, clesUniques(avant),
              avant.map((c) => c.cle).join(', ').slice(0, 90));
    s26.check(`${nom} : chaque carte rend sa propre séance`, croisee(avant) === null, croisee(avant) ?? '');

    // Changement de semaine : c'est CE re-rendu que le morphing traite, et sans clé il apparie
    // les cartes par position.
    await page.locator('button[aria-label="Suivant"]:visible').first().click();
    await page.waitForTimeout(1200);
    const apres = await releve(page);
    // Contrôle positif apparié : la liste a bien changé — sinon le morphing n'a rien eu à faire
    // et l'assertion qui suit ne prouverait rien.
    const memes = avant.map((c) => c.id).join() === apres.map((c) => c.id).join();
    s26.check(`${nom} : la semaine suivante rend une autre liste`, !memes,
              `${avant.length} → ${apres.length} carte(s)`);
    s26.check(`${nom} : après changement de semaine, aucun contenu croisé`,
              croisee(apres) === null, croisee(apres) ?? '');
    s26.check(`${nom} : les clés restent distinctes`, clesUniques(apres));
    await page.screenshot({ path: new URL(`./shots/s26-semaine-suivante-${nom}.png`, import.meta.url).pathname });

    // Filtre discipline — masqué sur mobile (is-hidden-temp), donc desktop seulement.
    if (nom === 'desktop') {
      const chips = page.locator('.dk-plan-filters .chip:visible');
      const n = await chips.count();
      if (s26.check('desktop : les chips de filtre sont rendus', n > 1, `${n} chip(s)`)) {
        await chips.nth(1).click();
        await page.waitForTimeout(1200);
        const filtre = await releve(page);
        s26.check('desktop : après filtrage, aucun contenu croisé',
                  croisee(filtre) === null, croisee(filtre) ?? `${filtre.length} carte(s)`);
        s26.check('desktop : les clés restent distinctes après filtrage', clesUniques(filtre));
        await page.screenshot({ path: new URL('./shots/s26-filtre-discipline.png', import.meta.url).pathname });
      }
    }

    s26.checkJs(page);
    await ctx.close();
  }

  tous.push(s26.report());
}

// ── S27 · #69 · Semaine mobile : la liste s'ouvre sur le jour courant ──
// Le CHOIX du jour (courant, prochain peuplé, rien hors semaine courante) est couvert par PHPUnit
// (PlanningWeekArrivalTest, horloge figée). Ici, ce que PHPUnit ne voit pas : le défilement réel,
// et qu'il ne se rejoue ni sur un morphing, ni au retour arrière, ni en changeant de semaine.
{
  const s27 = new Scenario('S27 · Semaine mobile — ouverture sur le jour courant (#69)');

  // Le jeu de démo ne garantit pas de séance AVANT aujourd'hui dans la semaine : sans elle, le jour
  // courant est déjà en tête et « la liste est positionnée » ne prouverait rien. On en pose une le
  // lundi à 10 h UTC (même date en heure club, été comme hiver), et on la retire à la fin.
  const tz = sql('SELECT timezone FROM club_settings LIMIT 1') || 'Europe/Paris';
  const aujourdhui = new Intl.DateTimeFormat('en-CA', { timeZone: tz }).format(new Date());
  const rang = (new Date(aujourdhui + 'T12:00:00Z').getUTCDay() + 6) % 7;   // 0 = lundi
  const lundi = new Date(Date.parse(aujourdhui + 'T12:00:00Z') - rang * 86400000).toISOString().slice(0, 10);
  const titre = 'E2E #69 séance du lundi';
  const admin = sql("SELECT id FROM users WHERE email='admin@demo.club'");
  if (rang > 0) {
    sql(`INSERT INTO sessions (kind, title, start_at, duration_min, visibility, created_by, created_at, updated_at)
         VALUES ('training', '${titre}', '${lundi} 10:00:00', 60, 'all', ${admin}, NOW(), NOW())`);
  }

  const { ctx, page } = await session(browser, 'marie@demo.club', MOBILE);
  const etat = () => page.evaluate(() => {
    const c = [...document.querySelectorAll('.plan-scroll-m')].find((e) => e.offsetParent);
    const a = c.querySelector('[data-arrivee]');
    const groupes = [...c.querySelectorAll('.plan-daygroup-m')];
    return {
      top: Math.round(c.scrollTop),
      max: c.scrollHeight - c.clientHeight,
      arrivee: a?.getAttribute('wire:key') ?? null,
      avant: a ? groupes.indexOf(a) : -1,
      ecart: a ? Math.round(a.getBoundingClientRect().top - c.getBoundingClientRect().top) : null,
      premier: groupes[0]?.getAttribute('wire:key') ?? null,
    };
  });
  const defiler = (y) => page.evaluate((y) => {
    [...document.querySelectorAll('.plan-scroll-m')].find((e) => e.offsetParent).scrollTop = y;
  }, y);

  // 1. Arrivée
  await page.goto(`${BASE}/planning`, { waitUntil: 'networkidle' });
  const arrivee = await etat();
  await page.screenshot({ path: new URL('./shots/s27-arrivee-mobile.png', import.meta.url).pathname });
  if (s27.check('un jour d\'arrivée est désigné dans la semaine courante', arrivee.arrivee !== null, arrivee.arrivee ?? 'aucun')) {
    // Contrôle positif : des jours existent AU-DESSUS — sinon « positionné » vaudrait pour une liste
    // qui n'a simplement pas bougé.
    s27.check('des jours antérieurs restent rendus au-dessus', arrivee.avant > 0 || rang === 0,
              rang === 0 ? 'lundi : aucun jour antérieur possible' : `${arrivee.avant} groupe(s) au-dessus`);
    // Aligné en haut — ou défilé au maximum quand la fin de semaine est trop courte pour y monter.
    s27.check('le groupe du jour est en haut de la liste, sans défilement manuel',
              Math.abs(arrivee.ecart) <= 1 || arrivee.top >= arrivee.max - 1,
              `écart ${arrivee.ecart}px, défilement ${arrivee.top}/${arrivee.max}`);
  }

  // 2. Un morphing Livewire ne ramène pas au jour courant
  await defiler(0);
  await page.evaluate(() => window.Livewire.all()[0].$wire.$refresh());
  await page.waitForTimeout(1200);
  s27.check('un re-rendu Livewire ne rejoue pas le positionnement', (await etat()).top === 0,
            `défilement ${(await etat()).top}`);

  // 3. Retour arrière depuis une fiche séance : la position quittée est rendue. On vise une position
  // NON NULLE et distincte de l'arrivée : 0 serait aussi le résultat d'un retour sans restauration.
  const cible = arrivee.top !== arrivee.max ? arrivee.max : Math.floor(arrivee.max / 2);
  await defiler(cible);
  await page.waitForTimeout(200);
  s27.check('la position de départ diffère de celle de l\'arrivée', cible !== arrivee.top,
            `départ ${cible}, arrivée ${arrivee.top}`);
  // Une carte DÉJÀ à l'écran : avant de cliquer, Playwright fait défiler jusqu'à sa cible, ce qui
  // déplacerait la liste avant même le départ si l'on visait la première carte du lundi.
  const visible = await page.evaluate(() => {
    const c = [...document.querySelectorAll('.plan-scroll-m')].find((e) => e.offsetParent);
    const cadre = c.getBoundingClientRect();
    return [...c.querySelectorAll('.scard-row')].findIndex((k) => {
      const r = k.getBoundingClientRect();
      return r.height > 0 && r.top >= cadre.top + 48 && r.bottom <= cadre.bottom - 160;
    });
  });
  s27.check('une carte est visible à la position de départ', visible >= 0);
  await page.locator('.plan-scroll-m .scard-row:visible').nth(Math.max(visible, 0)).click();
  s27.check('le clic n\'a pas déplacé la liste', Math.abs((await etat()).top - cible) <= 1);
  await page.waitForURL(/\/seances\/\d+/);
  await page.waitForLoadState('networkidle');
  await page.locator('[onclick*="clubBack"]:visible').first().click();
  await page.waitForURL(/\/planning/);
  await page.waitForTimeout(1500);
  const retour = await etat();
  s27.check('au retour arrière, la liste reprend la position quittée', Math.abs(retour.top - cible) <= 1,
            `attendu ${cible}, obtenu ${retour.top}`);

  // 4. Changer de semaine repart du haut ; revenir sur la semaine courante ne recale pas
  await defiler(retour.max);
  await page.waitForTimeout(200);
  const avantSuivant = await etat();
  await page.locator('.plan-weeknav button[aria-label="Suivant"]').click();
  await page.waitForTimeout(1200);
  const suivante = await etat();
  s27.check('contrôle positif : la semaine suivante est bien rendue', suivante.premier !== avantSuivant.premier,
            `${avantSuivant.premier} → ${suivante.premier}`);
  s27.check('la semaine suivante s\'ouvre en haut', avantSuivant.top > 0 && suivante.top === 0,
            `défilement ${avantSuivant.top} → ${suivante.top}`);
  s27.check('hors semaine courante, aucun jour n\'est désigné', suivante.arrivee === null);
  await page.locator('.plan-weeknav button[aria-label="Précédent"]').click();
  await page.waitForTimeout(1200);
  const revenue = await etat();
  s27.check('revenir sur la semaine courante ne force pas le recalage',
            revenue.arrivee !== null && revenue.top === 0, `défilement ${revenue.top}, jour ${revenue.arrivee}`);
  s27.checkJs(page);
  await ctx.close();

  // 5. Desktop : grille inchangée, rien ne défile
  {
    const { ctx, page } = await session(browser, 'marie@demo.club', DESKTOP);
    await page.goto(`${BASE}/planning`, { waitUntil: 'networkidle' });
    const y = await page.evaluate(() => window.scrollY);
    s27.check('desktop : la grille est rendue', await page.locator('.wk-grid-dk').isVisible());
    s27.check('desktop : la page ne défile pas à l\'arrivée', y === 0, `${y}px`);
    await page.screenshot({ path: new URL('./shots/s27-desktop.png', import.meta.url).pathname });
    s27.checkJs(page);
    await ctx.close();
  }

  // Restauration du jeu de démo
  if (rang > 0) {
    sql(`DELETE FROM sessions WHERE title='${titre}'`);
    s27.check('séance temporaire retirée', sql(`SELECT COUNT(*) FROM sessions WHERE title='${titre}'`) === '0');
  }

  tous.push(s27.report());
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES SCÉNARIOS RESPONSIVE PASSENT' : '❌ AU MOINS UN SCÉNARIO RESPONSIVE ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
