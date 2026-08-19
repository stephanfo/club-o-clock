// ⚠️ SCÉNARIOS DESTRUCTIFS — modifient durablement le jeu de démo.
// Couvre PLAN_TESTS.md §8.4 (suppressions RGPD) et §6 (rupture de tutelle).
// Se termine par un RE-SEED automatique. À lancer explicitement, jamais en boucle.
import { execFileSync } from 'node:child_process';
import { launch, session, sql, Scenario, DESKTOP, BASE } from './lib.mjs';

const PROJ = new URL('../../', import.meta.url).pathname;

if (!process.argv.includes('--oui-je-sais')) {
  console.error(`
⚠️  Ce script MODIFIE la base de démo (suppressions RGPD, rupture de tutelle)
    et la reconstruit ensuite via DemoSeeder.

    Relance avec :  node tests/E2E/destructif.mjs --oui-je-sais
`);
  process.exit(2);
}

const browser = await launch();
const tous = [];

// ── D1 · Garde RGPD : un garant de pupille P1 n'est pas supprimable ───
{
  const s = new Scenario('D1 · Suppression refusée pour un garant de P1 (PRD §4.3)');
  // Florence est garante de Lucie (P1) → la suppression doit être refusée.
  const p1 = sql("SELECT COUNT(*) n FROM users w JOIN users g ON g.id=w.guardian_id WHERE g.email='florence@demo.club' AND w.email IS NULL");
  s.check('prérequis : Florence garde une pupille sans compte (P1)', p1 !== '0', `n=${p1}`);

  const { ctx, page } = await session(browser, 'florence@demo.club', DESKTOP);
  await page.goto(`${BASE}/profil`, { waitUntil: 'networkidle' });
  // La zone danger vit dans l'onglet Connexion.
  const onglet = page.getByRole('button', { name: /connexion/i }).first();
  if (await onglet.count()) { await onglet.click(); await page.waitForTimeout(800); }
  const txt = await page.locator('body').innerText();
  s.check('la suppression est signalée impossible (pupille P1)',
          /pupille|enfant|garant|autonomis/i.test(txt), '');
  await s.shot(page, 'd1-garant-p1');
  await ctx.close();
  tous.push(s.report());
}

// ── D2 · Rupture de tutelle P2 (PLAN_TESTS §6) ───────────────────────
{
  const s = new Scenario('D2 · Sandrine rompt la tutelle de Noah (P2)');
  const avant = sql("SELECT guardian_id FROM users WHERE email='noah.faure@demo.club'");
  s.check('prérequis : Noah est sous tutelle', avant !== '' && avant !== 'NULL', `guardian_id=${avant}`);

  const { ctx, page } = await session(browser, 'sandrine@demo.club', DESKTOP);
  await page.goto(`${BASE}/enfants`, { waitUntil: 'networkidle' });
  const btn = page.getByRole('button', { name: /rompre la tutelle/i }).first();
  const dispo = await btn.isVisible().catch(() => false);
  s.check('bouton « Rompre la tutelle » proposé pour un P2', dispo);

  if (dispo) {
    await btn.click();
    await page.waitForTimeout(1200);
    const dlg = page.locator('.dialog, [role="dialog"]').first();
    s.check('dialog de confirmation (action notifiant des tiers)', await dlg.isVisible().catch(() => false));
    await s.shot(page, 'd2-rupture-dialog');
    const confirmer = dlg.getByRole('button', { name: /rompre|confirmer/i }).first();
    if (await confirmer.count()) { await confirmer.click(); await page.waitForTimeout(1800); }

    const apres = sql("SELECT IFNULL(guardian_id,'aucun') g FROM users WHERE email='noah.faure@demo.club'");
    s.check('tutelle effectivement rompue en base', apres === 'aucun', `guardian_id=${apres}`);
    const audit = sql("SELECT COUNT(*) n FROM audit_logs WHERE action='guardianship_severed'");
    s.check('AuditLog guardianship_severed émis', audit !== '0', `n=${audit}`);
  }
  await ctx.close();
  tous.push(s.report());
}

await browser.close();

// ── Remise en état obligatoire ───────────────────────────────────────
// Reconstruction COMPLÈTE, pas un simple re-seed : DemoSeeder crée les séances
// avec Session::create() sans garde d'unicité, donc un db:seed seul empile un jeu
// entier de doublons (mesuré : 74 → 147 séances).
console.log('\n♻️  Reconstruction du jeu de démo (migrate:fresh + seeders)…');
execFileSync('php', ['artisan', 'migrate:fresh', '--force'], { cwd: PROJ, stdio: 'inherit' });
execFileSync('php', ['artisan', 'db:seed', '--class=CatalogSeeder', '--force'], { cwd: PROJ, stdio: 'inherit' });
execFileSync('php', ['artisan', 'db:seed', '--class=DemoSeeder', '--force'], { cwd: PROJ, stdio: 'inherit' });
const noah = sql("SELECT IFNULL(guardian_id,'aucun') g FROM users WHERE email='noah.faure@demo.club'");
console.log(`   tutelle de Noah après re-seed : ${noah}`);

const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ SCÉNARIOS DESTRUCTIFS OK' : '❌ ÉCHEC'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
