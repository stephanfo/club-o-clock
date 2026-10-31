// Trombinoscope (#72, PRD §4.23) — grille et fiche selon le lecteur, accueil, listes d'inscrits,
// réglages du profil et de l'enfant, onglet admin.
//
// NON destructif : le seul accord posé (T4) est retiré dans le même scénario, et ses lignes du
// registre et du journal sont effacées par leur id.
import { execFileSync } from 'node:child_process';
import { statSync } from 'node:fs';
import { launch, session, fiche, sql, seance, repereJournaux, purgeJournaux, Scenario, MOBILE, DESKTOP, BASE, SHOTS } from './lib.mjs';

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
// Le seed place l'anniversaire de Camille dans la semaine OÙ IL A TOURNÉ : la semaine suivante, le
// bloc de l'accueil serait vide. On le pose donc aujourd'hui le temps de T1 (année 1992, bissextile :
// un 29 février reste une date valide), puis on rend sa date de naissance d'origine.
const dobCamille = sql("SELECT dob FROM users WHERE email='camille@demo.club'");
sql("UPDATE users SET dob = CONCAT('1992-', DATE_FORMAT(CURDATE(), '%m-%d')) WHERE email='camille@demo.club'");
try {
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
} finally {
  sql(`UPDATE users SET dob='${dobCamille}' WHERE email='camille@demo.club'`);
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
      WHERE r.status='participating' AND u.photo_path IS NOT NULL AND u.athlete_access_suspended=0)
    AND id IN (SELECT r.session_id FROM registrations r JOIN users u ON u.id=r.user_id
      WHERE r.status='participating' AND u.photo_path IS NULL)`; // et au moins un inscrit sans photo : contrôle des initiales
  // Toute séance à venir (pas seulement un entraînement) : la règle est la même sur les trois kinds.
  const cible = seance(`cancelled_at IS NULL AND start_at > NOW() AND ${avecPhoto}`);
  const { ctx, page } = await session(browser, 'vincent@demo.club', DESKTOP);
  await fiche(page, cible);
  const liste = page.locator('.reg-list:visible').first();
  await liste.scrollIntoViewIfNeeded();
  s.check('au moins une vignette chargée dans la liste', await imagesChargees(page, '.reg-list img'));
  s.check('contrôle : des inscrits sans photo gardent leurs initiales', await page.locator('.reg-list:visible .avatar:not(.has-photo)').count() > 0);
  await s.shot(page, 'trombi-inscrits-desktop');

  // Un inscrit mène à sa fiche du trombinoscope, et le retour ramène à la séance.
  const lien = page.locator('.reg-list:visible a.reg-link').first();
  // Le lien porte aussi l'avatar : ses initiales précèdent le nom (#148).
  const nom = (await lien.innerText()).trim().split('\n').pop().trim();
  const href = await lien.getAttribute('href');
  s.check('les inscrits sont des liens vers leur fiche', await page.locator('.reg-list:visible a.reg-link').count() > 0);
  await lien.click();
  await page.waitForURL(/\/trombinoscope\/\d+$/);
  await attendre(page);
  s.check('la fiche ouverte est celle de l’inscrit', page.url() === href && (await texte(page)).includes(nom.toLowerCase()), `${nom} → ${page.url()}`);
  await page.locator('[onclick*="clubBack"]:visible').first().click();
  await page.waitForURL(new RegExp(`/seances/${cible}`));
  s.check('le retour ramène à la séance', page.url().includes(`/seances/${cible}`), page.url());
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
  // Le numéro reste invisible de l'admin ; la photo partagée, elle, s'affiche pour la modérer (#149).
  s.check('mais ne voit pas le numéro', !html.includes('39 98 01 12'));
  s.check('la photo partagée s’affiche pour la modérer', html.includes('trombi-photo'));
  s.checkJs(page);
  tous.push(s.report());
  await ctx.close();
}

// ── T8 · Recadrage au dépôt : Lucas garde la moitié ROUGE d'une photo rouge | bleu de PLUS de 5 Mo ──
// Fabriquée par GD : paysage 3000×1500 bruité (incompressible, comme une photo de téléphone), rouge à
// gauche, bleu à droite. Sans toucher, le recadrage automatique la centrerait sur la frontière ; on
// glisse vers la droite pour ne garder que le rouge, et c'est la vignette STOCKÉE (relue par le
// navigateur) qui doit être rouge. Le poids prouve que la limite de 5 Mo ne bloque plus : le
// navigateur n'envoie que le carré cadré, réduit.
const fichier = `${SHOTS}rouge-bleu.png`;
execFileSync('php', ['-r', `$w=3000;$h=1500;$i=imagecreatetruecolor($w,$h);$r=fn($v)=>max(0,min(255,$v+mt_rand(-40,40)));for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++){[$a,$b,$c]=$x<$w/2?[200,30,30]:[30,60,200];imagesetpixel($i,$x,$y,($r($a)<<16)|($r($b)<<8)|$r($c));}imagepng($i,'${fichier}',9);`]);
for (const [format, viewport] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
  const s = new Scenario(`T8 · Recadrer sa photo au dépôt (${format})`);
  s.check('contrôle : la photo de départ dépasse 5 Mo', statSync(fichier).size > 5 * 1024 * 1024, `${(statSync(fichier).size / 1048576).toFixed(1)} Mo`);
  const lucas = id('lucas@demo.club');
  const repere = repereJournaux();
  s.check('contrôle : Lucas sans photo au départ', sql(`SELECT IFNULL(photo_path,'') FROM users WHERE id=${lucas}`) === '');

  const { ctx, page } = await session(browser, 'lucas@demo.club', viewport);
  page.on('dialog', d => d.accept()); // wire:confirm de la suppression finale
  await page.goto(BASE + '/profil?tab=trombi', { waitUntil: 'networkidle' });
  await page.locator('input[type=file]:visible, label:visible input[type=file]').first().setInputFiles(fichier);
  const stage = page.locator('.crop-stage:visible');
  await stage.waitFor();
  await page.waitForFunction(() => [...document.querySelectorAll('.crop-img')].some(i => i.offsetParent && i.complete && i.naturalWidth));
  s.check('le dialog s’ouvre, rien n’est encore envoyé', sql(`SELECT IFNULL(photo_path,'') FROM users WHERE id=${lucas}`) === '');
  await s.shot(page, `trombi-recadrage-${format}`);

  const box = await stage.boundingBox();
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width * 1.5, box.y + box.height / 2, { steps: 8 });
  await page.mouse.up();
  // Poids réellement envoyé : Playwright ne mesure pas un corps multipart, on le lit dans la page
  // (Livewire envoie les fichiers par XMLHttpRequest).
  await page.evaluate(() => {
    window.__envoye = 0;
    const send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (body) {
      if (body instanceof FormData) for (const v of body.values()) if (v instanceof Blob) window.__envoye += v.size;
      return send.call(this, body);
    };
  });
  await page.getByRole('button', { name: 'Valider' }).click();
  await page.waitForFunction(() => !document.querySelector('.crop-stage'), null, { timeout: 15000 });
  await attendre(page);
  const path = sql(`SELECT IFNULL(photo_path,'') FROM users WHERE id=${lucas}`);
  s.check('photo enregistrée après « Valider »', path !== '');
  const envoye = await page.evaluate(() => window.__envoye);
  s.check('seul le carré réduit est parti (< 1 Mo)', envoye > 0 && envoye < 1024 * 1024, `${Math.round(envoye / 1024)} Ko`);

  // Couleur du centre de la vignette 192 servie par l'app.
  const rgb = await page.evaluate(async (url) => {
    const blob = await (await fetch(url)).blob();
    const bmp = await createImageBitmap(blob);
    const c = new OffscreenCanvas(bmp.width, bmp.height);
    const g = c.getContext('2d');
    g.drawImage(bmp, 0, 0);
    return [...g.getImageData(bmp.width / 2, bmp.height / 2, 1, 1).data].slice(0, 3);
  }, `${BASE}/trombi-photo/${lucas}/192`);
  s.check('la vignette est la partie choisie (rouge)', rgb[0] > 150 && rgb[2] < 100, rgb.join(','));
  s.checkJs(page);

  // Restauration : la photo repart par l'interface, comme Lucas le ferait.
  await page.locator('button:visible', { hasText: /Supprimer/ }).first().click();
  await attendre(page);
  s.check('restauré : Lucas sans photo', sql(`SELECT IFNULL(photo_path,'') FROM users WHERE id=${lucas}`) === '');
  purgeJournaux(repere);
  tous.push(s.report());
  await ctx.close();
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES SCÉNARIOS PASSENT' : '❌ AU MOINS UN SCÉNARIO ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
