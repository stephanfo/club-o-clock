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

// ── S31 · Éditeur de débrief : la zone de texte défile, la page derrière ne bouge pas (#98) ──
// Un texte long dépassait la zone de saisie sans la faire défiler : le surplus était coupé par la
// carte, et le doigt, le trackpad ou la molette faisaient défiler la page derrière (iPhone, iPad).
// Sur un écran bas (clavier Android qui réduit la fenêtre), la zone tombait à 0 px.
// Aucune écriture : l'édition est annulée, le débrief en base est comparé avant/après.
{
  const s31 = new Scenario('S31 · Éditeur de débrief — texte long, mobile, desktop et écran bas (#98)');
  const [debriefId, sessionId, email] = ligne(`SELECT d.id, d.session_id, u.email FROM debriefs d
      JOIN users u ON u.id = d.author_id WHERE d.archived_at IS NULL ORDER BY d.id LIMIT 1`, 'un débrief actif');
  const avant = sql(`SELECT MD5(content_markdown) FROM debriefs WHERE id=${debriefId}`);

  const formats = [
    { nom: 'mobile', viewport: MOBILE },
    { nom: 'desktop', viewport: DESKTOP },
    // Fenêtre réduite par le clavier (Samsung Internet, « resizes-content ») ou paysage.
    { nom: 'écran bas', viewport: { width: 390, height: 360 } },
  ];

  for (const { nom, viewport } of formats) {
    const { ctx, page } = await session(browser, email, viewport);
    await fiche(page, sessionId);
    // L'embed OpenRunner du parcours (script tiers) lève sa propre erreur au chargement de la fiche
    // desktop : hors de notre code. On ne compte que les erreurs levées à partir de l'éditeur.
    page.__erreursJs.length = 0;
    const onglet = page.locator('button.tab:visible', { hasText: 'Débriefs' });
    if (await onglet.count()) await onglet.first().click();
    await page.locator(`button:visible[wire\\:click="openDebrief(${debriefId})"]`).first().click();
    const zone = page.locator('.debrief-dialog .wys-area');
    await zone.waitFor();

    // Texte long : 60 paragraphes, bien au-delà de la hauteur de la zone.
    await zone.click();
    await page.keyboard.press('ControlOrMeta+End');
    for (let i = 1; i <= 60; i++) {
      await page.keyboard.press('Enter');
      await page.keyboard.insertText(`Paragraphe ${i} du récit de course, assez long pour occuper une ligne.`);
    }

    const mesure = () => zone.evaluate((el) => ({
      scroll: el.scrollHeight, client: el.clientHeight, top: el.scrollTop, page: window.scrollY,
    }));
    const m = await mesure();
    s31.check(`${nom} : la zone de texte garde une hauteur utile`, m.client >= 80, `${m.client}px`);
    s31.check(`${nom} : la zone de texte est le conteneur qui défile`, m.scroll > m.client, `${m.scroll} > ${m.client}`);

    // Retour au début à la molette, depuis le bas : c'est la zone qui défile, pas la page.
    await zone.evaluate((el) => { el.scrollTop = el.scrollHeight; });
    const bas = await mesure();
    const boite = await zone.boundingBox();
    await page.mouse.move(boite.x + boite.width / 2, boite.y + boite.height / 2);
    await page.mouse.wheel(0, -100000);
    await page.waitForTimeout(400);
    const haut = await mesure();
    s31.check(`${nom} : la molette remonte au début du texte`, bas.top > 0 && haut.top === 0, `${bas.top} → ${haut.top}`);
    s31.check(`${nom} : la page derrière ne défile pas`, haut.page === bas.page, `${bas.page} → ${haut.page}`);

    // Les deux actions restent dans la fenêtre.
    const pied = await page.locator('.debrief-editor-foot .btn-pink').boundingBox();
    s31.check(`${nom} : le bouton d'enregistrement est dans la fenêtre`,
              pied && pied.y >= 0 && pied.y + pied.height <= viewport.height, pied ? `${Math.round(pied.y + pied.height)} / ${viewport.height}` : 'absent');

    await page.screenshot({ path: new URL(`./shots/s31-${nom === 'écran bas' ? 'ecran-bas' : nom}.png`, import.meta.url).pathname });
    await page.locator('.debrief-editor-foot .btn-ghost').click();
    await page.locator('.debrief-dialog').waitFor({ state: 'detached' });
    s31.checkJs(page);
    await ctx.close();
  }

  s31.check('le débrief en base est inchangé (édition annulée)',
            sql(`SELECT MD5(content_markdown) FROM debriefs WHERE id=${debriefId}`) === avant);
  tous.push(s31.report());
}

// ── S32 · #104 · « Derniers débriefs » à l'accueil + badge débriefs sur le planning ──
{
  const s32 = new Scenario('S32 · Derniers débriefs — accueil et badge débriefs du planning');
  // Cible dérivée : la compétition au débrief actif le plus récent (le jeu de démo en sème à l'instant du seed).
  const [id, title, j, n] = ligne(`SELECT s.id, s.title, DATE(s.start_at) j, COUNT(*) n FROM sessions s JOIN debriefs d ON d.session_id=s.id
      WHERE d.archived_at IS NULL AND d.created_at >= NOW() - INTERVAL 15 DAY
      GROUP BY s.id, s.title, j ORDER BY MAX(d.created_at) DESC LIMIT 1`, 'une compétition débriefée récemment');
  const c = { id, title, j, n };

  for (const [nom, vp] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
    const { ctx, page } = await session(browser, 'marie@demo.club', vp);
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    const coque = page.locator(nom === 'mobile' ? '.home-mobile' : '.home-desktop');
    const bloc = coque.locator('.sect-head', { hasText: 'Derniers débriefs' });
    s32.check(`${nom} : bloc « Derniers débriefs » visible`, await bloc.isVisible());
    const lien = coque.locator(`a[href*="/seances/${c.id}?tab=debriefs"]`);
    s32.check(`${nom} : la ligne nomme la compétition et ses débriefs`,
      (await lien.innerText()).includes(c.title) && (await lien.innerText()).includes(`${c.n} débrief`));
    await bloc.scrollIntoViewIfNeeded();
    await s32.shot(page, `s32-accueil-debriefs-${nom}`);
    await lien.click();
    await page.waitForURL(`**/seances/${c.id}**`);
    await page.waitForLoadState('networkidle');
    // Mobile : onglet Débriefs ouvert. Desktop : pas d'onglets, ?tab= fait défiler jusqu'à la section.
    const ouvert = nom === 'mobile'
      ? await page.locator('[x-show="tab === \'debriefs\'"]').first().isVisible()
      : await page.locator('.fiche-desktop [data-section="debriefs"]').evaluate(el => {
          const r = el.getBoundingClientRect();
          return r.top >= -1 && r.top < window.innerHeight;
        });
    s32.check(`${nom} : la ligne ouvre les débriefs de la fiche`, ouvert);
    s32.checkJs(page);
    await ctx.close();
  }

  // Badge du planning (vue semaine sur le jour de la compétition) — contrôle positif + négatif.
  const { ctx, page } = await session(browser, 'marie@demo.club', DESKTOP);
  await page.goto(`${BASE}/planning?view=week&anchor=${c.j}`, { waitUntil: 'networkidle' });
  const carte = page.locator(`a.scard[href$="/seances/${c.id}"]`).first();
  s32.check('planning : la carte porte le nombre de débriefs', (await carte.innerText()).includes(`${c.n} débrief`));
  const sans = sql(`SELECT COUNT(*) FROM sessions WHERE DATE(start_at)='${c.j}' AND id<>${c.id}
      AND NOT EXISTS (SELECT 1 FROM debriefs d WHERE d.session_id=sessions.id AND d.archived_at IS NULL)`);
  s32.check('planning : seule la carte débriefée porte un badge',
    await page.locator('a.scard:visible', { hasText: 'débrief' }).count() === 1, `${sans} autre(s) séance(s) ce jour`);
  await s32.shot(page, 's32-planning-badge-desktop');
  await ctx.close();
  tous.push(s32.report());
}

// ── S33 · #106 · Vue « Courses » du planning ──
{
  const s33 = new Scenario('S33 · Planning — vue Courses (à venir sans borne, passées de la saison)');
  // Attendus dérivés de la base, avec le filtre de catégories de Marie (fallback ouvert sans catégorie).
  const visible = `(NOT EXISTS (SELECT 1 FROM session_category sc WHERE sc.session_id=s.id)
      OR EXISTS (SELECT 1 FROM session_category sc JOIN user_category uc ON uc.category_id=sc.category_id
                 JOIN users u ON u.id=uc.user_id WHERE sc.session_id=s.id AND u.email='marie@demo.club')
      OR NOT EXISTS (SELECT 1 FROM user_category uc JOIN users u ON u.id=uc.user_id WHERE u.email='marie@demo.club'))`;
  const mois = Number(sql('SELECT season_start_month FROM club_settings')) || 9;
  const t = new Date();
  const saison = `${t.getMonth() + 1 >= mois ? t.getFullYear() : t.getFullYear() - 1}-${String(mois).padStart(2, '0')}-01`;
  const titres = (q) => { const r = sql(q); return r ? r.split('\n') : []; };
  const aVenir = titres(`SELECT title FROM sessions s WHERE kind='competition' AND start_at >= CURDATE() AND ${visible} ORDER BY start_at`);
  const passees = titres(`SELECT title FROM sessions s WHERE kind='competition' AND start_at < CURDATE() AND start_at >= '${saison}' AND ${visible} ORDER BY start_at DESC`);
  s33.check('jeu de démo : au moins une course à venir, dont une à plus de 6 mois', aVenir.length > 0
    && Number(sql(`SELECT COUNT(*) FROM sessions s WHERE kind='competition' AND start_at > NOW() + INTERVAL 6 MONTH AND ${visible}`)) > 0);

  for (const [nom, vp] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
    const { ctx, page } = await session(browser, 'marie@demo.club', vp);
    await page.goto(`${BASE}/planning`, { waitUntil: 'networkidle' });
    const coque = page.locator(nom === 'mobile' ? '.planning-mobile' : '.planning-desktop');
    await coque.locator('.seg-item', { hasText: 'Courses' }).click();
    await page.waitForURL('**view=courses**');
    await page.waitForLoadState('networkidle');
    s33.check(`${nom} : segment « Courses » actif`, await coque.locator('.seg-item.on', { hasText: 'Courses' }).isVisible());
    s33.check(`${nom} : pas de navigation de période`,
      await page.locator('.weeknav:visible, .plan-weeknav:visible').count() === 0);

    const lus = async (cle) => coque.locator(`[data-courses="${cle}"] .scard-row-title`).allInnerTexts();
    const up = await lus('up');
    s33.check(`${nom} : « À venir » = toutes les courses futures, date croissante`,
      JSON.stringify(up) === JSON.stringify(aVenir), `${up.length} affichée(s) / ${aVenir.length}`);
    const past = await lus('past');
    s33.check(`${nom} : « Passées » = saison en cours, la plus récente en tête`,
      JSON.stringify(past) === JSON.stringify(passees), `${past.length} affichée(s) / ${passees.length}`);

    // Une course passée débriefée porte ses chips et ouvre l'onglet Débriefs.
    const debriefee = coque.locator('[data-courses="past"] a[href*="tab=debriefs"]').first();
    if (await debriefee.count()) {
      const txt = await debriefee.innerText();
      s33.check(`${nom} : la course débriefée affiche débriefs et participants`, /\d+ débrief/.test(txt) && /\d+ du club/.test(txt));
    } else {
      s33.check(`${nom} : aucune course passée débriefée cette saison (rien à contrôler)`, passees.length === 0 ||
        Number(sql(`SELECT COUNT(*) FROM debriefs d JOIN sessions s ON s.id=d.session_id WHERE s.kind='competition' AND s.start_at >= '${saison}' AND d.archived_at IS NULL`)) === 0);
    }
    await s33.shot(page, `s33-planning-courses-${nom}`);
    s33.checkJs(page);
    await ctx.close();
  }
  // Garant de deux enfants (390px) : 3 pastilles + 4 vues sur la même rangée, sans chevauchement.
  {
    const garant = sql(`SELECT g.email FROM users c JOIN users g ON g.id=c.guardian_id GROUP BY g.email ORDER BY COUNT(*) DESC LIMIT 1`);
    const { ctx, page } = await session(browser, garant, MOBILE);
    await page.goto(`${BASE}/planning?view=courses`, { waitUntil: 'networkidle' });
    const pastilles = page.locator('.plan-viewbar-m .plan-subj-pill');
    const n = await pastilles.count();
    const derniere = await pastilles.last().boundingBox();
    const seg = await page.locator('.plan-viewbar-m .seg').boundingBox();
    s33.check(`garant (${n} pastilles) : le segment ne chevauche pas la dernière pastille`,
      n >= 2 && derniere && seg && derniere.x + derniere.width <= seg.x && seg.x + seg.width <= MOBILE.width);
    await s33.shot(page, 's33-planning-courses-garant-mobile');
    await ctx.close();
  }
  tous.push(s33.report());
}

// ── S34 · #105 · Ouverture des inscriptions : vue Courses, fiche, formulaire (pas l'accueil) ──
{
  const s34 = new Scenario('S34 · Ouverture des inscriptions — Courses, fiche, formulaire, absente de l\'accueil');
  const [id] = ligne(`SELECT id FROM sessions WHERE kind='competition' AND cancelled_at IS NULL AND start_at > NOW()
      AND registration_opens_at > NOW() ORDER BY registration_opens_at LIMIT 1`, 'une compétition dont les inscriptions ouvrent bientôt');

  for (const [nom, vp] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
    const { ctx, page } = await session(browser, 'marie@demo.club', vp);
    // Accueil : réservé aux débriefs (choix du 01/10), aucune ouverture n'y figure.
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    s34.check(`${nom} : accueil — aucune ouverture d'inscriptions`,
      await page.locator('[data-inscriptions], [data-ouverture]').count() === 0);

    await page.goto(`${BASE}/planning?view=courses`, { waitUntil: 'networkidle' });
    const pc = page.locator(nom === 'mobile' ? '.planning-mobile' : '.planning-desktop');
    s34.check(`${nom} : vue Courses — état des inscriptions sur la course`,
      await pc.locator(`[data-courses="up"] a[href$="/seances/${id}"] [data-inscriptions]`).isVisible());
    s34.check(`${nom} : vue Courses — aucun état sur les courses passées`,
      await pc.locator('[data-courses="past"] [data-inscriptions]').count() === 0);
    await s34.shot(page, `s34-courses-ouvertures-${nom}`);

    s34.checkJs(page);
    // Contrôle JS AVANT la fiche : l'embed OpenRunner des compétitions de démo lève sa propre erreur
    // (openrunner-embed.es.js, « reading 'code' »), étrangère à l'app.
    await page.goto(`${BASE}/seances/${id}`, { waitUntil: 'networkidle' });
    s34.check(`${nom} : fiche — rubrique Inscriptions`, await page.locator('dt:visible', { hasText: 'Inscriptions' }).count() > 0);
    await ctx.close();
  }

  // Formulaire coach : les deux champs, pré-remplis, au format mobile (le plus étroit).
  const { ctx, page } = await session(browser, 'vincent@demo.club', MOBILE);
  await page.goto(`${BASE}/seances/${id}/modifier`, { waitUntil: 'networkidle' });
  const date = page.locator('input[type="date"][wire\\:model\\.blur="registration_opens_date"]');
  const heure = page.locator('input[type="time"][wire\\:model\\.blur="registration_opens_time"]');
  s34.check('formulaire : date et heure d\'ouverture pré-remplies',
    await date.inputValue() !== '' && await heure.inputValue() !== '');
  await date.scrollIntoViewIfNeeded();
  await s34.shot(page, 's34-formulaire-ouverture-mobile');
  s34.checkJs(page);
  await ctx.close();
  tous.push(s34.report());
}

// ── S35 · #101 · Réaction « j'aime » sur un débrief : poser, voir, retirer ; l'auteur voit le compte ──
// Restaure l'état : le « j'aime » posé est retiré, et sa notification en attente avec lui.
{
  const s35 = new Scenario('S35 · Réaction « j\'aime » — lecteur et auteur, mobile et desktop (#101)');
  const [debriefId, sessionId, auteur] = ligne(`SELECT d.id, d.session_id, u.email FROM debriefs d
      JOIN users u ON u.id = d.author_id WHERE d.archived_at IS NULL ORDER BY d.id LIMIT 1`, 'un débrief actif');
  const [lecteur] = ligne(`SELECT u.email FROM users u WHERE u.is_active = 1 AND u.email LIKE '%@demo.club'
      AND u.id <> (SELECT author_id FROM debriefs WHERE id=${debriefId})
      AND NOT EXISTS (SELECT 1 FROM debrief_reactions r WHERE r.debrief_id=${debriefId} AND r.user_id=u.id)
      ORDER BY u.id LIMIT 1`, 'un lecteur qui n\'a pas encore aimé ce débrief');
  const nb = () => Number(sql(`SELECT COUNT(*) FROM debrief_reactions WHERE debrief_id=${debriefId}`));
  const avant = nb();
  s35.check('contrôle positif : le débrief a déjà des « j\'aime »', avant > 0);
  // Des notifications en attente peuvent préexister (« j'aime » posés à la main sur la démo) :
  // on compare avant / après plutôt que d'exiger zéro.
  const enAttente = () => sql(`SELECT COUNT(*) FROM notification_outbox WHERE type='debrief_reaction' AND status='pending'`);
  const attenteAvant = enAttente();

  for (const [nom, vp] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
    const { ctx, page } = await session(browser, lecteur, vp);
    await fiche(page, sessionId);
    page.__erreursJs.length = 0; // embed OpenRunner : erreur tierce au chargement de la fiche
    const onglet = page.locator('button.tab:visible', { hasText: 'Débriefs' });
    if (await onglet.count()) await onglet.first().click();
    const bouton = page.locator(`button.react-btn:visible[wire\\:click="toggleReaction(${debriefId})"]`).first();
    s35.check(`${nom} : bouton « J'aime · ${avant} »`, (await bouton.innerText()).includes(`J'aime · ${avant}`));

    await bouton.click();
    await page.waitForFunction((id) => document.querySelector(`button.react-btn.on[wire\\:click="toggleReaction(${id})"]`), debriefId);
    s35.check(`${nom} : « j'aime » posé en base`, nb() === avant + 1);
    s35.check(`${nom} : « Toi » en tête des noms`, (await page.locator('.react-who:visible').first().innerText()).startsWith('Toi'));
    await s35.shot(page, `s35-reaction-${nom}`);

    await bouton.click();
    await page.waitForFunction((id) => !document.querySelector(`button.react-btn.on[wire\\:click="toggleReaction(${id})"]`), debriefId);
    s35.check(`${nom} : « j'aime » retiré (état restauré)`, nb() === avant);
    s35.checkJs(page);
    await ctx.close();
  }
  s35.check('aucune notification de réaction laissée en attente par le scénario', enAttente() === attenteAvant);

  // L'auteur : le compteur et les noms, sans bouton.
  const { ctx, page } = await session(browser, auteur, MOBILE);
  await fiche(page, sessionId);
  await page.locator('button.tab:visible', { hasText: 'Débriefs' }).first().click();
  s35.check('auteur : compteur affiché', await page.locator('.react-count:visible').first().waitFor({ timeout: 5000 }).then(() => true, () => false));
  s35.check('auteur : pas de bouton « J\'aime » sur son débrief',
    await page.locator(`button.react-btn[wire\\:click="toggleReaction(${debriefId})"]`).count() === 0);
  await s35.shot(page, 's35-reaction-auteur-mobile');
  await ctx.close();
  tous.push(s35.report());
}

// ── S36 · #97 · Onglet Notifs : avertissement sans appareil, « Mes appareils », retrait ──
// Les appareils sont insérés en base (aucun vrai abonnement push en navigateur headless), puis
// supprimés en fin de scénario : l'état est restauré.
{
  const s36 = new Scenario('S36 · Push sur mes appareils — avertissement, liste, retrait (#97)');
  const email = 'marie@demo.club';
  const [uid] = ligne(`SELECT id FROM users WHERE email='${email}'`, 'le compte de démo');
  const nb = () => Number(sql(`SELECT COUNT(*) FROM push_subscriptions WHERE user_id=${uid}`));
  s36.check('contrôle : aucun appareil abonné au départ', nb() === 0);
  const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
  const ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36';
  const ajoute = (ep, ua, extra) => sql(`INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, content_encoding, user_agent, last_success_at, failure_count, created_at, updated_at)
      VALUES (${uid}, '${ep}', SHA2('${ep}', 256), 'k', 'a', 'aes128gcm', '${ua}', ${extra}, NOW() - INTERVAL 20 DAY, NOW())`);

  try {
    for (const [nom, vp] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
      const { ctx, page } = await session(browser, email, vp);
      await page.goto(`${BASE}/profil?tab=notifs`, { waitUntil: 'networkidle' });
      s36.check(`${nom} : avertissement « aucun appareil »`, await page.locator('[data-push-aucun]:visible').count() === 1);
      s36.check(`${nom} : pas de liste sans appareil`, await page.locator('[data-push-appareil]').count() === 0);
      await s36.shot(page, `s36-notifs-sans-appareil-${nom}`);
      s36.checkJs(page);
      await ctx.close();
    }

    ajoute('https://e2e.invalid/s36-iphone', IPHONE, 'NOW() - INTERVAL 2 HOUR, 0');
    ajoute('https://e2e.invalid/s36-android', ANDROID, 'NULL, 2');

    for (const [nom, vp] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
      const { ctx, page } = await session(browser, email, vp);
      await page.goto(`${BASE}/profil?tab=notifs`, { waitUntil: 'networkidle' });
      const lignes = page.locator('[data-push-appareil]:visible');
      s36.check(`${nom} : deux appareils listés`, await lignes.count() === 2);
      s36.check(`${nom} : plus d'avertissement`, await page.locator('[data-push-aucun]').count() === 0);
      const texte = await page.locator('[data-push-appareil]:visible').allInnerTexts();
      s36.check(`${nom} : libellés et dernier envoi`, texte.join(' ').includes('iPhone · Safari') && texte.join(' ').includes('Dernier envoi réussi'));
      await s36.shot(page, `s36-mes-appareils-${nom}`);
      s36.checkJs(page);
      await ctx.close();
    }

    // Retrait d'un autre appareil (wire:confirm natif, accepté).
    const { ctx, page } = await session(browser, email, MOBILE);
    await page.goto(`${BASE}/profil?tab=notifs`, { waitUntil: 'networkidle' });
    page.once('dialog', (d) => d.accept());
    await page.locator('[data-push-appareil]:visible', { hasText: 'Android' }).locator('button', { hasText: 'Retirer' }).click();
    await page.locator('[data-push-appareil]:visible').nth(1).waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
    s36.check('retrait : un appareil en moins en base', nb() === 1);
    s36.check('retrait : un appareil listé', await page.locator('[data-push-appareil]:visible').count() === 1);
    s36.checkJs(page);
    await ctx.close();
  } finally {
    sql(`DELETE FROM push_subscriptions WHERE user_id=${uid} AND endpoint LIKE 'https://e2e.invalid/s36-%'`);
    s36.check('état restauré : aucun appareil', nb() === 0);
  }
  tous.push(s36.report());
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES SCÉNARIOS RESPONSIVE PASSENT' : '❌ AU MOINS UN SCÉNARIO RESPONSIVE ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
