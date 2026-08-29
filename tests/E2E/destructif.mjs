// ⚠️ SCÉNARIOS DESTRUCTIFS — modifient durablement le jeu de démo.
// Couvre PLAN_TESTS.md §8.4 (suppressions RGPD) et §6 (rupture de tutelle).
// Se termine par un RE-SEED automatique. À lancer explicitement, jamais en boucle.
import { execFileSync } from 'node:child_process';
import { launch, session, sql, barreMobile, Scenario, DESKTOP, MOBILE, BASE } from './lib.mjs';

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
    // Accusé de réception (§4.17) : le bouton n'est armé que la case cochée. Contrôle NÉGATIF
    // d'abord — « le bouton n'agit pas » ne vaudrait rien sans le contrôle positif qui suit.
    s.check('bouton non armé tant que la case n\'est pas cochée',
      await dlg.locator('button[wire\\:click="confirmSever"]').count() === 0);
    await dlg.locator('[wire\\:click="$toggle(\'severCheck\')"]').first().click();
    await page.waitForTimeout(600);
    s.check('la case cochée arme le bouton',
      await dlg.locator('button[wire\\:click="confirmSever"]').count() === 1);

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

// ── D3 · Bascule de saison (PRD §4.4, PLAN_TESTS §8.8) ───────────────
{
  const s = new Scenario('D3 · Suspension de masse + nouvelle année sportive');

  // Périmètre exact de l'opération (SeasonService::impactCounters) : TOUS les comptes athlète,
  // y compris déjà suspendus, et les inscriptions futures sur séances NON annulées — une séance
  // annulée n'a plus d'inscription à annuler.
  const FUTURES = "SELECT COUNT(*) n FROM registrations r JOIN sessions s ON s.id=r.session_id WHERE s.start_at>NOW() AND s.cancelled_at IS NULL AND r.status IN ('participating','waitlist')";
  const avantActifs   = +sql("SELECT COUNT(*) n FROM users WHERE JSON_CONTAINS(roles,'\"athlete\"') AND athlete_access_suspended=0");
  const avantAthletes = +sql("SELECT COUNT(*) n FROM users WHERE JSON_CONTAINS(roles,'\"athlete\"')");
  const avantCoachs   = +sql("SELECT COUNT(*) n FROM users WHERE JSON_CONTAINS(roles,'\"coach\"')");
  const avantFutures  = +sql(FUTURES);
  const avantPassees  = +sql("SELECT COUNT(*) n FROM registrations r JOIN sessions s ON s.id=r.session_id WHERE s.start_at<=NOW() AND r.status IN ('participating','waitlist')");
  s.check('prérequis : des athlètes actifs', avantActifs > 0, `${avantActifs} actifs`);
  s.check('prérequis : des inscriptions futures', avantFutures > 0, `${avantFutures} inscriptions`);

  const { ctx, page } = await session(browser, 'admin@demo.club', DESKTOP);
  await page.goto(`${BASE}/admin/parametres`, { waitUntil: 'networkidle' });

  const ouvrir = page.getByRole('button', { name: /basculer la saison/i }).first();
  s.check('action de bascule proposée à l\'admin', await ouvrir.isVisible().catch(() => false));
  await ouvrir.click();
  await page.waitForTimeout(1000);
  const dlg = page.locator('.dialog, [role="dialog"]').first();
  s.check('modale de bascule ouverte', await dlg.isVisible().catch(() => false));
  await s.shot(page, 'd3-1-modale');

  // L'impact est annoncé AVANT l'action (le PRD l'exige : pas de destruction à l'aveugle).
  const texteModale = (await dlg.innerText()).replace(/\s+/g, ' ');
  s.check('impact chiffré annoncé dans la modale',
          new RegExp(`${avantAthletes}\\s+comptes`).test(texteModale)
          && new RegExp(`${avantFutures}\\s+inscriptions`).test(texteModale), texteModale.slice(30, 140));

  // GARDE : tant que les deux confirmations ne sont pas données, le bouton est INERTE.
  // Il porte is-disabled ET n'a pas de wire:click (double garde vue + serveur), donc on
  // vérifie son état plutôt que de tenter un clic — Playwright refuserait de le cliquer.
  const valider = dlg.getByRole('button', { name: /basculer la saison/i }).last();
  const classesAvant = await valider.getAttribute('class') ?? '';
  const wireAvant = await valider.getAttribute('wire:click');
  s.check('GARDE : bouton désactivé sans double validation', classesAvant.includes('is-disabled'), classesAvant);
  s.check('GARDE : aucune action rattachée au bouton', wireAvant === null, String(wireAvant));
  s.check('GARDE : rien n\'est suspendu à ce stade',
          +sql("SELECT COUNT(*) n FROM users WHERE JSON_CONTAINS(roles,'\"athlete\"') AND athlete_access_suspended=0") === avantActifs);

  // Double validation : on clique les deux rangées de confirmation.
  const rangees = dlg.locator('div[wire\\:click^="$toggle"]');
  const nbRangees = await rangees.count();
  s.check('deux confirmations à cocher', nbRangees === 2, `${nbRangees} rangée(s)`);
  for (let i = 0; i < nbRangees; i++) { await rangees.nth(i).click(); await page.waitForTimeout(400); }
  await s.shot(page, 'd3-2-cochees');
  const classesApres = await valider.getAttribute('class') ?? '';
  s.check('bouton réactivé après double validation', !classesApres.includes('is-disabled'), classesApres);
  await valider.click();
  await page.waitForTimeout(3000);

  // Effets attendus.
  const apresActifs  = +sql("SELECT COUNT(*) n FROM users WHERE JSON_CONTAINS(roles,'\"athlete\"') AND athlete_access_suspended=0");
  const apresCoachs  = +sql("SELECT COUNT(*) n FROM users WHERE JSON_CONTAINS(roles,'\"coach\"')");
  const apresFutures = +sql(FUTURES);
  const apresPassees = +sql("SELECT COUNT(*) n FROM registrations r JOIN sessions s ON s.id=r.session_id WHERE s.start_at<=NOW() AND r.status IN ('participating','waitlist')");

  s.check('tous les athlètes sont suspendus', apresActifs === 0, `${apresActifs} restant(s) actif(s)`);
  s.check('les inscriptions futures (séances actives) sont annulées', apresFutures === 0, `${apresFutures} restante(s)`);
  // Les inscriptions sur séance ANNULÉE ne sont pas touchées : il n'y a rien à y annuler.
  s.check('les inscriptions sur séance annulée sont laissées telles quelles',
          +sql("SELECT COUNT(*) n FROM registrations r JOIN sessions s ON s.id=r.session_id WHERE s.start_at>NOW() AND s.cancelled_at IS NOT NULL AND r.status IN ('participating','waitlist')") > 0);
  s.check('les inscriptions PASSÉES sont préservées (historique)',
          apresPassees === avantPassees, `${apresPassees} (attendu ${avantPassees})`);
  // Les annulations passent par cancelAsSystem(), qui promeut la file d'attente. Tous les
  // athlètes étant suspendus en même temps, il ne reste personne à promouvoir : les files
  // futures doivent être vides, et non laissées avec des candidats fantômes.
  s.check('aucune liste d\'attente résiduelle sur séance active',
          +sql("SELECT COUNT(*) n FROM registrations r JOIN sessions s ON s.id=r.session_id WHERE s.start_at>NOW() AND s.cancelled_at IS NULL AND r.status='waitlist'") === 0);
  s.check('les annulations sont tracées comme annulations système',
          +sql("SELECT COUNT(*) n FROM registrations r JOIN sessions s ON s.id=r.session_id WHERE s.start_at>NOW() AND s.cancelled_at IS NULL AND r.status='cancelled'") > 0);
  s.check('les rôles coach sont conservés', apresCoachs === avantCoachs, `${apresCoachs} (attendu ${avantCoachs})`);
  s.check('AuditLog bulk_athlete_deactivation émis',
          sql("SELECT COUNT(*) n FROM audit_logs WHERE action='bulk_athlete_deactivation'") !== '0');
  await s.shot(page, 'd3-3-apres');

  // Un athlète suspendu ne peut plus s'inscrire : contrôle par l'UI, pas seulement en base.
  const { ctx: ctx2, page: page2 } = await session(browser, 'marie@demo.club', MOBILE);
  await page2.goto(`${BASE}/seances/9`, { waitUntil: 'networkidle' });
  const barre = await barreMobile(page2);
  s.check('un athlète suspendu voit le message de suspension',
          /suspendu/i.test(barre || ''), barre?.slice(0, 60));
  await ctx2.close();

  // Réactivation individuelle depuis la fiche adhérent (§8.8).
  const marieId = sql("SELECT id FROM users WHERE email='marie@demo.club'");
  await page.goto(`${BASE}/admin/adherents/${marieId}`, { waitUntil: 'networkidle' });
  const reactiver = page.getByRole('button', { name: /réactiver|rétablir/i }).first();
  if (await reactiver.isVisible().catch(() => false)) {
    await reactiver.click();
    await page.waitForTimeout(1200);
    const dlg2 = page.locator('.dialog, [role="dialog"]').first();
    if (await dlg2.isVisible().catch(() => false)) {
      const ok = dlg2.getByRole('button', { name: /réactiver|confirmer/i }).last();
      if (await ok.count()) { await ok.click(); await page.waitForTimeout(1500); }
    }
    s.check('réactivation individuelle effective',
            sql("SELECT athlete_access_suspended s FROM users WHERE email='marie@demo.club'") === '0');
  } else {
    s.check('bouton de réactivation trouvé sur la fiche adhérent', false, 'introuvable');
  }

  // Nouvelle année sportive : recalcul des catégories + purge des surclassements.
  const avantSurcl = +sql("SELECT COUNT(*) n FROM user_category WHERE is_primary=0");
  await page.goto(`${BASE}/admin/parametres`, { waitUntil: 'networkidle' });
  const nouvelle = page.getByRole('button', { name: /^démarrer/i }).first();
  if (await nouvelle.isVisible().catch(() => false)) {
    await nouvelle.click();
    await page.waitForTimeout(1000);
    const dlg3 = page.locator('.dialog, [role="dialog"]').first();
    const ok3 = dlg3.getByRole('button', { name: /démarrer|confirmer|nouvelle/i }).last();
    if (await ok3.count()) { await ok3.click(); await page.waitForTimeout(2500); }
    const apresSurcl = +sql("SELECT COUNT(*) n FROM user_category WHERE is_primary=0");
    s.check('surclassements réinitialisés', apresSurcl <= avantSurcl, `${avantSurcl} → ${apresSurcl}`);
    s.check('AuditLog season_rollover émis',
            sql("SELECT COUNT(*) n FROM audit_logs WHERE action='season_rollover'") !== '0');
    s.check('chaque athlète garde une catégorie principale',
            sql("SELECT COUNT(*) n FROM users u WHERE JSON_CONTAINS(u.roles,'\"athlete\"') AND u.dob IS NOT NULL AND NOT EXISTS (SELECT 1 FROM user_category uc WHERE uc.user_id=u.id AND uc.is_primary=1)") === '0');
    await s.shot(page, 'd3-4-nouvelle-annee');
  } else {
    s.check('action « nouvelle année sportive » trouvée', false, 'introuvable');
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
