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

/**
 * Id de LA séance qui satisfait `where`, la plus proche dans le temps. Lève si aucune ne convient.
 *
 * Les séances du jeu de démo sont placées relativement à `now()` (DemoSeeder), mais leur position
 * par rapport à l'instant courant dépend du JOUR et de l'HEURE du run : la natation du mercredi
 * 18h15 est future si l'on seede le lundi, déjà commencée si l'on lance la suite le mercredi soir —
 * y compris juste après un `db:seed`. Les ids, eux, dépendent de l'ordre d'insertion. Coder un id en
 * dur rend donc le scénario vert ou rouge selon le moment du run, et c'est ainsi que S8/S10/S12
 * pointaient des séances qui ne correspondaient plus à leur intention.
 *
 * Règle du harnais : on sélectionne une séance par ses PROPRIÉTÉS, jamais par son id.
 */
export function seance(where, ordre = 'start_at') {
  const id = sql(`SELECT id FROM sessions WHERE ${where} ORDER BY ${ordre} LIMIT 1`);
  if (!id) throw new Error(`Aucune séance ne satisfait : ${where}`);
  return Number(id);
}

/** Séance d'entraînement future, non annulée, satisfaisant `where`. */
export function seanceFuture(where = '1=1') {
  return seance(`kind='training' AND cancelled_at IS NULL AND start_at > NOW() AND (${where})`);
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
