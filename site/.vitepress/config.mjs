import { fileURLToPath } from 'node:url'
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
  // La géométrie obtenue est donc celle du dépôt :
  //   doc/PRD.md       → /doc/PRD
  //   CONTRIBUTING.md  → /CONTRIBUTING

  // L'application n'a pas de mode sombre (aucun `prefers-color-scheme` dans les tokens) :
  // en proposer un ici créerait une incohérence avec les captures et la démo.
  appearance: false,

  lastUpdated: true,

  // Volontairement laissé à `false` (le défaut) : un lien mort fait échouer le build. C'est le
  // filet qui remplace la relecture manuelle des quarante liens croisés.
  // ignoreDeadLinks: false,

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/assets/favicon.svg' }],
    ['meta', { name: 'theme-color', content: '#4338CA' }],
    ['link', { rel: 'preload', as: 'font', type: 'font/woff2', crossorigin: '',
               href: '/assets/manrope-latin-variable.woff2' }],
  ],

  vite: {
    // `srcDir` pointant sur la racine du dépôt, VitePress y chercherait son dossier `public` —
    // c'est-à-dire celui de Laravel, qui n'a rien à faire dans le site. On le désigne donc
    // explicitement. Ce qu'il contient est servi tel quel, sans hachage : c'est ce qui permet
    // au `@font-face` de pointer une URL stable, /assets/manrope-latin-variable.woff2.
    publicDir: fileURLToPath(new URL('../public', import.meta.url)),
  },

  themeConfig: {
    logo: '/assets/favicon.svg',
    siteTitle: "Club'O'Clock",

    search: {
      provider: 'local',
      options: {
        translations: {
          button: { buttonText: 'Rechercher', buttonAriaLabel: 'Rechercher' },
          modal: {
            displayDetails: 'Afficher les détails',
            resetButtonTitle: 'Réinitialiser',
            backButtonTitle: 'Fermer',
            noResultsText: 'Aucun résultat pour',
            footer: {
              selectText: 'pour sélectionner',
              navigateText: 'pour naviguer',
              closeText: 'pour fermer',
            },
          },
        },
      },
    },

    // Niveaux 2-3 seulement : le PRD porte 86 titres de niveau 4, qui rendraient la colonne
    // de droite illisible. En [2,3] on obtient une trentaine d'entrées, et chaque sous-section
    // 4.x reste atteignable en un clic.
    outline: { level: [2, 3], label: 'Sur cette page' },

    nav: [
      { text: 'Accueil', link: '/' },
      { text: 'Documentation', link: '/doc/' },
      { text: 'Démo', link: 'https://demo.cluboclock.ratelet.fr/' },
      { text: 'GitHub', link: 'https://github.com/stephanfo/club-o-clock' },
    ],

    // Groupée par intention de lecture, pas par emplacement des fichiers : « j'installe »,
    // « je comprends », « je contribue ».
    sidebar: [
      {
        text: 'Démarrer',
        items: [
          { text: 'Vue d’ensemble', link: '/doc/' },
          { text: 'Installation & déploiement', link: '/doc/INSTALL' },
          { text: 'Comptes de démonstration', link: '/doc/COMPTES_DEMO' },
          { text: 'L’application en images', link: '/doc/CAPTURES' },
        ],
      },
      {
        text: 'Comprendre',
        items: [
          { text: 'Spécification produit (PRD)', link: '/doc/PRD' },
          { text: 'Cadrage technique', link: '/doc/CADRAGE_TECHNIQUE' },
        ],
      },
      {
        text: 'Recetter',
        items: [
          { text: 'Plan de tests — membres', link: '/doc/PLAN_TESTS_MEMBRES' },
          { text: 'Plan de tests — technique', link: '/doc/PLAN_TESTS' },
        ],
      },
      {
        text: 'Contribuer',
        items: [
          { text: 'Guide de contribution', link: '/CONTRIBUTING' },
          { text: 'Politique de sécurité', link: '/SECURITY' },
          { text: 'Journal des versions', link: '/CHANGELOG' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/stephanfo/club-o-clock' },
    ],

    editLink: {
      pattern: 'https://github.com/stephanfo/club-o-clock/edit/main/:path',
      text: 'Proposer une correction sur GitHub',
    },

    lastUpdatedText: 'Dernière mise à jour',
    docFooter: { prev: 'Précédent', next: 'Suivant' },
    darkModeSwitchLabel: 'Apparence',
    returnToTopLabel: 'Haut de page',
    sidebarMenuLabel: 'Documentation',
    outlineTitle: 'Sur cette page',

    footer: {
      message: 'Publié sous licence AGPL-3.0.',
      copyright: 'Une instance par club — vos données restent chez vous.',
    },
  },
})
