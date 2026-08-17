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
| **PWA** | Service worker maison + manifest statique |
| **Stockage objets** | Filesystem hors webroot (servi par contrôleur PHP) |
| **Météo** | Open-Meteo (gratuit, UE, sans clé, cache 3h) |
| **XLSX** | `phpoffice/phpspreadsheet` (exports côté serveur) |

Design system : tokens CSS ([`club-tokens.css`](resources/css/club-tokens.css)) + composants
([`club-app.css`](resources/css/club-app.css)). Toute couleur, taille et espacement passe par un
token — la palette du club se personnalise depuis l'administration, sans toucher au CSS.

---

## Prérequis

- PHP ≥ 8.3 (extensions : `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `intl`, `fileinfo`)
- MariaDB ≥ 10.6 ou MySQL ≥ 8.0 (InnoDB)
- Composer ≥ 2
- Node.js ≥ 20 + npm (build des assets uniquement)
- Un fournisseur email transactionnel UE (Brevo par défaut, Scaleway TEM en alternative)
- Un client Google OAuth propre au club (optionnel — bouton Google inopérant sans lui)
- Un compte OpenRunner Pro (optionnel — embeds de parcours uniquement)

---

## Installation locale

> 📖 **Guide complet** — installation, configuration, premier démarrage et déploiement (mutualisé
> ou VPS) : **[doc/INSTALL.md](doc/INSTALL.md)**. Ci-dessous, la version courte pour développer en
> local.

```bash
# 1. Cloner et installer les dépendances
git clone https://github.com/stephanfo/club-o-clock.git && cd club-o-clock
composer install
npm install

# 2. Configurer l'environnement
cp .env.example .env
php artisan key:generate

# 3. Configurer la base de données dans .env
#    DB_CONNECTION=mariadb
#    DB_HOST=127.0.0.1
#    DB_DATABASE=cluboclock
#    DB_USERNAME=root
#    DB_PASSWORD=

# 4. Migrer et seeder les catalogues (catégories FFTri, disciplines, qualifications…)
php artisan migrate
php artisan db:seed   # CatalogSeeder uniquement

# 5. Builder les assets
npm run build         # ou : npm run dev (watch)

# 6. Lancer le serveur de développement
php artisan serve
```

L'application est accessible sur `http://localhost:8000`.

### Seed de démo (semaine type du club)

Peuple la base avec des utilisateurs, lieux, templates et séances sur 6 semaines glissantes :

```bash
php artisan migrate:fresh --seed          # catalogues + tables vides
php artisan db:seed --class=DemoSeeder   # données de démo
```

Tous les comptes de démo partagent le mot de passe `password`. Points d'entrée par rôle :

| Email | Rôle |
|---|---|
| `admin@demo.club` | Admin |
| `vincent@demo.club` | Coach |
| `mathieu@demo.club` | Coach **+** athlète (rôles cumulés) |
| `karine@demo.club` | Coach (qualification BNSSA valide) |
| `marie@demo.club` | Athlète adulte |
| `tom@demo.club` | Athlète mineur autonome (Juniors) |
| `olivier@demo.club` | Parent « pur » (garant, sans rôle propre) |
| `sandrine@demo.club` | Garant de **2 enfants** (avec et sans compte propre) **+** athlète |

> 📖 **La liste complète** (6 coachs, 22 athlètes, les 3 profils de minorité P1/P2/P3, les comptes
> inactifs et les cas de test) vit dans **[doc/COMPTES_DEMO.md](doc/COMPTES_DEMO.md)** — source de
> vérité unique, tenue à jour avec le seeder. Ce tableau n'en donne qu'un extrait.

---

## Variables d'environnement clés

| Variable | Description |
|---|---|
| `BOOTSTRAP_ADMIN_EMAIL` | Email du premier admin (reçoit le rôle `admin` à l'inscription) |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | Client OAuth du club (vide = bouton Google inopérant) |
| `VAPID_SUBJECT` | URL ou `mailto:` du contact push (ex. `mailto:admin@monclub.fr`) |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | Paire VAPID — générer via `php artisan club:vapid-keys` |
| `BREVO_API_KEY` | Clé API Brevo pour l'email en production |
| `MAIL_MAILER` | `log` (dev) · `brevo` (prod via API) · `smtp` (Scaleway TEM ou autre) |
| `NOTIF_PUSH_DRIVER` | Driver push réel en prod : `App\Notifications\Channels\PushChannel` (vide = `LogChannel`) |
| `NOTIF_EMAIL_DRIVER` | Driver email réel en prod : `App\Notifications\Channels\EmailChannel` (vide = `LogChannel`) |
| `APP_URL` | URL publique de l'instance (ex. `https://planning.monclub.fr`) |
| `APP_ENV` | `local` / `production` |

### ⚠️ Activer réellement l'email en production

L'envoi d'email passe par **deux interrupteurs indépendants** — les deux doivent être positionnés,
sinon des messages restent silencieusement dans les logs sans jamais partir :

1. **`MAIL_MAILER=brevo`** (+ `BREVO_API_KEY`) — choisit le *transport* qui expédie les emails.
   Tant qu'il vaut `log` (défaut), **tout email est simplement écrit dans `storage/logs/laravel.log`**,
   aucune requête réseau. C'est voulu en dev/démo (on y récupère l'URL du lien magique).
2. **`NOTIF_EMAIL_DRIVER="App\Notifications\Channels\EmailChannel"`** — choisit le *driver de canal*
   des **notifications métier** (annulation de séance, promotion de liste d'attente, tutelle…).
   Tant qu'il est vide, ces notifications utilisent `LogChannel` : elles sont **marquées « livrées »
   dans l'outbox mais ne composent aucun email** (le drain journalise seulement `id/type/user`).

**Conséquence du piège classique** : mettre `MAIL_MAILER=brevo` **sans** `NOTIF_EMAIL_DRIVER`
→ les **liens magiques de connexion partent** (ils n'utilisent que `MAIL_MAILER`, canal `mail` natif),
mais **toutes les notifications métier restent bloquées en `LogChannel`**, sans erreur visible.

Configuration email complète pour la production :

```dotenv
MAIL_MAILER=brevo
BREVO_API_KEY=xkeysib-…
MAIL_FROM_ADDRESS="planning@monclub.fr"
MAIL_FROM_NAME="Mon Club"
NOTIF_EMAIL_DRIVER="App\Notifications\Channels\EmailChannel"
# Symétriquement pour le push (sinon les notifs push restent en LogChannel) :
NOTIF_PUSH_DRIVER="App\Notifications\Channels\PushChannel"
```

> Les notifications métier ne partent pas immédiatement : elles sont mises en file (table outbox)
> puis expédiées par le drain, lancé toutes les 5 min par le cron `schedule:run` (voir Déploiement).
> Le lien magique, lui, part **inline** (immédiat).

### Google OAuth (optionnel)

La connexion Google est facultative : sans client OAuth configuré, le bouton « Google » est
inopérant (les autres méthodes — email + mot de passe, lien magique — restent disponibles).

Pour l'activer, créer un client OAuth propre au club dans la
[Google Cloud Console](https://console.cloud.google.com/apis/credentials) :

1. **Créer les identifiants** → « ID client OAuth » → type **Application Web**.
2. **URI de redirection autorisé** : `${APP_URL}/auth/google/callback`
   (ex. `https://planning.monclub.fr/auth/google/callback`).
3. Reporter les identifiants dans `.env` :

   ```dotenv
   GOOGLE_CLIENT_ID=…apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=…
   GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
   ```

Le linking n'est accepté que si l'email Google est **vérifié** et correspond à un compte club
**déjà vérifié** (pas de création de compte par Google — sécurité, cadrage §14.1).

### Notifications push (optionnel)

Le push web est natif **VAPID** — aucun service tiers, aucun flux hors-UE : le navigateur parle
directement à son propre serveur push (Google / Mozilla / Apple selon le navigateur). Il suit la
**même file outbox + drain** que l'email, avec deux prérequis en plus.

**1. Générer la paire de clés VAPID** (une fois, propre à l'instance) et la reporter dans `.env` :

```bash
php artisan club:vapid-keys   # affiche subject / public / private à coller dans .env
```

```dotenv
NOTIF_PUSH_DRIVER="App\Notifications\Channels\PushChannel"
VAPID_SUBJECT="mailto:admin@monclub.fr"   # contact imposé par la spec VAPID (mailto: ou URL)
VAPID_PUBLIC_KEY=…                          # exposée au front (souscription)
VAPID_PRIVATE_KEY=…                         # secret — ne jamais exposer
```

Comme pour l'email, **deux interrupteurs en série** :

- **`NOTIF_PUSH_DRIVER`** vide (défaut) → `LogChannel` : les notifs push sont marquées « livrées »
  dans l'outbox mais **ne partent jamais**. Le renseigner active `PushChannel`.
- **Clés VAPID** absentes → le premier envoi réel lève une `RuntimeException` explicite. Les deux
  doivent être positionnés ensemble (driver **sans** clés = erreur ; clés **sans** driver = rien ne part).

**2. Chaque utilisateur s'abonne depuis son appareil.** Contrairement à l'email (l'adresse est en
base), le push exige une **souscription active par appareil** : Profil → Notifications → activer le
push. L'abonnement (endpoint + clés du navigateur) est stocké en base ; un utilisateur peut avoir
plusieurs appareils (la notif est poussée à **tous**). Les abonnements morts (404/410 : PWA
désinstallée, cache vidé) sont **purgés automatiquement** au prochain envoi.

**Limitation iOS / Safari (fallback = email).** Le push web sur iPhone/iPad n'existe que sur
**Safari 16.4+** et **uniquement si la PWA est installée** sur l'écran d'accueil (« Ajouter à
l'écran d'accueil »). Dans un onglet Safari classique, le bouton d'activation n'apparaît pas. Pour
ces utilisateurs, **l'email prend le relais** — d'où l'intérêt de configurer les deux canaux.

---

## Commandes Artisan utiles

```bash
# Créer le premier compte admin (seul point d'entrée d'une base vide)
php artisan club:create-admin president@monclub.fr

# Générer la paire de clés VAPID (à faire une fois, coller dans .env)
php artisan club:vapid-keys

# Drainer manuellement la file d'envoi des notifications (outbox)
php artisan notifications:drain

# Rafraîchir le cache météo (séances de la fenêtre J+16)
php artisan weather:refresh

# Lancer les tests
composer test
# ou directement :
php artisan test
```

---

## Déploiement (OVH Pro mutualisé)

Le déploiement cible est un hébergement mutualisé OVH Pro.

> ⚠️ **Sur le mutualisé OVH, `npm install` / `npm run build` ne sont pas exécutables en
> SSH** (pas de toolchain Node fiable, quotas process, mémoire). En revanche
> **`composer install` et les commandes `artisan` (PHP pur) passent** normalement.
> Le principe est donc : **on builde le front en local, on transfère uniquement le
> résultat** (`public/build/`) ; tout le reste (dépendances PHP, migrations, caches) se
> régénère côté serveur en SSH comme d'habitude.
>
> Corollaire Vite : **on ne copie jamais `node_modules/` sur le serveur.** Vite compile
> les sources front en bundles statiques dans `public/build/` (avec son `manifest.json`) —
> c'est le **seul** livrable front. `node_modules/` ne sert qu'au build, il reste en local.

### 1. Builder le front en local

Sur ta machine (celle qui a Node) :

```bash
npm ci            # dépendances front (dans node_modules/, reste en local)
npm run build     # compile les assets → public/build/ (+ manifest.json)
```

Seul dossier à transférer ensuite :

| Dossier | Contenu | Généré par | Transfert |
|---|---|---|---|
| `public/build/` | assets front compilés + `manifest.json` | `npm run build` (local) | **SFTP / rsync** |
| `vendor/` | dépendances PHP (Laravel, Livewire…) | `composer install` (**sur OVH**) | — (régénéré en SSH) |

`public/build/` est **gitignoré** (`.gitignore`) : c'est voulu, on ne versionne pas les
artefacts. Il transite donc **hors Git**, par transfert de fichiers.

### 2. Transférer le code + le build vers OVH

**Par SFTP** (*SSH File Transfer Protocol* — la voie qui a servi à déployer ce projet), en excluant
ce qui se régénère ou appartient au serveur :

```bash
rsync -az --delete \
  --exclude='.git/' --exclude='node_modules/' --exclude='vendor/' \
  --exclude='.env' --exclude='storage/' --exclude='tests/' --exclude='doc/' \
  ./  <user>@<ssh-host>:<docroot>/
```

Un client graphique (FileZilla, Cyberduck, manager OVH) fait le même travail : envoyer l'arbre en
désélectionnant ces dossiers. SFTP passe **par SSH**, donc les mêmes identifiants ouvrent aussi la
ligne de commande de l'étape 3. Jamais de **FTP simple** : tout y circule en clair.

> ⚠️ **`.env` et `storage/` appartiennent au serveur.** Les écraser avec ceux du poste casse
> l'instance et détruit les fichiers déjà téléversés par le club.
>
> 🚨 **`public/build/` est le piège** : gitignoré et hashé, il se transfère **à chaque changement
> front**, en **supprimant d'abord le dossier distant** (sinon les anciens bundles s'accumulent).
> Un build périmé ne lève **aucune erreur** — le site s'affiche avec l'ancien CSS.

<details>
<summary><b>Alternative : déploiement par Git</b>, si l'hébergeur expose un dépôt</summary>

```bash
git remote add ovh <ssh-host>:<repo>.git   # une seule fois
git push ovh main

# public/build/ est gitignoré : il ne passe PAS par Git
rsync -az --delete public/build/  <user>@<ssh-host>:<docroot>/public/build/
```

Plus rapide à répéter, mais ne dispense d'aucune étape ci-dessous.
</details>

### 3. Finaliser côté serveur (SSH — obligatoire)

> 🚨 **Transférer les fichiers ne suffit JAMAIS**, ni par FTP/SFTP ni par `git push`. Copier
> le code ne joue **pas** les migrations ni ne régénère les caches. Si un déploiement embarque une
> migration et que cette étape est sautée, la prod tombe en **500** dès qu'une route
> lit la colonne manquante (`QueryException` en plein pipeline, après authentification —
> vu en prod le 2026-07-11 avec `read_at`). **Toujours dérouler le bloc SSH ci-dessous
> après chaque transfert.** En cas de doute, `php artisan migrate:status` liste les
> migrations `Pending`.

> ⚠️ **Le mutualisé OVH tourne sur MySQL 8.4** (vérifié : `select version()` →
> `8.4.x`), malgré le nom d'hôte en `*.mysql.db`. Dans le `.env` **du serveur** :
> **`DB_CONNECTION=mysql`** (et non `mariadb`, qui reste la valeur du dev local).
> À défaut, Laravel parle le mauvais dialecte. Rappel : MySQL **interdit** un
> `DEFAULT` sur colonne JSON (err. 1101) — c'est pour ça que le schéma ne pose
> aucun défaut SQL sur les colonnes JSON (le défaut est porté côté modèle).

> ⚠️ **Session DB forcée en UTC** (`config/database.php` → `'timezone' => '+00:00'`,
> depuis 2026-07-11) : sans elle, MySQL (tz `SYSTEM` = Paris) interprète les colonnes
> `TIMESTAMP` en heure locale → écritures **refusées 1 h/an** (heure inexistante au
> passage à l'heure d'été) et instants stockés décalés. Effet au premier déploiement :
> les valeurs `TIMESTAMP` écrites AVANT (métadonnées uniquement : logs `created_at`,
> expirations de tokens, outbox `available_at`, `archived_at`…) relisent 1-2 h plus
> tôt. Les horaires de séances (`start_at`, `DATETIME`) sont **insensibles**. Rien à
> migrer : décalage cosmétique sur ~quelques semaines d'historique.

```bash
# composer install et artisan passent sur le mutualisé (PHP pur).
composer install --no-dev --optimize-autoloader
php artisan migrate --force

# ⚠ OBLIGATOIRE : publier l'asset Livewire EN STATIQUE (voir Dépannage §HTTP/2).
# Sans ça, livewire.min.js est servi par une route PHP → framing HTTP/2 cassé
# côté OVH. Une fois public/vendor/livewire/manifest.json présent, Livewire
# bascule seul le <script src> sur /vendor/livewire/livewire.min.js.
php artisan vendor:publish --tag=livewire:assets --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> Si le build front a changé sans que la vue ait bougé, `view:cache` suffit ; en cas de
> comportement bizarre après déploiement, `php artisan optimize:clear` remet à zéro tous
> les caches (config/route/view) avant de les recompiler.

**Checklist SSH — à dérouler d'un bloc après chaque transfert** (copiable tel quel) :

```bash
composer install --no-dev --optimize-autoloader   # si vendor/ a changé
php artisan migrate:status                         # repère les migrations « Pending »
php artisan migrate --force                        # ⚠ obligatoire dès qu'une migration est en attente
php artisan vendor:publish --tag=livewire:assets --force
php artisan optimize:clear                         # purge les caches de l'ancien code
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

> Rien à migrer ? `migrate --force` est un no-op sans risque : le lancer
> systématiquement coûte moins cher qu'une 500 pour une migration oubliée.

**Cron unique à configurer dans le manager OVH** (chaque minute) :

```
* * * * * /path/to/php /path/to/artisan schedule:run >> /dev/null 2>&1
```

> `schedule:run` doit être lancé **chaque minute** : c'est lui qui cadence les
> tâches internes (`notifications:drain` toutes les 5 min, `weather:refresh`
> horaire, `model:prune` quotidien) déclarées dans `routes/console.php`. Un cron
> plus espacé décalerait ou raterait ces créneaux.

Ce cron pilote deux tâches : pré-calcul météo J+16 et **drain de la file de notifications**
(`notifications:drain`). Sans lui, même avec l'email correctement configuré (voir
_Activer réellement l'email en production_), **les notifications métier ne partent jamais** :
elles s'accumulent dans l'outbox sans être expédiées.

**Backups** : les sauvegardes automatiques natives OVH Pro (BDD quotidienne rétention 30j, fichiers J-14) couvrent le RPO. Prévoir un export froid mensuel (dump SQL + dossier `storage/app`) téléchargé hors OVH pour la rétention 1 an.

### Dépannage — Livewire ne charge pas en prod (HTTPS / HTTP2 / 404)

Trois causes **distinctes** peuvent casser le chargement de `livewire.min.js` sur
le mutualisé OVH (elles se sont enchaînées au premier déploiement, 2026-07-07).
Diagnostiquer par le message exact dans la console navigateur :

| Symptôme console | Cause | Correctif |
|---|---|---|
| `http://…/livewire.min.js` bloqué / « connexion réseau perdue » (Safari) | Le proxy TLS d'OVH ne transmet pas `X-Forwarded-Proto` : Laravel se croit en http malgré `APP_URL=https://…` et génère l'asset en `http://` (mixed content). | `URL::forceScheme('https')` dans `AppServiceProvider::boot()` (actif si `APP_URL` en https). **Déjà dans le code.** Vérifier `APP_URL=https://…` dans le `.env` OVH. |
| `net::ERR_HTTP2_PROTOCOL_ERROR` sur `/livewire/livewire.min.js` | Livewire sert le JS via une route PHP ; le HTTP/2 d'OVH re-compresse/re-chunke la réponse et casse le framing. | Publier l'asset **en statique** : `php artisan vendor:publish --tag=livewire:assets --force`. Livewire bascule seul sur `/vendor/livewire/…`. **Étape de la checklist SSH.** |
| `404` sur `/vendor/livewire/livewire.min.js` (fichier pourtant présent) | Le `.htaccess` racine bloque `/vendor/` défensivement — il attrapait aussi cet asset public. | `RedirectMatch 404 (?i)/vendor/(?!livewire/)` dans le `.htaccess`. **Déjà dans le code.** |

> Après correctif : `php artisan config:clear && php artisan config:cache` puis
> **hard refresh** (Cmd+Shift+R). L'asset doit répondre `200` en
> `https://…/vendor/livewire/livewire.min.js?id=…`.
>
> ⚠ Un `.htaccess` cassé met **tout le site** à terre sur mutualisé : tester le
> chargement d'une page **avant** de figer une modif du `.htaccess`.

---

## Architecture

```
Navigateur (PWA)
├── Blade (rendu serveur)
├── Livewire 3 (composants réactifs, état serveur)
├── Alpine.js 3 (micro-interactions client)
└── Service Worker (offline lecture planning semaine courante)

Serveur (OVH mutualisé Pro)
├── Laravel 13 (monolithe PHP)
│   ├── Auth : Fortify (MDP/reset) + magic link maison + Socialite (Google OIDC)
│   ├── Inscriptions : SELECT FOR UPDATE + transaction InnoDB (sérialisation atomique)
│   ├── Quota : requête live indexée bornée à la semaine (quasi-constant, sans Redis)
│   ├── Notifications : table outbox + drain cron (~5 min) + drain inline (urgents)
│   └── XLSX : phpspreadsheet (exports côté serveur)
├── MariaDB/InnoDB (persistance relationnelle)
├── Filesystem hors webroot (PJ séance, GPX, logo club)
└── Cron unique → scheduler (météo J+16 · drain outbox)

Services externes
├── Brevo / Scaleway TEM (email transactionnel UE)
├── Web Push VAPID (push navigateur, dont Safari 16.4+ via PWA installée)
├── Open-Meteo (météo, gratuit, UE, cache 3h)
├── Nominatim / OpenStreetMap (géocodage d'adresse, UE)
├── Google OIDC (client OAuth propre au club, optionnel)
└── OpenRunner Pro (embed iframe parcours, optionnel)
```

Pas de Docker, pas de Node long-running, pas de WebSocket serveur, pas de Redis — contraintes de l'hébergement mutualisé assumées et documentées dans [`doc/CADRAGE_TECHNIQUE.md`](doc/CADRAGE_TECHNIQUE.md).

---

## Flux réseau sortants

Pour rédiger sa page mentions légales/confidentialité (trame fournie sur `/mentions-legales`),
chaque club doit savoir quels services tiers son instance contacte. Voici l'inventaire complet —
tous UE ou sans flux de données personnelles identifiables :

| Service | Déclenché quand | Donnée transmise | UE / clé |
|---|---|---|---|
| **Open-Meteo** | Rafraîchissement météo (cron, J+16) pour chaque lieu de séance | Coordonnées GPS du lieu (pas de donnée personnelle) | UE, gratuit, sans clé |
| **Nominatim / OpenStreetMap** | Un admin saisit ou modifie l'adresse d'un `Location` | Texte de l'adresse saisie | UE, gratuit, sans clé, 1 req/s (mis en cache) |
| **Email transactionnel** (Brevo par défaut, Scaleway TEM en alternative) | Notification, lien de connexion (magic link), invitation | Email + nom du destinataire, contenu du message | UE, clé API |
| **Web Push (VAPID)** | Notification poussée à un appareil abonné | Payload chiffré vers l'endpoint du navigateur (Chrome/Firefox/Apple selon appareil) | Protocole standard, pas de service commercial intermédiaire côté club |
| **Google OAuth** (optionnel) | Connexion via le bouton « Google », si activé | Email + identité du compte Google de l'utilisateur | Hors UE (Google) — désactivable en laissant `GOOGLE_CLIENT_ID` vide |
| **OpenRunner Pro** (optionnel) | Affichage d'un parcours ayant un embed OpenRunner configuré | Chargement d'un iframe depuis OpenRunner | Self-hosting du club requis (cf. PRD) |

Pas d'analytics, pas de tracker, pas de CDN tiers. Les polices web (Manrope) sont auto-hébergées : aucun appel à Google Fonts.

---

## Structure du projet

```
app/
  Console/Commands/   # vapid-keys, drain-notifications, refresh-weather
  Http/               # Middleware, contrôleurs fichiers/auth
  Livewire/           # Composants réactifs (Home, Planning, SessionForm, SessionShow…)
    Admin/            # Composants admin (users, templates, catalogues, settings…)
  Models/             # Eloquent (User, Session, SessionTemplate, Registration…)
  Notifications/      # Dispatcher + channels (Push, Email, Log)
  Services/           # TemplateGenerationService, RegistrationService, QuotaService…
  Support/Logging/    # ActivityLogger, AuditLogger

database/
  migrations/         # Source de vérité du schéma — toute évolution passe par une nouvelle migration
  schema/             # Dump du schéma — artefact dérivé (php artisan schema:dump), jamais édité à la main
  seeders/
    CatalogSeeder.php # Données de référence (catégories, disciplines, qualifications)
    DemoSeeder.php    # Jeu de démonstration (utilisateurs, séances sur 6 semaines)

doc/                  # PRD, cadrage technique, installation, comptes de démo

resources/
  css/                # club-tokens.css, club-app.css (design system), app.css
  views/              # Blade (layouts, composants x-*, écrans Livewire)

tests/                # 655 tests (Feature + Unit)
```

---

## Tests

```bash
# Suite complète
php artisan test

# Avec couverture (nécessite Xdebug ou PCOV)
php artisan test --coverage

# Filtre sur un groupe
php artisan test --filter=RegistrationTest
```

**711 tests** (1912 assertions). `composer check` enchaîne le style (Pint), l'analyse statique
(PHPStan niveau 5), la cohérence du schéma (`schema:check-drift`) et la suite complète — c'est le
prérequis de toute contribution, et exactement ce que rejoue la CI sur chaque pull request.

> La suite tourne sur **MariaDB/InnoDB**, jamais SQLite : les tests d'inscription reposent sur des
> verrous de ligne que SQLite ne sait pas reproduire. Il faut donc une base `cluboclock_test`
> accessible — cf. [doc/INSTALL.md](doc/INSTALL.md).

---

## Rôles et accès

| Rôle | Accès |
|---|---|
| **Athlète** | Planning, fiche séance, inscription/désinscription, profil |
| **Parent garant** | Planning + inscriptions de ses enfants mineurs (P1/P2) |
| **Coach** | Tout athlète + saisie contenu séance, débrief, encadrement |
| **Admin** | Tout coach + gestion utilisateurs, templates, catalogues, paramètres club, notifications |

Les rôles sont cumulables (`roles` = tableau JSON sur `users`).

---

## Documentation

| Document | Contenu |
|---|---|
| [`doc/INSTALL.md`](doc/INSTALL.md) | **Installation, configuration, premier démarrage, déploiement** |
| [`doc/PRD.md`](doc/PRD.md) | Spécification produit — source de vérité fonctionnelle, y compris ce qui est **hors périmètre** |
| [`doc/CADRAGE_TECHNIQUE.md`](doc/CADRAGE_TECHNIQUE.md) | Décisions techniques et compromis assumés |
| [`doc/COMPTES_DEMO.md`](doc/COMPTES_DEMO.md) | Comptes et scénarios du jeu de démonstration |
| [`doc/CAPTURES.md`](doc/CAPTURES.md) | Tous les écrans en images, côté adhérent, coach et bureau |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Contribuer : porte de qualité, conventions, périmètre |
| [`SECURITY.md`](SECURITY.md) | Signaler une vulnérabilité |
| [`CHANGELOG.md`](CHANGELOG.md) | Journal des versions |

---

## Licence

[AGPL-3.0](LICENSE) — logiciel libre, chaque club déploie sa propre instance.  
Toute modification distribuée (y compris via réseau) doit rester sous AGPL-3.0.
