// Lanceur : enchaîne les suites NON destructives et agrège le verdict.
// Le script destructif (destructif.mjs) reste volontairement à part.
import { execFileSync } from 'node:child_process';

const HERE = new URL('.', import.meta.url).pathname;
const suites = ['scenarios.mjs', 'parcours.mjs', 'comptes.mjs', 'responsive.mjs'];
const echecs = [];

for (const suite of suites) {
  console.log(`\n${'▄'.repeat(46)}\n▶ ${suite}\n${'▀'.repeat(46)}`);
  try {
    execFileSync('node', [HERE + suite], { stdio: 'inherit' });
  } catch {
    echecs.push(suite);
  }
}

console.log(`\n${'═'.repeat(46)}`);
if (echecs.length) {
  console.log(`❌ ÉCHEC — ${echecs.join(', ')}`);
  process.exit(1);
}
console.log('✅ TOUTES LES SUITES E2E PASSENT');
