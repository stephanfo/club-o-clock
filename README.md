# Club'O'Clock

> **Nage, pédale, cavale… fini le planning infernal !**

[![Licence AGPL-3.0](https://img.shields.io/badge/licence-AGPL--3.0-blue.svg)](LICENSE)
[![CI](https://github.com/stephanfo/club-o-clock/actions/workflows/ci.yml/badge.svg)](https://github.com/stephanfo/club-o-clock/actions/workflows/ci.yml)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3+-777bb4.svg)](https://www.php.net/)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-ff2d20.svg)](https://laravel.com/)

**Le planning d'entraînement de ton club, sans le tableur partagé ni le fil de discussion
interminable.** Les adhérents voient les séances et s'inscrivent depuis leur téléphone ; les coachs
savent qui vient ; les places limitées se gèrent toutes seules, liste d'attente comprise.

Application web installable (PWA) pour **club sportif associatif**, pensée pour un club de 50 à 150
adhérents administré par des bénévoles.

### Ce que ça résout

| Le problème | La réponse |
|---|---|
| « Qui vient à la séance de mercredi ? » | Inscriptions en ligne, liste des présents à jour en temps réel |
| Une place se libère la veille | **Liste d'attente** avec promotion automatique et notification |
| Le créneau piscine est limité à 24 | **Capacité** par séance, et **quotas** par période |
| Une séance est annulée à 18 h | **Notification** email et push web à tous les inscrits |
| Les mineurs et leurs parents | **Tutelle parentale** : le parent inscrit son enfant et reçoit ses notifications |
| Recréer la semaine type chaque saison | **Modèles de séance** qui génèrent le planning |

### Ce qui le distingue

- 🔒 **Tes données restent chez toi.** Une instance par club, sur ton hébergement. Pas de SaaS, pas
  d'abonnement, pas de tiers qui exploite le fichier de tes adhérents.
- 🇫🇷 **RGPD par construction.** Ni numéro de téléphone, ni certificat médical, ni numéro de
  licence. Aucun service non européen dans le chemin critique, polices auto-hébergées, aucun
  traqueur.
- 🪶 **Ça tourne sur un mutualisé à quelques euros.** Ni Docker, ni serveur Node, ni WebSocket :
  du PHP et un cron.
- 🎨 **C'est le club de l'utilisateur, pas le nôtre.** Nom, logo, baseline et palette se règlent
  depuis l'administration.
- 📱 **Installable comme une app**, sans passer par un store.
- ⚖️ **Libre, sous [AGPL-3.0](LICENSE)** — utilisable, modifiable, et qui le reste.

### 👉 Essayer sans rien installer

**[demo.cluboclock.ratelet.fr](https://demo.cluboclock.ratelet.fr/)** — les identifiants sont
affichés sur l'écran de connexion, un clic les remplit.

Données entièrement fictives, **remise à zéro chaque nuit** : n'hésite pas à tout casser. Aucun
email ni notification ne peut sortir de cette instance, c'est verrouillé côté serveur et non
simplement désactivé — teste donc les invitations et les annulations sans crainte.

### À quoi ça ressemble

L'adhérent est sur son téléphone, le bureau est sur son ordinateur : l'application est conçue pour
les deux.

| Le planning de la semaine | La fiche de séance |
|---|---|
| <img src="doc/img/planning-semaine.png" alt="Planning hebdomadaire sur mobile : séances par jour, une séance annulée, une autre en liste d'attente" width="320"> | <img src="doc/img/seance-parcours.png" alt="Fiche de séance sur mobile : onglet Parcours affichant le tracé GPX et ses bornes kilométriques" width="320"> |
| Les séances de la semaine, leur lieu et leur remplissage. Une annulation, une liste d'attente et « tu participes » se lisent d'un coup d'œil. | Infos, encadrement, inscrits et **parcours** : le tracé GPX s'affiche directement, avec son profil et ses bornes. |

| La tutelle parentale | L'administration |
|---|---|
| <img src="doc/img/mes-enfants.png" alt="Écran Mes enfants : deux enfants mineurs, l'un sans compte propre, l'autre avec le sien" width="320"> | <img src="doc/img/admin-adherents.png" alt="Administration des adhérents sur desktop : compteurs, filtres, rôles et statuts d'accès" width="440"> |
| Un parent gère ses enfants depuis son propre compte — y compris quand l'un a déjà le sien et l'autre non, avec le bon routage des notifications. | Adhérents, rôles, modèles de séance, journaux d'audit et envois : le bureau garde la main sur tout. |

> 📸 **[Voir tous les écrans](doc/CAPTURES.md)** — création de séance, génération de la saison,
> tableau de bord, journaux d'audit, envois, paramètres du club. Captures prises sur la démo
> publique, avec des données fictives.

---

## Stack

| Couche | Choix |
|---|---|
| **Backend** | PHP 8.3+ · Laravel 13 |
| **Frontend** | Blade + Livewire 3 + Alpine.js 3 (rendu serveur) |
| **Base de données** | MariaDB / MySQL (InnoDB) |
| **Push notifications** | Web Push VAPID natif (`minishlink/web-push`) |
| **Email transactionnel** | Brevo (UE) via `symfony/brevo-mailer` |
| **PWA** | Service worker maison + manifest dynamique (identité et icônes du club) |
| **Stockage objets** | Filesystem hors webroot (servi par contrôleur PHP) |
| **Météo** | Open-Meteo (gratuit, UE, sans clé, cache 3h) |
| **XLSX** | `phpoffice/phpspreadsheet` (exports côté serveur) |

Design system : tokens CSS ([`club-tokens.css`](resources/css/club-tokens.css)) + composants
([`club-app.css`](resources/css/club-app.css)). Toute couleur, taille et espacement passe par un
token — la palette du club se personnalise depuis l'administration, sans toucher au CSS.

Les décisions techniques et leurs contreparties sont argumentées dans
**[doc/CADRAGE_TECHNIQUE.md](doc/CADRAGE_TECHNIQUE.md)**.

---

## Démarrage rapide

> 📖 **Le guide complet** — prérequis détaillés, configuration email, OAuth, notifications push,
> premier démarrage et déploiement (mutualisé ou VPS) : **[doc/INSTALL.md](doc/INSTALL.md)**.
> Ci-dessous, la version courte pour développer en local.

Il faut **PHP ≥ 8.3**, **MariaDB ≥ 10.6** ou **MySQL ≥ 8.0**, **Composer ≥ 2** et **Node ≥ 20**.

```bash
git clone https://github.com/stephanfo/club-o-clock.git && cd club-o-clock

# Renseigner la base de données dans .env avant la suite, puis :
composer setup                # dépendances, .env, clé, migrations, assets
php artisan db:seed           # catalogues — indispensable, même en production
php artisan storage:link      # sans lui, le logo du club est en 404

php artisan club:create-admin # le seul point d'entrée d'une base vide
php artisan serve             # http://localhost:8000
```

**Pour explorer l'application remplie** — un club fictif, six semaines de séances, tous les cas de
figure — ajouter le jeu de démonstration (⚠️ **jamais en production**, `migrate:fresh` détruit
tout) :

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSeeder
php artisan db:seed --class=GpxRouteSeeder
```

Mot de passe universel `password` ; les comptes et les scénarios qu'ils illustrent sont dans
**[doc/COMPTES_DEMO.md](doc/COMPTES_DEMO.md)**.

---

## Contribuer

La porte de qualité est `composer check` — Pint, PHPStan niveau 5, cohérence du schéma et la suite
de tests. Ce qui passe en local passe en CI, et inversement.

```bash
composer check
```

Un harnais **Playwright** complète cette porte pour ce qu'elle ne voit pas — le rendu, les clics et
les parcours de bout en bout. Il tourne à part (serveur + base de démo requis), jamais en CI :

```bash
node tests/E2E/run.mjs
```

Conventions, périmètre et attentes sur le code : **[CONTRIBUTING.md](CONTRIBUTING.md)**.

---

## Documentation

| Document | Contenu |
|---|---|
| [`doc/INSTALL.md`](doc/INSTALL.md) | **Installation, configuration, premier démarrage, déploiement** |
| [`doc/PRD.md`](doc/PRD.md) | Spécification produit — source de vérité fonctionnelle, y compris ce qui est **hors périmètre** |
| [`doc/CADRAGE_TECHNIQUE.md`](doc/CADRAGE_TECHNIQUE.md) | Décisions techniques et compromis assumés |
| [`doc/COMPTES_DEMO.md`](doc/COMPTES_DEMO.md) | Comptes et scénarios du jeu de démonstration |
| [`doc/CAPTURES.md`](doc/CAPTURES.md) | Tous les écrans en images, côté adhérent, coach et bureau |
| [`doc/PLAN_TESTS_MEMBRES.md`](doc/PLAN_TESTS_MEMBRES.md) | Recette en interface, sans ligne de commande — pour les testeurs du club |
| [`doc/PLAN_TESTS.md`](doc/PLAN_TESTS.md) | Recette côté organisateur : journaux, commandes, base |
| [`tests/E2E/README.md`](tests/E2E/README.md) | Harnais de tests navigateur : couverture, exécution, conventions |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Contribuer : porte de qualité, conventions, périmètre |
| [`SECURITY.md`](SECURITY.md) | Signaler une vulnérabilité |
| [`CHANGELOG.md`](CHANGELOG.md) | Journal des versions |

---

## Licence

**[AGPL-3.0](LICENSE)**. Tu peux l'utiliser, le modifier et le redistribuer ; si tu déploies une
version modifiée accessible en réseau, tu dois en publier le code source.
