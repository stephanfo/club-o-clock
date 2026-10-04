// Allures course (#114, #113) — écran du membre, écrans admin, zones dans les consignes.
//
// NON destructif : la VMA de Marie est remise à sa valeur de démo, celle de Lucas effacée.
import { launch, session, fiche, sql, seanceFuture, Scenario, MOBILE, DESKTOP, BASE } from './lib.mjs';

const browser = await launch();
const tous = [];
const attendre = (page) => page.waitForLoadState('networkidle');

// ── A1 · Marie (VMA renseignée) : allures par zone, estimation sans enregistrer, deux formats ──
for (const [format, viewport] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
  const s = new Scenario(`A1 · Marie consulte ses allures (${format})`);
  const { ctx, page } = await session(browser, 'marie@demo.club', viewport);

  // Onglet « Allures » du profil (une instance du composant par coquille : filtrer :visible).
  await page.goto(BASE + '/profil', { waitUntil: 'networkidle' });
  await page.locator('button:visible', { hasText: /^\s*Allures\s*$/ }).first().click();
  await page.locator('#al-vma:visible').waitFor();
  await attendre(page);
  s.check('onglet Allures du profil, avec la VMA', (await page.locator('#al-vma:visible').inputValue()) === '13,5');

  const corps = (await page.locator('body').innerText()).toLowerCase();
  s.check('VMA affichée avec son origine', corps.includes('estimée depuis un 10 km'));
  s.check('tableau des zones rendu (grille générique)', await page.locator('table.tbl:visible tbody tr').count() === 5);
  s.check('distances de la piste au marathon', (await page.locator('table.tbl:visible thead').innerText()).toLowerCase().includes('marathon'));
  s.check('bande de zones colorée sous le curseur', await page.locator('.zone-strip:visible > span[title]').count() === 5);
  s.check('projection affichée, modèle de Riegel annoncé', corps.includes('projection de temps') && corps.includes('riegel'));

  // Le curseur de % recalcule côté navigateur.
  const avant = await page.locator('#al-pct:visible').locator('..').innerText();
  await page.locator('#al-pct:visible').fill('100');
  const apres = await page.locator('#al-pct:visible').locator('..').innerText();
  s.check('curseur : 100 % de 13,5 km/h = 4:27 /km', apres.includes('4:27') && apres !== avant);

  await page.locator('#al-time:visible').fill('47:45');
  await page.waitForFunction(() => document.body.innerText.toLowerCase().includes('utiliser'));
  s.check('estimation affichée depuis un 10 km en 47:45', (await page.locator('body').innerText()).includes('14,1'));
  s.check('rien d’enregistré tant qu’on ne clique pas', sql("SELECT value FROM reference_values rv JOIN users u ON u.id=rv.user_id WHERE u.email='marie@demo.club'") === '13.5');

  await s.shot(page, `allures-marie-${format}`);
  s.checkJs(page);
  tous.push(s.report());
  await ctx.close();
}

// ── A2 · Lucas (sans VMA) : estimation en tête, enregistrement, puis restauration ──
{
  const s = new Scenario('A2 · Lucas estime puis enregistre sa VMA (mobile)');
  const { ctx, page } = await session(browser, 'lucas@demo.club', MOBILE);
  await page.goto(BASE + '/allures', { waitUntil: 'networkidle' });
  s.check('l’ancienne adresse mène à l’onglet du profil', page.url().includes('tab=allures'), page.url());

  // Le bloc « Mon niveau » (s'il y a plusieurs niveaux) précède l'estimation, qu'il règle.
  const titres = await page.locator('.eyebrow:visible').allInnerTexts();
  const premier = titres.find(t => !/mon niveau/i.test(t)) ?? '';
  s.check('sans VMA, l’estimation passe en premier', /estimer/i.test(premier), premier);
  s.check('pas de tableau des zones sans VMA', await page.locator('table.tbl').count() === 0);
  s.check('contrôle positif : l’écran est bien rendu', await page.locator('#al-vma:visible').count() === 1);

  await page.getByRole('button', { name: 'Semi' }).click();
  await attendre(page);
  await page.locator('#al-time:visible').fill('1:49:00');
  const btn = page.getByRole('button', { name: /^Utiliser/ });
  await btn.waitFor();
  await btn.click();
  await page.locator('table.tbl:visible').waitFor();

  const enBase = sql("SELECT CONCAT(value,'|',source,'|',source_distance) FROM reference_values rv JOIN users u ON u.id=rv.user_id WHERE u.email='lucas@demo.club'");
  s.check('VMA estimée enregistrée, distance sans le temps', enBase === '13.7|estimation|semi', enBase);
  await s.shot(page, 'allures-lucas-mobile');
  s.checkJs(page);

  sql("DELETE rv FROM reference_values rv JOIN users u ON u.id=rv.user_id WHERE u.email='lucas@demo.club'");
  tous.push(s.report());
  await ctx.close();
}

// ── A3 · Admin : catalogue des zones et table club (desktop assumé) ──
{
  const s = new Scenario('A3 · Admin — zones d’allure et allures cibles (desktop)');
  const { ctx, page } = await session(browser, 'admin@demo.club', DESKTOP);

  await page.goto(BASE + '/admin/catalogues/allure_zone', { waitUntil: 'networkidle' });
  s.check('les 5 zones génériques listées', (await page.locator('body').innerText()).includes('95–105 %'));
  await s.shot(page, 'allures-admin-zones');

  await page.goto(BASE + '/admin/allures-cibles', { waitUntil: 'networkidle' });
  const niveaux = page.locator('.niveau-grille');
  s.check('Riegel affiché comme niveau calculé', await niveaux.count() === 1
    && (await page.locator('.cat-wrap').innerText()).includes('Modèle de Riegel'));
  await page.getByRole('button', { name: /Ajouter un niveau/ }).click();
  await niveaux.nth(1).waitFor({ timeout: 5000 }).catch(() => {});
  s.check('un niveau ajouté sous Riegel (non enregistré)', await niveaux.count() === 2);
  await page.getByLabel('% minimum 5 km').fill('85,5');
  s.check('saisie lisible : le champ n’est pas tronqué', await page.getByLabel('% minimum 5 km').evaluate(e => e.scrollWidth <= e.clientWidth));
  await s.shot(page, 'allures-admin-cibles');
  s.check('rien d’enregistré : seule la ligne Riegel du seed en base', sql("SELECT GROUP_CONCAT(model) FROM allure_levels") === 'riegel');

  await page.goto(BASE + '/admin/parametres', { waitUntil: 'networkidle' });
  const hub = await page.locator('body').innerText();
  s.check('bloc « Allures course » des paramètres : zones et allures cibles', hub.includes('Zones d’allure') && hub.includes('Allures cibles'));
  s.checkJs(page);
  tous.push(s.report());
  await ctx.close();
}

// ── A4 · #113 · Consigne en zones : allure de Marie à côté du code, invitation pour Lucas ──
{
  const cible = seanceFuture(`kind='training' AND content_markdown LIKE '%2 blocs de 4x%'
      AND discipline_id IN (SELECT id FROM disciplines WHERE referentiel='course')`);
  for (const [format, viewport] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
    const s = new Scenario(`A4 · Consigne en zones, séance ${cible} (${format})`);
    const { ctx, page } = await session(browser, 'marie@demo.club', viewport);
    await fiche(page, cible);
    const prose = page.locator('.db-prose:visible').first();
    await prose.scrollIntoViewIfNeeded();
    // VMA 13,5 : Z4 (90–95 %) = 4:41–4:56 /km.
    s.check('allure de Marie à côté de Z4', (await prose.innerText()).includes('Z4 [4:41–4:56 /km]'));
    s.check('pas d’invitation pour qui a une VMA', await page.getByText('Renseigne ta VMA').count() === 0);
    await s.shot(page, `allures-consigne-marie-${format}`);
    s.checkJs(page);
    tous.push(s.report());
    await ctx.close();
  }
  {
    const s = new Scenario(`A4 · Consigne en zones, sans VMA (Lucas, mobile)`);
    const { ctx, page } = await session(browser, 'lucas@demo.club', MOBILE);
    await fiche(page, cible);
    const prose = page.locator('.db-prose:visible').first();
    s.check('texte brut conservé', (await prose.innerText()).includes("2' Z4 / 2' Z3"));
    s.check('aucune allure ajoutée', await page.locator('.zone-allure').count() === 0);
    const lien = page.locator('a[href*="tab=allures"]:visible', { hasText: 'Renseigne ta VMA' });
    s.check('invitation à renseigner sa VMA, vers l’onglet du profil', await lien.count() === 1);
    await lien.scrollIntoViewIfNeeded();
    await s.shot(page, 'allures-consigne-lucas-mobile');
    s.checkJs(page);
    tous.push(s.report());
    await ctx.close();
  }
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES SCÉNARIOS PASSENT' : '❌ AU MOINS UN SCÉNARIO ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
