// Allures course (#114) — écran du membre et écrans admin, aux deux formats.
//
// NON destructif : la VMA de Marie est remise à sa valeur de démo, celle de Lucas effacée.
import { launch, session, sql, Scenario, MOBILE, DESKTOP, BASE } from './lib.mjs';

const browser = await launch();
const tous = [];
const attendre = (page) => page.waitForLoadState('networkidle');

// ── A1 · Marie (VMA renseignée) : allures par zone, estimation sans enregistrer, deux formats ──
for (const [format, viewport] of [['mobile', MOBILE], ['desktop', DESKTOP]]) {
  const s = new Scenario(`A1 · Marie consulte ses allures (${format})`);
  const { ctx, page } = await session(browser, 'marie@demo.club', viewport);

  await page.goto(BASE + '/profil', { waitUntil: 'networkidle' });
  const carte = page.locator('a[href$="/allures"]:visible').first();
  s.check('carte « Mes allures course » sur le profil, avec la VMA', (await carte.innerText()).includes('VMA 13,5'));
  await carte.click();
  await page.waitForURL('**/allures');
  await attendre(page);

  const corps = (await page.locator('body').innerText()).toLowerCase();
  s.check('VMA affichée avec son origine', corps.includes('estimée depuis un 10 km'));
  s.check('tableau des zones rendu (grille générique)', await page.locator('table.tbl tbody tr').count() === 5);
  s.check('projection affichée, modèle de Riegel annoncé', corps.includes('projection de temps') && corps.includes('riegel'));

  // Le curseur de % recalcule côté navigateur.
  const avant = await page.locator('#al-pct').locator('..').innerText();
  await page.locator('#al-pct').fill('100');
  const apres = await page.locator('#al-pct').locator('..').innerText();
  s.check('curseur : 100 % de 13,5 km/h = 4:27 /km', apres.includes('4:27') && apres !== avant);

  await page.locator('#al-time').fill('47:45');
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

  const premier = await page.locator('.eyebrow:visible').first().innerText();
  s.check('sans VMA, l’estimation passe en premier', /estimer/i.test(premier), premier);
  s.check('pas de tableau des zones sans VMA', await page.locator('table.tbl').count() === 0);

  await page.getByRole('button', { name: 'Semi' }).click();
  await attendre(page);
  await page.locator('#al-time').fill('1:49:00');
  const btn = page.getByRole('button', { name: /^Utiliser/ });
  await btn.waitFor();
  await btn.click();
  await page.waitForFunction(() => document.querySelector('table.tbl'));

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
  s.check('table vide : Riegel annoncé', (await page.locator('body').innerText()).includes('le modèle de Riegel s'));
  await page.getByRole('button', { name: /Ajouter un niveau/ }).click();
  await page.locator('table.tbl tbody tr').first().waitFor({ timeout: 5000 }).catch(() => {});
  s.check('une ligne de niveau ajoutée (non enregistrée)', await page.locator('table.tbl tbody tr').count() === 1);
  await s.shot(page, 'allures-admin-cibles');
  s.check('rien d’enregistré', sql('SELECT COUNT(*) FROM allure_levels') === '0');

  await page.goto(BASE + '/admin/parametres', { waitUntil: 'networkidle' });
  const hub = await page.locator('body').innerText();
  s.check('hub des paramètres : zones et allures cibles', hub.includes('Zones d’allure course') && hub.includes('Allures cibles'));
  s.checkJs(page);
  tous.push(s.report());
  await ctx.close();
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES SCÉNARIOS PASSENT' : '❌ AU MOINS UN SCÉNARIO ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
