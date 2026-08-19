import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

export const BASE = 'http://127.0.0.1:8000';
const HERE = new URL('.', import.meta.url).pathname;
export const SHOTS = HERE + 'shots/';
export const MOBILE  = { width: 390, height: 844 };
export const DESKTOP = { width: 1440, height: 900 };

export const launch = () => chromium.launch();

/** Contexte authentifié via magic link (usage unique, TTL 15 min). */
export async function session(browser, email, viewport = MOBILE) {
  const url = execFileSync('php', [HERE + 'auth.php', email], { encoding: 'utf8' }).trim();
  const ctx = await browser.newContext({
    viewport, isMobile: viewport.width < 768, hasTouch: viewport.width < 768,
  });
  const page = await ctx.newPage();
  await page.goto(url, { waitUntil: 'networkidle' });
  return { ctx, page };
}

/** Va sur une fiche séance et attend le rendu Livewire. */
export async function fiche(page, id) {
  await page.goto(`${BASE}/seances/${id}`, { waitUntil: 'networkidle' });
}

/** Requête SQL de vérification (retour texte brut). */
export function sql(query) {
  return execFileSync('php', [HERE + 'sql.php', query], { encoding: 'utf8' }).trim();
}

/** Texte visible de la barre d'action mobile (ou '' si absente/vide). */
export async function barreMobile(page) {
  const el = page.locator('.fiche-actions-m').first();
  if (await el.count() === 0) return null;
  return (await el.innerText()).replace(/\s+/g, ' ').trim();
}

export class Scenario {
  constructor(nom) { this.nom = nom; this.r = []; }
  check(label, ok, detail = '') { this.r.push({ label, ok: !!ok, detail }); return !!ok; }
  async shot(page, nom) { await page.screenshot({ path: SHOTS + nom + '.png' }); }
  report() {
    const bad = this.r.filter(x => !x.ok);
    console.log(`\n━━━ ${this.nom} ━━━`);
    for (const x of this.r) console.log(`  ${x.ok ? '✅' : '❌'} ${x.label}${x.detail ? ' — ' + x.detail : ''}`);
    if (bad.length) console.log(`  ⚠️  ${bad.length} ÉCHEC(S)`);
    return bad.length === 0;
  }
}
