// Trombinoscope (#72, PRD §4.23) — grille et fiche selon le lecteur, accueil, listes d'inscrits,
// réglages du profil et de l'enfant, onglet admin.
//
// NON destructif : le seul accord posé (T4) est retiré dans le même scénario, et ses lignes du
// registre et du journal sont effacées par leur id.
import { launch, session, fiche, sql, seance, repereJournaux, purgeJournaux, Scenario, MOBILE, DESKTOP, BASE } from './lib.mjs';

const browser = await launch();
const tous = [];
const attendre = (page) => page.waitForLoadState('networkidle');
const id = (email) => sql(`SELECT id FROM users WHERE email='${email}'`);
const texte = async (page) => (await page.locator('body').innerText()).toLowerCase();
/**
 * Les vignettes DANS LA FENÊTRE ont-elles réellement chargé (pas d'image cassée) ? Les autres sont en
 * `loading=lazy` : hors écran, le navigateur ne les demande pas encore — les exiger serait faux.
 */
const imagesChargees = (page, sel) => page.waitForFunction((sel) => {
  const vues = [...document.querySelectorAll(sel)].filter(i => {
    const r = i.getBoundingClientRect();
    return i.offsetParent && r.bottom > 0 && r.top < innerHeight;
  });
  return vues.length > 0 && vues.every(i => i.complete && i.naturalWidth > 0);
}, sel, { timeout: 5000 }).then(() => true, () => false);

// ── T1 · Marie : accueil, grille, filtre Bureau, fiche d'un majeur qui a tout partagé ──
for (const [format, viewport] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
  const s = new Scenario(`T1 · Marie consulte le trombinoscope (${format})`);
  const { ctx, page } = await session(browser, 'marie@demo.club', viewport);

  // Accueil : Camille fête son anniversaire cette semaine (DemoSeeder) ; jour et prénom seuls.
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const bloc = page.locator('.sect-head:visible', { hasText: 'Anniversaires de la semaine' });
  s.check('bloc « Anniversaires de la semaine » à l’accueil', await bloc.count() === 1);
  const accueil = await texte(page);
  s.check('Camille y figure, sans son nom ni son année', accueil.includes('camille') && !accueil.includes('leroy'));
  if (format === 'mobile') {
    // Le bloc est sous la pliure, entre deux barres fixes : on le centre avant la capture.
    await bloc.evaluate(e => e.scrollIntoView({ block: 'center' }));
    await s.shot(page, 'trombi-accueil-mobile');
    await page.locator('a:visible', { hasText: 'Trombinoscope du club' }).click();
  } else {
    s.check('desktop : entrée de la barre latérale', await page.locator('a:visible', { hasText: /^\s*Trombinoscope\s*$/ }).count() >= 1);
    await page.locator('a:visible', { hasText: /^\s*Trombinoscope\s*$/ }).first().click();
  }
  await page.waitForURL(/\/trombinoscope$/);
  await attendre(page);

  const grille = await texte(page);
  s.check('nom complet consenti (Paul Fournier)', grille.includes('paul fournier'));
  s.check('sans accord : prénom et initiale (Lucas M.)', grille.includes('lucas m.') && !grille.includes('lucas martin'));
  s.check('fonction au bureau sans accord', grille.includes('président'));
  s.check('suspendu absent (Kévin), contrôle positif sur Marie', !grille.includes('kévin') && grille.includes('marie'));
  s.check('vignettes chargées (route servie sous policy)', await imagesChargees(page, '.trombi-card img'));
  const lucas = page.locator('.trombi-card:visible', { hasText: 'Lucas M.' });
  s.check('Lucas : initiales, aucune image', await lucas.count() === 1 && await lucas.locator('img').count() === 0);
  await s.shot(page, `trombi-grille-${format}`);

  const filtre = page.locator('button.chip:visible', { hasText: /^Bureau$/ });
  await filtre.click();
  await page.waitForFunction(() => [...document.querySelectorAll('button.chip')].some(b => b.offsetParent && b.textContent.trim() === 'Bureau' && b.getAttribute('aria-pressed') === 'true'));
  const bureau = await page.locator('.trombi-card:visible .trombi-card-name').allInnerTexts();
  s.check('filtre Bureau : 4 cartes, présidence en tête', bureau.length === 4 && /paul/i.test(bureau[0]), bureau.join(', '));

  await page.locator('.trombi-card:visible', { hasText: 'Sophie' }).click();
  await page.waitForURL(/\/trombinoscope\/\d+$/);
  await attendre(page);
  s.check('fiche : lien d’appel', await page.locator('a[href^="tel:+33639980112"]:visible').count() === 1);
  s.check('fiche : lien d’écriture', await page.locator('a[href="mailto:sophie@demo.club"]:visible').count() === 1);
  s.check('fiche : photo 512 chargée', await imagesChargees(page, '.avatar.xxl img'));
  await s.shot(page, `trombi-fiche-${format}`);
  s.checkJs(page);
  tous.push(s.report());
  await ctx.close();
}

// ── T2 · Théo (mineur, P2) : visages oui, coordonnées jamais — y compris dans le HTML ──
{
  const s = new Scenario('T2 · Un adolescent ne reçoit aucune coordonnée (mobile)');
  const { ctx, page } = await session(browser, 'theo.mercier@demo.club', MOBILE);
  await page.goto(`${BASE}/trombinoscope/${id('sophie@demo.club')}`, { waitUntil: 'networkidle' });
  const html = await page.content();
  s.check('contrôle positif : la fiche de Sophie est rendue', html.includes('Sophie Bernard'));
  s.check('ni tel: ni mailto: dans le HTML', !html.includes('tel:') && !html.includes('mailto:'));
  s.check('ni le numéro ni l’email dans le HTML', !html.includes('39 98 01 12') && !html.includes('sophie@demo.club'));
  await s.shot(page, 'trombi-fiche-ado-mobile');
  s.checkJs(page);
  tous.push(s.report());
  await ctx.close();
}

// ── T3 · Fiche de Lucie (P1) vue par Marie : « Parent : » avec ce que Florence a partagé ──
{
  const s = new Scenario('T3 · Contact du parent sur la fiche d’un enfant (mobile)');
  const { ctx, page } = await session(browser, 'marie@demo.club', MOBILE);
  const lucie = sql("SELECT id FROM users WHERE first_name='Lucie' AND last_name='Garnier'");
  await page.goto(`${BASE}/trombinoscope/${lucie}`, { waitUntil: 'networkidle' });
  const t = await texte(page);
  s.check('« Parent : Florence » affiché', t.includes('parent : florence'));
  s.check('coordonnées partagées par Florence', await page.locator('a[href^="tel:+33639980556"]:visible').count() === 1
    && await page.locator('a[href="mailto:florence@demo.club"]:visible').count() === 1);
  s.check('Lucie sans accord nom complet : initiale', t.includes('lucie g.') && !t.includes('garnier'));
  await s.shot(page, 'trombi-fiche-enfant-mobile');
  s.checkJs(page);
  tous.push(s.report());
  await ctx.close();
}

// ── T4 · Marie règle ses accords au profil : allumer puis éteindre la catégorie ──
for (const [format, viewport] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
  const s = new Scenario(`T4 · Onglet Trombi du profil (${format})`);
  const { ctx, page } = await session(browser, 'marie@demo.club', viewport);
  const repere = repereJournaux();
  const avant = sql('SELECT IFNULL(MAX(id),0) FROM consents');
  const marie = id('marie@demo.club');
  const actif = () => sql(`SELECT COUNT(*) FROM consents WHERE user_id=${marie} AND type='trombi_category' AND revoked_at IS NULL`);

  await page.goto(BASE + '/profil?tab=trombi', { waitUntil: 'networkidle' });
  const tout = page.locator('button.toggle:visible[aria-label="Tout partager"]');
  s.check('« Tout » en état mixte (trois accords sur six)', await tout.getAttribute('aria-pressed') === 'mixed');
  s.check('aperçu de la carte : nom complet consenti', (await texte(page)).includes('marie dupont'));
  await s.shot(page, `trombi-profil-${format}`);

  const cat = page.locator('button.toggle:visible[aria-label="Catégorie"]');
  s.check('contrôle : catégorie éteinte au départ', actif() === '0' && await cat.getAttribute('aria-pressed') === 'false');
  await cat.click();
  await page.waitForFunction(() => [...document.querySelectorAll('button.toggle[aria-label="Catégorie"]')].some(b => b.offsetParent && b.getAttribute('aria-pressed') === 'true'));
  s.check('allumée : une ligne active au registre', actif() === '1');
  await cat.click();
  await page.waitForFunction(() => [...document.querySelectorAll('button.toggle[aria-label="Catégorie"]')].some(b => b.offsetParent && b.getAttribute('aria-pressed') === 'false'));
  s.check('éteinte : la ligne est révoquée, pas effacée', actif() === '0'
    && sql(`SELECT COUNT(*) FROM consents WHERE id > ${avant} AND revoked_at IS NOT NULL`) === '1');
  s.checkJs(page);

  sql(`DELETE FROM consents WHERE id > ${avant} AND user_id=${marie}`);
  purgeJournaux(repere);
  tous.push(s.report());
  await ctx.close();
}

// ── T5 · Florence règle le trombinoscope de Lucie depuis « Mes enfants » ──
{
  const s = new Scenario('T5 · Le parent garant règle les accords de son enfant (mobile)');
  const { ctx, page } = await session(browser, 'florence@demo.club', MOBILE);
  const lucie = sql("SELECT id FROM users WHERE first_name='Lucie' AND last_name='Garnier'");
  await page.goto(`${BASE}/enfants/${lucie}/trombinoscope`, { waitUntil: 'networkidle' });
  s.check('photo et anniversaire accordés par le parent', await page.locator('button.toggle:visible[aria-label="Photo"]').getAttribute('aria-pressed') === 'true'
    && await page.locator('button.toggle:visible[aria-label="Anniversaire"]').getAttribute('aria-pressed') === 'true');
  s.check('ni email ni téléphone proposés pour un mineur', await page.locator('button.toggle[aria-label="Email"], button.toggle[aria-label="Téléphone"]').count() === 0);
  await s.shot(page, 'trombi-enfant-mobile');
  s.checkJs(page);
  tous.push(s.report());
  await ctx.close();
}

// ── T6 · Vignettes dans la liste d'inscrits, vue d'un coach (desktop) ──
{
  const s = new Scenario('T6 · Photos des inscrits sur la fiche séance (desktop)');
  const avecPhoto = `id IN (SELECT r.session_id FROM registrations r JOIN users u ON u.id=r.user_id
      JOIN consents c ON c.user_id=u.id AND c.type='trombi_photo' AND c.revoked_at IS NULL
      WHERE r.status='participating' AND u.photo_path IS NOT NULL AND u.athlete_access_suspended=0)`;
  // Toute séance à venir (pas seulement un entraînement) : la règle est la même sur les trois kinds.
  const cible = seance(`cancelled_at IS NULL AND start_at > NOW() AND ${avecPhoto}`);
  const { ctx, page } = await session(browser, 'vincent@demo.club', DESKTOP);
  await fiche(page, cible);
  const liste = page.locator('.reg-list:visible').first();
  await liste.scrollIntoViewIfNeeded();
  s.check('au moins une vignette chargée dans la liste', await imagesChargees(page, '.reg-list img'));
  s.check('contrôle : des inscrits sans photo gardent leurs initiales', await page.locator('.reg-list:visible .avatar:not(.has-photo)').count() > 0);
  await s.shot(page, 'trombi-inscrits-desktop');
  s.checkJs(page);
  tous.push(s.report());
  await ctx.close();
}

// ── T7 · Admin : onglet Trombinoscope de la fiche adhérent (desktop assumé) ──
{
  const s = new Scenario('T7 · Onglet Trombinoscope de la fiche adhérent (desktop)');
  const { ctx, page } = await session(browser, 'admin@demo.club', DESKTOP);
  // L'onglet ne se lit pas dans l'URL : on l'ouvre comme un admin, d'un clic.
  const onglet = async (userId) => {
    await page.goto(`${BASE}/admin/adherents/${userId}`, { waitUntil: 'networkidle' });
    await page.locator('button:visible', { hasText: /^\s*Trombinoscope\s*$/ }).click();
    await page.locator('select[aria-label="Fonction au bureau"]').waitFor();
    return page.locator('.mc-show-grid').innerText();
  };

  const timeo = sql("SELECT id FROM users WHERE first_name='Timéo' AND last_name='Vidal'");
  const t = (await onglet(timeo)).toLowerCase();
  s.check('accord enregistré sur bulletin, avec sa source', t.includes("bulletin d'adhésion (démo)") && t.includes('par admin demo'));
  s.check('mineur : ni email ni téléphone (contrôle : anniversaire présent)', t.includes('anniversaire') && !t.includes('email') && !t.includes('téléphone :'));
  await s.shot(page, 'trombi-admin-desktop');

  await onglet(id('sophie@demo.club'));
  const html = await page.content();
  s.check('fonction Trésorier·e sélectionnée', await page.locator('select[aria-label="Fonction au bureau"]').inputValue() === 'treasurer');
  s.check('l’admin voit que la photo et le numéro existent', (await page.locator('.mc-show-grid').innerText()).includes('Photo : déposée · Téléphone : saisi'));
  s.check('mais ne voit ni le numéro ni la photo', !html.includes('39 98 01 12') && !html.includes('trombi-photo'));
  s.checkJs(page);
  tous.push(s.report());
  await ctx.close();
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES SCÉNARIOS PASSENT' : '❌ AU MOINS UN SCÉNARIO ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
