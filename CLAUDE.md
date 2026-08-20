# CLAUDE.md

Instructions pour Claude Code (ou tout autre assistant de code) sur ce dépôt.

## Source de vérité

- **Spec produit** : [doc/PRD.md](doc/PRD.md). Toute fonctionnalité demandée doit être référencée dans le périmètre V1 (§3.1) ; si elle est en hors-V1 (§3.2), le signaler **avant** de coder.
- **Cadrage technique** : [doc/CADRAGE_TECHNIQUE.md](doc/CADRAGE_TECHNIQUE.md) — source de vérité pour tout choix technique, et pour les raisons de chaque arbitrage.
- **Installation & déploiement** : [doc/INSTALL.md](doc/INSTALL.md).
- **Contribution** : [CONTRIBUTING.md](CONTRIBUTING.md) — porte de qualité, conventions de commit, périmètre.

## État du projet

**Application complète et en production.** Le planning, les inscriptions (avec liste d'attente et quotas), les rôles cumulables, la tutelle parentale, les notifications email/push, les parcours GPX, la météo, l'administration et les journaux d'audit sont livrés et couverts par les tests.

Stack — **monolithe Laravel 13** sur hébergement mutualisé + **MariaDB/MySQL (InnoDB)** ; frontend **Blade + Livewire 3 + Alpine.js 3** rendu serveur ; PWA par service worker maison ; push **VAPID natif** ; email transactionnel UE ; stockage objets sur filesystem hors webroot.

**Base de données** : la source de vérité du schéma est [database/migrations/](database/migrations/). Une évolution de schéma se porte dans une **nouvelle migration**, jamais en éditant une migration existante ni le dump.

> [database/schema/mariadb-schema.sql](database/schema/mariadb-schema.sql) est un **artefact dérivé**, régénéré par `php artisan schema:dump` : Laravel le charge automatiquement au début de `migrate` pour accélérer la reconstruction (tests, base neuve). Il n'est **pas** une source de vérité, et il n'est chargé que pour la connexion `mariadb` (dev/test) — la production, en `DB_CONNECTION=mysql`, joue les migrations. **Le régénérer après toute migration qui change le schéma**, sinon les tests repartent d'un schéma périmé.

## Porte de qualité

**Toute modification doit passer `composer check`** (pint + phpstan niveau 5 + suite de tests) :

```bash
composer check
```

Une correction de bug apporte **un test qui échouait avant**. Une fonctionnalité apporte les tests de son comportement, **y compris les refus** (qui n'a *pas* le droit de faire quoi).

### Tests navigateur (E2E) — hors `composer check`

`composer check` ne voit ni le rendu ni les clics. Un harnais **Playwright** ([tests/E2E/](tests/E2E/), conventions dans son [README](tests/E2E/README.md)) rejoue les parcours dans un vrai navigateur : il clique, attend Livewire, vérifie l'état **en base** et prend des captures aux deux formats.

```bash
php artisan serve                             # prérequis : serveur + jeu de démo seedé
node tests/E2E/run.mjs                        # 17 scénarios non destructifs
node tests/E2E/destructif.mjs --oui-je-sais   # RGPD, tutelle, bascule de saison — reconstruit la base
```

**Ne pas l'ajouter à `composer check`** (serveur + base + navigateur requis : la porte deviendrait fragile). La référence de non-régression reste PHPUnit.

**Jamais d'id de séance en dur dans un scénario.** Le jeu de démo est relatif à `now()`, mais la position d'une séance par rapport à l'instant du run dépend du jour et de l'heure — un id figé rend le scénario vert ou rouge selon le moment. Sélectionner par les propriétés via `seance(where)` / `seanceFuture(where)` de [tests/E2E/lib.mjs](tests/E2E/lib.mjs). Idem pour les comptes : par email, jamais par `user_id`.

Quand une modification touche l'UI, **lancer le harnais et regarder les captures** — c'est le seul moyen de voir ce qu'un test textuel ne montre pas (bloc vide, contraste, débordement). Deux règles d'écriture : apparier toute assertion négative à un **contrôle positif** (« X est absent » ne vaut rien sur une liste vide), et **restaurer l'état** modifié.

## Fidélité au design system (NON NÉGOCIABLE)

Le design system existe : tout écran doit lui être fidèle.

1. **Réutiliser les classes de [`resources/css/club-app.css`](resources/css/club-app.css) telles quelles** (`scard`, `seg`/`seg-item`, `chip`/`dot-*`, `ifield`, `btn-*`, `dk-side`, `botnav`, `auth-*`, `fondu`, `dsp`, `eyebrow`…). **Avant d'écrire une classe maison, vérifier qu'elle n'existe pas déjà.**
2. **Toute couleur, taille ou espacement passe par un token** (`var(--…)` de [`club-tokens.css`](resources/css/club-tokens.css)), **jamais une valeur en dur**. Du CSS maison uniquement pour ce que le design ne couvre pas.
3. **S'aligner sur les écrans existants.** Avant de créer un écran, en lire un comparable dans [`resources/views/livewire/`](resources/views/livewire/) et en reprendre la structure.
4. **Mobile ET desktop.** L'app est utilisée majoritairement au téléphone : un écran n'est fini que s'il rend correctement dans les deux formats.

> Les styles inline présents dans certaines vues sont un **portage fidèle** des maquettes de référence — ne pas les « nettoyer » en classes génériques.

## Conventions UI

- **Feedback d'action** : `flash('status', …)` = succès/info (vert), `flash('warn', …)` = refus/erreur (orange). Rendu par `<x-flash-float />` à la racine de **chaque écran Livewire** (jamais dans le layout : il ne re-rend pas sur une action). Ne pas réintroduire `flash('toast')` ni de bannières inline persistantes.
- **Confirmations** : `wire:confirm` natif pour l'anodin réversible (se désinscrire, délier un appareil…) ; `<x-dialog danger>` avec conséquences (`x-conseq-row`) pour le destructif ou ce qui notifie des tiers (annuler une séance, rompre une tutelle).
- **Formats de date** (heure club, `isoFormat`) : liste/contexte dense = `ddd D MMM` · pleine page/titre = `dddd D MMMM` · heure toujours `HH:mm` · mois seul = `MMMM YYYY`.
- **Admin sur mobile : assumé desktop.** Pas d'entrée de navigation admin sur mobile — ces écrans ne sont pas conçus pour ce format ; ne pas en ajouter sans les repenser.
- **CTA d'action serveur** : toujours `wire:loading.attr="disabled"` + `wire:target` (latence du mutualisé, anti double-tap).
- **Retour** (chevron de topbar, bouton « ← » desktop) : `onclick="return !window.clubBack?.()"` + `href` de repli, et **jamais `wire:navigate` sur ce lien** — il navigue dès `mousedown`, donc avant l'`onclick`, et le repli partirait toujours. Cf. [resources/js/back.js](resources/js/back.js).
- **Ne jamais empiler `wire:click` et `wire:navigate` sur le même clic** : la course est non déterministe (bugs vus en production). Porter l'état dans l'URL, lu au `mount()`.

## Ce qu'il NE faut PAS faire

### Stack technique

- **La stack est tranchée.** Toute proposition divergente (autre framework, SPA découplée, NoSQL, Docker, Node long-running, WebSocket serveur…) doit être **discutée explicitement** avant écriture — le cadrage documente pourquoi ces options sont écartées (contraintes du mutualisé, maintenance par un bénévole solo, AGPL/RGPD).
- Le **PRD reste agnostique de la stack** : ne pas y injecter de techno. Une implication technique d'une exigence produit s'y formule en **exigence non-fonctionnelle** (ex. *« évaluation du quota en temps quasi-constant »*) ; le *comment* vit dans le cadrage.

### Modèle

- **Pas de multi-tenant, jamais.** Pas de `tenant_id`, pas d'isolation logique multi-club, pas de plumbing « préparé pour plus tard ». Un autre club déploie sa propre instance.
- **Pas de stockage** de numéro de téléphone ni de certificat médical (minimisation RGPD).
- **Pas de stockage** du numéro de licence fédérale (géré sur l'extranet fédéral externe).

### Vocabulaire

- Le statut d'inscription est **`participating` | `waitlist` | `cancelled`**, uniforme sur les 3 `kind`. Ne pas réintroduire `confirmed` ni `declared_participation`.
- La récurrence repose sur un **générateur `SessionTemplate`** produisant des `Session` indépendantes. **Pas de RRULE / EXDATE.**

### Services tiers

Deux services tiers sont nommés dans le PRD comme **choix produit**, pas comme choix de stack : **OpenRunner Pro** (embed du parcours, optionnel) et **Open-Meteo** (météo : gratuit, sans clé, UE). Tout autre service tiers relève du cadrage technique — ne pas en figer un nouveau sans discussion.

## Points d'attention

- **Langue du projet : français.** Code, commentaires, commits, interface, documentation. Pas d'i18n à introduire prématurément (V1 monolingue assumée).
- **PWA, pas d'application native** (iOS/Android hors périmètre V1).
- **Push iOS** : limitation Safari 16.4+ **et PWA installée** assumée. L'email est le fallback documenté.
- **Amorçage** : il n'y a pas d'inscription publique. Le premier admin se crée par `php artisan club:create-admin` — seul point d'entrée d'une base vide.
- Quand une règle évoque « à arbitrer au cadrage technique » dans le PRD, **ne pas trancher** : soulever la question si elle bloque, sinon l'ignorer.

## Conventions de collaboration

- **Répondre en français.**
- Avant tout livrable significatif, **cadrer les choix** plutôt que de supposer.
- Toute proposition qui diverge du PRD doit être discutée explicitement avant écriture.
