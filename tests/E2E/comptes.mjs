// Cycle de vie des comptes vu depuis le navigateur : messages d'authentification, correction de
// l'email de connexion, suspension individuelle de l'accès athlète.
//
// Ce que PHPUnit ne voit pas ici : que le message d'erreur de connexion arrive bien À L'ÉCRAN en
// français, que la carte « Email & connexion » bascule réellement en édition au clic, que la modale
// de conséquences s'ouvre et annonce le bon compteur, et que l'adhérent suspendu constate lui-même
// la perte de l'action d'inscription.

import { launch, session, sql, seanceFuture, BASE, DESKTOP, MOBILE, Scenario, repereJournaux, purgeJournaux } from './lib.mjs';

const browser = await launch();
const tous = [];

// ── S18 · Le refus de connexion parle français (§ langue du projet) ───
{
  const s = new Scenario('S18 · Message de connexion en français');
  const ctx = await browser.newContext({ viewport: DESKTOP });
  const page = await ctx.newPage();

  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });

  // L'écran rend les DEUX coquilles (mobile + desktop) : on se cantonne à celle qui est visible en
  // 1440px, sinon on remplirait les champs cachés de l'autre. Puis on bascule sur l'onglet mot de
  // passe — le lien magique est l'onglet par défaut.
  const dk = page.locator('.auth-dk');
  await dk.locator('label.seg-pwd').first().click();
  await page.waitForTimeout(300);
  await dk.locator('input[name="email"]:visible').first().fill('marie@demo.club');
  await dk.locator('input[name="password"]:visible').first().fill('ce-mot-de-passe-est-faux');
  await dk.locator('button[type="submit"]:visible').first().click();
  await page.waitForLoadState('networkidle');

  const txt = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  // Le symptôme corrigé : `lang/fr/` n'ayant que validation.php, Laravel rendait la CLÉ.
  s.check('la clé brute « auth.failed » n\'apparaît pas', !/auth\.failed/i.test(txt), txt.slice(0, 120));
  s.check('un message français est affiché', /identifiants ne correspondent/i.test(txt), txt.slice(0, 120));
  await s.shot(page, 's18-login-refus');

  await ctx.close();
  tous.push(s.report());
}

// ── S19 · Correction de l'email de connexion depuis la fiche (§4.1.3) ─
{
  const s = new Scenario('S19 · Correction de l\'email depuis la fiche adhérent');
  const journaux = repereJournaux();   // le changement d'email est tracé DEUX fois : aller et retour

  const cible = sql("SELECT id FROM users WHERE email IS NOT NULL AND is_minor=0 AND anonymized_at IS NULL AND NOT JSON_CONTAINS(roles,'\"admin\"') ORDER BY id LIMIT 1");
  const emailOrigine = sql(`SELECT email FROM users WHERE id=${cible}`);
  const corrige = 'correction.e2e@demo.club';

  const { ctx, page } = await session(browser, 'admin@demo.club', DESKTOP);
  await page.goto(`${BASE}/admin/adherents/${cible}`, { waitUntil: 'networkidle' });

  s.check('adresse affichée en lecture', (await page.locator('body').innerText()).includes(emailOrigine), emailOrigine);

  await page.click('button[aria-label="Modifier l\'email"]');
  await page.waitForTimeout(600);
  const champ = page.locator('input[wire\\:model="email"], input[wire\\:model\\.blur="email"]').first();
  s.check('le champ d\'édition apparaît', await champ.isVisible().catch(() => false));
  s.check('la conséquence est annoncée avant le clic',
    /révoque l'invitation en cours/i.test(await page.locator('body').innerText()));
  await s.shot(page, 's19-email-edition');

  await champ.fill(corrige);
  await page.locator('button[wire\\:click="saveEmail"]').first().click();
  await page.waitForTimeout(1200);

  const enBase = sql(`SELECT email FROM users WHERE id=${cible}`);
  s.check('adresse enregistrée en base', enBase === corrige, `${enBase} (attendu ${corrige})`);
  // La nouvelle adresse est marquée vérifiée, sinon le compte redeviendrait muet (§4.1.1).
  const verifie = sql(`SELECT IF(email_verified_at IS NULL,'non','oui') FROM users WHERE id=${cible}`);
  s.check('nouvelle adresse marquée vérifiée', verifie === 'oui', verifie);
  const jetons = sql(`SELECT COUNT(*) FROM invitation_tokens WHERE user_id=${cible} AND consumed_at IS NULL`);
  s.check('aucune invitation vivante ne survit au changement', jetons === '0', `n=${jetons}`);

  // — Contrôle négatif apparié : une adresse déjà prise est refusée —
  const occupe = sql(`SELECT email FROM users WHERE id<>${cible} AND email IS NOT NULL LIMIT 1`);
  await page.click('button[aria-label="Modifier l\'email"]');
  await page.waitForTimeout(500);
  await page.locator('input[wire\\:model="email"], input[wire\\:model\\.blur="email"]').first().fill(occupe);
  await page.locator('button[wire\\:click="saveEmail"]').first().click();
  await page.waitForTimeout(1000);
  s.check('doublon refusé, adresse inchangée', sql(`SELECT email FROM users WHERE id=${cible}`) === corrige);

  // Remise en état : adresse d'origine (statut + vérification sont reposés par le service).
  await page.locator('input[wire\\:model="email"], input[wire\\:model\\.blur="email"]').first().fill(emailOrigine);
  await page.locator('button[wire\\:click="saveEmail"]').first().click();
  await page.waitForTimeout(1000);
  purgeJournaux(journaux);
  s.check('état restauré', sql(`SELECT email FROM users WHERE id=${cible}`) === emailOrigine, emailOrigine);
  s.check('journaux restaurés (audit, activité, envois)',
          sql(`SELECT (SELECT COUNT(*) FROM audit_logs WHERE id>${journaux.audit})
                    + (SELECT COUNT(*) FROM activity_logs WHERE id>${journaux.activite})
                    + (SELECT COUNT(*) FROM notification_outbox WHERE id>${journaux.envois}) n`) === '0');

  await ctx.close();
  tous.push(s.report());
}

// ── S20 · Suspension individuelle de l'accès athlète (§4.4) ──────────
// Cible SANS inscription future à dessein : la suspension annule les inscriptions et fait remonter
// des tiers depuis la file — un effet que l'on ne saurait défaire proprement dans un jeu de démo
// partagé. C'est PHPUnit (SeasonTest) qui couvre l'annulation ; ici on couvre le parcours d'écran.
{
  const s = new Scenario('S20 · Suspension individuelle de l\'accès athlète');

  const cible = sql(`SELECT u.id FROM users u
      WHERE JSON_CONTAINS(u.roles,'"athlete"') AND u.is_active=1 AND u.anonymized_at IS NULL
        AND u.athlete_access_suspended=0 AND u.deletion_requested_at IS NULL AND u.email IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM registrations r JOIN sessions se ON se.id=r.session_id
                        WHERE r.user_id=u.id AND r.status IN ('participating','waitlist')
                          AND se.cancelled_at IS NULL AND se.start_at > NOW())
      ORDER BY u.id LIMIT 1`);
  const emailCible = sql(`SELECT email FROM users WHERE id=${cible}`);
  s.check(`prérequis : athlète actif sans inscription future (id ${cible})`, !!cible, emailCible);

  const { ctx, page } = await session(browser, 'admin@demo.club', DESKTOP);
  await page.goto(`${BASE}/admin/adherents/${cible}`, { waitUntil: 'networkidle' });

  const bouton = page.getByRole('button', { name: /Suspendre l'accès athlète/i }).first();
  s.check('action de suspension proposée', await bouton.isVisible().catch(() => false));
  await bouton.click();
  await page.waitForTimeout(700);

  const dlg = page.locator('.dialog, [role="dialog"]').first();
  const dlgVisible = await dlg.isVisible().catch(() => false);
  s.check('modale de conséquences ouverte (pas un confirm natif)', dlgVisible);
  if (dlgVisible) {
    const t = (await dlg.innerText()).replace(/\s+/g, ' ');
    s.check('le compteur d\'impact est annoncé', /0 inscription\(s\) future\(s\)/i.test(t), t.slice(0, 90));
    s.check('la réversibilité est annoncée', /réversible/i.test(t));
    await s.shot(page, 's20-suspension-dialog');
    await dlg.locator('input[wire\\:model="suspendMotif"], input[wire\\:model\\.blur="suspendMotif"]').first().fill('Licence non renouvelée (E2E)');

    // Accusé de réception (§4.17) : le bouton n'est armé que la case cochée. Contrôle NÉGATIF d'abord
    // — « le bouton n'agit pas » ne vaudrait rien sans le contrôle positif qui suit.
    s.check('bouton non armé tant que la case n\'est pas cochée',
      await dlg.locator('button[wire\\:click="suspendAccess"]').count() === 0);
    await dlg.locator('[wire\\:click="$toggle(\'suspendCheck\')"]').first().click();
    await page.waitForTimeout(600);
    s.check('la case cochée arme le bouton',
      await dlg.locator('button[wire\\:click="suspendAccess"]').count() === 1);

    await dlg.locator('button[wire\\:click="suspendAccess"]').first().click();
    await page.waitForTimeout(1200);
  }

  s.check('flag posé en base', sql(`SELECT athlete_access_suspended FROM users WHERE id=${cible}`) === '1');
  // Le compte reste OUVERT : suspendre n'est pas fermer (§4.3 est un autre geste).
  s.check('le compte reste actif', sql(`SELECT is_active FROM users WHERE id=${cible}`) === '1');
  const audit = sql(`SELECT COUNT(*) FROM audit_logs WHERE action='account_deactivated' AND target_id=${cible}`);
  s.check('AuditLog account_deactivated ciblé', Number(audit) >= 1, `n=${audit}`);
  const motif = sql(`SELECT motif FROM audit_logs WHERE action='account_deactivated' AND target_id=${cible} ORDER BY id DESC LIMIT 1`);
  s.check('le motif saisi est consigné', /Licence non renouvelée \(E2E\)/.test(motif), motif);
  s.check('l\'écran bascule vers la réactivation',
    /Réactiver l'accès athlète/i.test(await page.locator('body').innerText()));
  await s.shot(page, 's20-suspension-apres');

  // — L'adhérent suspendu le constate lui-même —
  {
    const seance = seanceFuture();
    const titre = sql(`SELECT title FROM sessions WHERE id=${seance}`);
    const { ctx: ctxA, page: pageA } = await session(browser, emailCible, MOBILE);
    await pageA.goto(`${BASE}/seances/${seance}`, { waitUntil: 'networkidle' });
    const txt = (await pageA.locator('body').innerText()).replace(/\s+/g, ' ');
    // Contrôle positif apparié à l'absence du bouton : la séance est bien rendue, c'est seulement
    // l'action qui manque. Sur une page vide ou en erreur, « pas de bouton » ne prouverait rien.
    // Comparaison insensible à la casse : le titre est rendu en capitales par le CSS
    // (text-transform), et innerText restitue la casse AFFICHÉE, pas celle de la base.
    s.check('l\'athlète suspendu voit toujours la séance',
      txt.toLowerCase().includes(titre.toLowerCase()), titre);
    s.check('aucune action d\'inscription ne lui est proposée', !/s'inscrire/i.test(txt), txt.slice(0, 120));
    await s.shot(pageA, 's20-athlete-suspendu');
    await ctxA.close();
  }

  // Remise en état : réactivation par l'écran, puis purge de l'email de réactivation mis en file
  // (il n'existe que parce que ce scénario est passé par là).
  await page.reload({ waitUntil: 'networkidle' });
  await page.locator('button[wire\\:click="reactivateAccess"]').first().click();
  await page.waitForTimeout(1200);
  s.check('état restauré (accès rendu)', sql(`SELECT athlete_access_suspended FROM users WHERE id=${cible}`) === '0');
  sql(`DELETE FROM notification_outbox WHERE user_id=${cible} AND type='athlete_reactivated'`);
  sql(`DELETE FROM audit_logs WHERE target_id=${cible} AND action IN ('account_deactivated','account_activated')`);
  s.check('état restauré (journal et file d\'envoi nettoyés)',
    sql(`SELECT COUNT(*) FROM audit_logs WHERE target_id=${cible} AND action='account_deactivated'`) === '0');

  await ctx.close();
  tous.push(s.report());
}

await browser.close();
const ok = tous.every(Boolean);
console.log(`\n${'═'.repeat(46)}\n${ok ? '✅ TOUS LES SCÉNARIOS COMPTES PASSENT' : '❌ AU MOINS UN SCÉNARIO COMPTES ÉCHOUE'}  (${tous.filter(Boolean).length}/${tous.length})\n`);
process.exit(ok ? 0 : 1);
