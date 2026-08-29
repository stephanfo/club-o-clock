// Lanceur : enchaîne les suites NON destructives et agrège le verdict.
// Le script destructif (destructif.mjs) reste volontairement à part.
import { execFileSync } from 'node:child_process';
import { sql } from './lib.mjs';

const HERE = new URL('.', import.meta.url).pathname;
const suites = ['scenarios.mjs', 'parcours.mjs', 'comptes.mjs', 'responsive.mjs'];
const echecs = [];

/**
 * Empreinte de l'état du jeu de démo.
 *
 * Le harnais EXIGE de chaque scénario qu'il restaure ce qu'il modifie, mais rien ne le vérifiait :
 * une remise en état incomplète ne se voyait que des runs plus tard, quand un scénario partait d'un
 * jeu appauvri — et parfois jamais, l'assertion devenant simplement moins exigeante.
 *
 * Deux fuites réelles ont vécu ainsi : `fillQuota` (S17) promeut TOUTE la file quota d'un coup mais
 * la restauration ne portait que sur le premier promu — le jeu perdait une entrée de file à CHAQUE
 * run —, et les notifications de promotion restaient « en attente », donc bel et bien envoyables par
 * le prochain passage du cron, pour des promotions défaites depuis.
 *
 * D'où ce garde-fou : on photographie avant, on compare après, et un écart FAIT ÉCHOUER le run. Les
 * compteurs sont volontairement grossiers — ils ne disent pas quelle ligne a bougé, seulement qu'il
 * faut regarder ; c'est aux scénarios de porter les assertions fines.
 */
const empreinte = () => sql(`
  SELECT 'comptes', COUNT(*) FROM users
  UNION ALL SELECT 'comptes suspendus', COUNT(*) FROM users WHERE athlete_access_suspended=1
  UNION ALL SELECT 'comptes inactifs', COUNT(*) FROM users WHERE is_active=0
  UNION ALL SELECT 'tutelles', COUNT(*) FROM users WHERE guardian_id IS NOT NULL
  UNION ALL SELECT 'séances', COUNT(*) FROM sessions
  UNION ALL SELECT 'séances annulées', COUNT(*) FROM sessions WHERE cancelled_at IS NOT NULL
  UNION ALL SELECT 'encadrements', COUNT(*) FROM session_coach
  UNION ALL SELECT 'inscriptions', COUNT(*) FROM registrations
  UNION ALL SELECT 'inscrites participantes', COUNT(*) FROM registrations WHERE status='participating'
  UNION ALL SELECT 'file séance pleine', COUNT(*) FROM registrations WHERE status='waitlist' AND waitlist_reason='capacity'
  UNION ALL SELECT 'file quota dépassé', COUNT(*) FROM registrations WHERE status='waitlist' AND waitlist_reason='quota_exceeded'
  UNION ALL SELECT 'inscriptions annulées', COUNT(*) FROM registrations WHERE status='cancelled'
  UNION ALL SELECT 'promotions', COUNT(*) FROM registrations WHERE promoted_at IS NOT NULL
  UNION ALL SELECT 'apéros', COUNT(*) FROM apero_flags
  UNION ALL SELECT 'file d''envoi', COUNT(*) FROM notification_outbox
  UNION ALL SELECT 'envois en attente', COUNT(*) FROM notification_outbox WHERE status='pending'
  UNION ALL SELECT 'journal d''audit', COUNT(*) FROM audit_logs
  UNION ALL SELECT 'journal d''activité', COUNT(*) FROM activity_logs`);

const avant = empreinte();

for (const suite of suites) {
  console.log(`\n${'▄'.repeat(46)}\n▶ ${suite}\n${'▀'.repeat(46)}`);
  try {
    execFileSync('node', [HERE + suite], { stdio: 'inherit' });
  } catch {
    echecs.push(suite);
  }
}

// — Verdict d'intégrité du jeu de démo —
const apres = empreinte();
const derives = avant.split('\n')
  .map((ligne, i) => [ligne, apres.split('\n')[i]])
  .filter(([a, b]) => a !== b)
  .map(([a, b]) => `  ${a.split(' | ')[0]} : ${a.split(' | ')[1]} → ${b?.split(' | ')[1] ?? '?'}`);

console.log(`\n${'═'.repeat(46)}`);
if (derives.length === 0) {
  console.log('✅ JEU DE DÉMO INTACT — aucun scénario n\'a laissé de trace');
} else {
  console.log('❌ DÉRIVE DU JEU DE DÉMO — un scénario n\'a pas restauré ce qu\'il a modifié :');
  console.log(derives.join('\n'));
  console.log('\n  Le run suivant partirait d\'un jeu faussé. Repartir d\'une base propre :');
  console.log('  php artisan migrate:fresh && php artisan db:seed --class=CatalogSeeder \\');
  console.log('    && php artisan db:seed --class=DemoSeeder');
}

console.log(`\n${'═'.repeat(46)}`);
if (echecs.length) {
  console.log(`❌ ÉCHEC — ${echecs.join(', ')}`);
  process.exit(1);
}
if (derives.length) {
  process.exit(1);
}
console.log('✅ TOUTES LES SUITES E2E PASSENT');
