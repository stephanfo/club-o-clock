import { defineConfig } from 'vitepress'

// Configuration du site de documentation.
//
// Le parti pris central : les fichiers Markdown ne bougent pas. Ils restent dans `doc/` et à la
// racine du dépôt, là où GitHub les lit déjà, et c'est VitePress qui vient les chercher via
// `srcDir` + `rewrites`. Renommer `doc/` en `docs/` (ce qu'attendent la plupart des générateurs)
// aurait cassé les quarante liens relatifs qui traversent le corpus.
export default defineConfig({
  title: "Club'O'Clock",
  description: 'Le planning d’entraînement de ton club, sans le tableur partagé.',
  lang: 'fr-FR',

  // Pas de `base` : le site est monté à la racine du domaine, et c'est l'arborescence du dépôt
  // (conservée, cf. l'absence de `rewrites` plus bas) qui place naturellement la documentation
  // sous /doc/ — doc/PRD.md donne /doc/PRD.
  //
  // La vitrine `site/index.html` occupe `/`, et remplace au montage l'index que VitePress
  // génère pour la racine (cf. site/build-local.sh).

  // La racine du dépôt : c'est ce qui permet d'agréger `doc/` ET les fichiers de la racine
  // (CONTRIBUTING, SECURITY, CHANGELOG) dans un seul site sans en déplacer aucun.
  srcDir: '..',

  // `srcDir` pointant hors du dossier de config, ces deux chemins doivent être explicites :
  // sinon VitePress écrirait à la racine du dépôt. Ils sont relatifs à `site/`, pas à ce fichier.
  outDir: './.vitepress/dist',
  cacheDir: './.vitepress/.cache',

  srcExclude: [
    'CLAUDE.md',                          // instructions d'assistant, sans intérêt public
    'doc/J10_BIBLIOTHEQUE_PARCOURS.md',   // spec d'un jalon déjà livré, orpheline

    // Le README reste la vitrine du dépôt GitHub ; celle du site est `site/index.html`.
    // L'inclure ici créerait deux pages d'accueil concurrentes, avec deux contenus à tenir à jour.
    'README.md',

    'vendor/**',
    'node_modules/**',
    'storage/**',
    'site/**',
    'tests/**',
    'database/**',
    'public/**',                          // OFL-ATTRIBUTION.md n'est pas une page de doc
    '.github/**',
  ],

  // Pas de `rewrites` : les pages conservent l'arborescence du dépôt. Aplatir `doc/` vers la
  // racine du site (`'doc/:page': ':page'`) donnait des URL plus courtes, mais cassait les
  // chemins d'images relatifs de CAPTURES.md — or ces chemins doivent rester relatifs pour que
  // les captures s'affichent aussi sur GitHub, où un `src` absolu viserait la racine du domaine.
  //
  // Avec `base: '/doc/'`, la géométrie obtenue est donc :
  //   doc/PRD.md       → /doc/doc/PRD        (réécrit en /doc/PRD par le montage, cf. build-local.sh)
  //   CONTRIBUTING.md  → /doc/CONTRIBUTING

  // L'application n'a pas de mode sombre (aucun `prefers-color-scheme` dans les tokens) :
  // en proposer un ici créerait une incohérence avec les captures et la démo.
  appearance: false,

  lastUpdated: true,

  // Volontairement laissé à `false` (le défaut) : un lien mort fait échouer le build. C'est le
  // filet qui remplace la relecture manuelle des quarante liens croisés.
  // ignoreDeadLinks: false,

  themeConfig: {
    search: { provider: 'local' },
    outline: { level: [2, 3], label: 'Sur cette page' },
  },
})
