# Installation & déploiement

Guide complet pour installer **Club'O'Clock** — de la copie locale à la mise en production, puis au
premier démarrage réel avec ton club.

> **Modèle one-instance-per-club.** Chaque club déploie **sa propre instance**, avec sa base et son
> `.env`. Il n'y a pas de multi-tenant : pas de compte à créer chez qui que ce soit, pas de données
> partagées entre clubs. Tu es propriétaire de ton installation.

**Ce guide couvre** : prérequis · installation locale · configuration (email, Google OAuth,
notifications push) · premier démarrage · déploiement sur mutualisé puis sur VPS · instance de
démonstration · flux réseau sortants · maintenance · dépannage.

---

## 1. Prérequis

| Besoin | Version | Note |
|---|---|---|
| **PHP** | ≥ 8.3 | Extensions : `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `intl`, `fileinfo`, `zip`, `gd` |
| **MariaDB** ou **MySQL** | MariaDB ≥ 10.6 · MySQL ≥ 8.0 | Moteur **InnoDB** |
| **Composer** | ≥ 2 | Dépendances PHP |
| **Node.js + npm** | ≥ 20 | **Build des assets uniquement** — pas nécessaire sur le serveur (cf. §5) |
| **Chromium (Playwright)** | — | **Tests navigateur en local uniquement**, optionnel : `npx playwright install chromium` (cf. [`tests/E2E/README.md`](https://github.com/stephanfo/club-o-clock/blob/main/tests/E2E/README.md)) |

**Services externes** — tous optionnels sauf l'email :

| Service | Statut | Sans lui |
|---|---|---|
| Fournisseur email transactionnel (Brevo, Scaleway TEM, SMTP…) | **Requis en production** | Aucun lien de connexion, aucune notification ne part |
| Client Google OAuth | Optionnel | Le bouton « Google » reste inopérant, les autres connexions fonctionnent |
| Compte OpenRunner Pro | Optionnel | Les parcours GPX s'affichent, seul l'embed interactif manque |

> **Hébergement.** L'application est conçue pour tenir sur un **mutualisé** (cible de référence :
> OVH Pro). Elle n'exige ni Docker, ni Node en fonctionnement continu, ni WebSocket, ni file
> d'attente Redis — un cron minute et du PHP suffisent.

---

## 2. Installation locale

```bash
git clone https://github.com/stephanfo/club-o-clock.git club-o-clock
cd club-o-clock
composer setup
```

`composer setup` enchaîne : `composer install`, création du `.env`, génération de la clé
applicative, migrations, `storage:link` et build front. **Configure la base dans `.env` avant** de
le lancer, sinon l'étape `migrate` échoue :

```dotenv
DB_CONNECTION=mariadb        # ou "mysql" — voir l'avertissement §5.3
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=club_o_clock
DB_USERNAME=root
DB_PASSWORD=
```

Puis peuple les catalogues métier (catégories d'âge, disciplines, qualifications) et lance le
serveur :

```bash
php artisan db:seed          # CatalogSeeder — indispensable, même en production
php artisan serve            # http://localhost:8000
```

### Jeu de démonstration (facultatif, local uniquement)

Pour explorer l'application remplie — un club fictif, six semaines de séances, tous les cas de
figure (liste d'attente, quotas, mineurs sous tutelle) :

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSeeder
php artisan db:seed --class=GpxRouteSeeder    # bibliothèque de parcours vélo
```

Comptes et scénarios : **[COMPTES_DEMO.md](COMPTES_DEMO.md)**. Mot de passe universel `password`.

> 🚨 **Jamais en production.** `migrate:fresh` **détruit toutes les données**, et le jeu de démo
> crée des comptes au mot de passe public.

---

## 3. Configuration

Les variables décisives. Le `.env.example` documente les autres.

| Variable | Rôle |
|---|---|
| `APP_NAME` | Nom affiché (onglet, emails). ⚠️ Alimente aussi les préfixes de session/cache : le changer **déconnecte** tout le monde |
| `APP_URL` | URL publique — ex. `https://planning.monclub.fr`. Doit être exacte, en `https` en production |
| `APP_ENV` | `local` en dev · `production` en prod |
| `BOOTSTRAP_ADMIN_EMAIL` | **L'email qui deviendra admin** à sa première inscription (cf. §4) |
| `MAIL_MAILER` + `NOTIF_EMAIL_DRIVER` | Les **deux** interrupteurs de l'email — cf. §3.1 |
| `NOTIF_PUSH_DRIVER` + clés `VAPID_*` | Push web — cf. §3.3 |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | Connexion Google — cf. §3.2 |

### 3.1 Email (obligatoire en production)

⚠️ **Deux interrupteurs indépendants, en série.** N'en positionner qu'un est le piège classique :
les messages sont marqués « livrés » et ne partent jamais, sans la moindre erreur.

1. **`MAIL_MAILER`** choisit le *transport*. Tant qu'il vaut `log` (défaut), chaque email est écrit
   dans `storage/logs/laravel.log` sans requête réseau. Pratique en dev (on y lit le lien magique).
2. **`NOTIF_EMAIL_DRIVER`** choisit le *canal des notifications métier* (annulation de séance,
   promotion depuis la liste d'attente, tutelle…). Vide, elles passent en `LogChannel` : marquées
   « livrées » dans l'outbox, **aucun email composé**.

**Le symptôme à connaître** : `MAIL_MAILER=brevo` **sans** `NOTIF_EMAIL_DRIVER` → les liens de
connexion partent (canal `mail` natif), mais **aucune notification métier** n'arrive. L'application
paraît fonctionner ; les adhérents ne reçoivent rien.

```dotenv
MAIL_MAILER=brevo
BREVO_API_KEY=xkeysib-…
MAIL_FROM_ADDRESS="planning@monclub.fr"
MAIL_FROM_NAME="Nom du club"
NOTIF_EMAIL_DRIVER="App\Notifications\Channels\EmailChannel"
```

Pour un SMTP classique (Scaleway TEM et autres), remplacer par `MAIL_MAILER=smtp` et les
`MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` du fournisseur.

> Les notifications métier sont **mises en file** (table outbox) puis expédiées par le drain, toutes
> les 5 min via le cron `schedule:run` (§5.4). **Sans ce cron, rien ne part**, même bien configuré.
> Le lien magique de connexion, lui, part immédiatement.

**Un troisième interrupteur, celui-là en base et non dans l'`.env`** : *Paramètres du club →
Notifications* décide **si** le club envoie sur un canal, là où les variables ci-dessus décident
**avec quoi**. C'est le réglage à utiliser pour couper le push ou l'email — il est réversible depuis
l'interface, tracé dans le journal d'audit, et il coupe proprement : rien n'entre dans l'outbox, et
les envois déjà en file ressortent « Annulée » plutôt que faussement « Envoyée ». Un club sans clés
VAPID a donc intérêt à **couper le push là** plutôt qu'à laisser `NOTIF_PUSH_DRIVER` vide.
Les emails de connexion ne sont jamais concernés.

> **Choix du fournisseur.** Privilégier un service **UE** pour limiter les transferts hors-UE
> (RGPD). Vérifier surtout la délivrabilité : configurer **SPF** et **DKIM** sur le domaine
> d'envoi, faute de quoi les emails du club partent en indésirables.

### 3.2 Google OAuth (optionnel)

Sans client OAuth, le bouton « Google » **n'est pas affiché** sur l'écran de connexion — email +
mot de passe et lien magique restent disponibles. Pour l'activer, créer un client dans la
[Google Cloud Console](https://console.cloud.google.com/apis/credentials) :

1. **Créer les identifiants** → « ID client OAuth » → type **Application Web**.
2. **URI de redirection autorisé** : `${APP_URL}/auth/google/callback`.
3. Reporter dans `.env` :

```dotenv
GOOGLE_CLIENT_ID=…apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=…
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
```

> **Google ne crée jamais de compte.** Le rattachement n'est accepté que si l'email Google est
> *vérifié* **et** correspond à un compte club **déjà vérifié**. Un inconnu qui clique « Google »
> n'entre pas.

**Choisir les moyens de connexion proposés** : *Paramètres du club → Moyens de connexion* active ou
retire le **lien magique** et **Google** de l'écran de connexion. Deux garde-fous :

- **email + mot de passe n'est pas désactivable** — c'est la voie garantie ;
- couper un moyen est **refusé** tant que des comptes actifs n'auraient plus aucun accès. C'est le cas
  courant des comptes créés par invitation ou activation de tutelle : ils sont sans mot de passe et
  sans Google, donc **le lien magique est leur seule porte**. L'écran annonce le nombre de comptes
  concernés avant toute tentative.

Si un club se retrouve malgré tout sans accès (restauration de base, réglage importé), la voie de
secours est `php artisan club:create-admin` (§4) : elle pose un mot de passe, et le login par mot de
passe n'est jamais coupé.

### 3.3 Notifications push (optionnel)

Push web natif **VAPID** : aucun service tiers, aucun flux hors-UE — le navigateur parle
directement à son propre serveur push. Deux prérequis en plus de l'email.

**1. Générer la paire de clés**, propre à l'instance :

```bash
php artisan club:vapid-keys      # affiche subject / public / private à coller dans .env
```

```dotenv
NOTIF_PUSH_DRIVER="App\Notifications\Channels\PushChannel"
VAPID_SUBJECT="mailto:admin@monclub.fr"   # contact imposé par la spec VAPID
VAPID_PUBLIC_KEY=…                        # exposée au front
VAPID_PRIVATE_KEY=…                       # secret — ne jamais exposer ni versionner
```

Mêmes deux interrupteurs en série : driver **sans** clés → `RuntimeException` explicite au premier
envoi ; clés **sans** driver → rien ne part, silencieusement.

**2. Chaque utilisateur s'abonne depuis son appareil** — Profil → Notifications. Contrairement à
l'email (l'adresse est en base), le push exige une souscription **par appareil**. Les abonnements
morts (404/410) sont purgés automatiquement.

> **iOS : limitation Safari.** Le push web n'existe sur iPhone/iPad qu'en **Safari 16.4+** et
> **uniquement PWA installée** sur l'écran d'accueil. Dans un onglet classique, le bouton
> n'apparaît pas. **L'email est le fallback** pour ces adhérents — d'où l'intérêt des deux canaux.

---

## 4. Premier démarrage — checklist club

L'application démarre **vide** : ni club, ni utilisateur, ni séance. Cette séquence l'amène à un
état exploitable. Elle vaut aussi bien en local qu'en production.

**1. Créer le compte administrateur**, en ligne de commande :

```bash
php artisan club:create-admin president@monclub.fr
```

Le prénom, le nom et le mot de passe sont demandés en interactif (ou passés en options :
`--first-name`, `--last-name`, `--password`). Sans argument, la commande reprend l'email de
`BOOTSTRAP_ADMIN_EMAIL` s'il est renseigné dans le `.env`.

> **Pourquoi la ligne de commande ?** Il **n'y a pas d'inscription publique** : les comptes sont
> créés par un admin depuis l'interface, et le lien magique exige un compte existant. Sur une base
> vide, personne ne peut donc entrer — cette commande est le **seul** point d'amorçage.

La commande est **idempotente et sans danger** : lancée sur un email déjà présent, elle ajoute le
rôle `admin` **sans toucher au mot de passe** ni aux rôles existants. Elle **réactive aussi** le
compte s'il avait été désactivé (et marque l'email vérifié) : c'est le moyen de reprendre la main
sur une instance dont l'admin s'est verrouillé dehors — les trois portes d'entrée (mot de passe,
lien magique, Google) exigent un compte actif.

**2. Identité du club** — Admin → Paramètres du club :

- **nom** (affiché partout, y compris comme expéditeur des emails) ;
- **baseline** — laissée vide, l'app affiche celle du produit ; en écrire une propre au club ;
- **logo** (remplace le logo neutre par défaut) ;
- **palette** — trois couleurs (natation, vélo, course). Les déclinaisons et la couleur de texte
  lisible sont calculées automatiquement ;
- **fuseau horaire** et **mois de bascule de saison** (septembre par défaut) — ce mois définit
  l'année sportive : il pilote le calcul des catégories d'âge, le statut mineur et les périodes
  « saison en cours » des statistiques et des journaux ;
- **mentions légales** (bloc dédié) — voir l'encadré en fin de section.

**3. Vérifier les catalogues** — Admin → Catalogues. Les catégories d'âge, disciplines,
qualifications, types d'événement et étiquettes de quota sont pré-remplies selon la **fédération
française de triathlon**. Un club d'un autre sport ou d'une autre fédération **les adapte ici**
(archivage réversible, jamais de suppression : l'historique reste lisible).

**4. Créer les lieux** — Admin → Catalogues → **Lieux** (les lieux sont un catalogue, pas un menu
séparé). Piscine, stade, salle, point de rendez-vous vélo. L'adresse saisie est **géocodée
automatiquement** (OpenStreetMap) : ces coordonnées alimentent la météo de la séance et l'affichage
cartographique.

**5. Créer les modèles de séance** — Admin → Modèles. Un modèle décrit un créneau récurrent (jour,
heure, lieu, discipline, coach, capacité, catégories visées) et **génère les séances réelles**. La
semaine type du club se construit ici.

**6. Générer les séances** sur l'horizon souhaité, depuis chaque modèle.

**7. Créer les adhérents** — Admin → Adhérents → Ajouter. L'admin saisit chaque membre (identité,
date de naissance, rôles). Il n'y a **pas d'email d'invitation automatique** : préviens tes
adhérents que l'application est ouverte, ils se connectent seuls depuis l'écran de connexion en
demandant un **lien magique** à leur adresse. La date de naissance détermine la catégorie d'âge.

Pour un **mineur**, créer d'abord le compte du **représentant légal**, puis rattacher l'enfant en le
désignant comme garant. Trois configurations sont possibles selon l'âge et l'autonomie (enfant sans
compte propre, avec compte propre sous tutelle, ou autonome).

> **Avant d'ouvrir aux adhérents** : compléter les **mentions légales**, dans Admin → Paramètres du
> club → **Mentions légales** (aucun fichier à éditer). Éditeur, hébergeur, directeur de la
> publication et contact RGPD sont obligatoires en France ; la licence AGPL impose en outre
> d'indiquer **où trouver le code source** de l'instance déployée. Tant qu'un champ manque, la page
> publique `/mentions-legales` affiche `[À COMPLÉTER PAR LE CLUB]` et signale qu'elle est
> incomplète.

---

## 5. Déploiement A — hébergement mutualisé (OVH Pro et similaires)

Cible de référence. Le principe tient en une phrase : **on builde le front en local, on ne transfère
que le résultat.**

> ⚠️ Sur mutualisé, `npm install` / `npm run build` ne sont **pas** exécutables en SSH (pas de
> toolchain Node fiable, quotas process et mémoire). En revanche `composer install` et les
> commandes `artisan` — du PHP pur — passent normalement.
>
> Corollaire : **ne jamais copier `node_modules/`** sur le serveur. Vite compile les sources en
> bundles statiques dans `public/build/` — c'est le **seul** livrable front.

### 5.1 Builder le front en local

```bash
npm ci
npm run build      # → public/build/ (+ manifest.json)
```

| Dossier | Généré par | Transfert |
|---|---|---|
| `public/build/` | `npm run build` (**local**) | **SFTP / rsync** |
| `vendor/` | `composer install` (**sur le serveur**) | — régénéré en SSH |

`public/build/` est **gitignoré** volontairement (on ne versionne pas d'artefacts) : il transite
hors Git.

### 5.2 Transférer

Deux voies, au choix. **La voie FTP/SFTP est celle qui a réellement servi à déployer ce projet** ;
elle ne demande qu'un client graphique, ce qui la rend accessible sans ligne de commande.

#### Voie A — SFTP (éprouvée, recommandée)

> **SFTP** (*SSH File Transfer Protocol*, port 22) — c'est le protocole utilisé pour déployer ce
> projet. Il passe **par SSH** : les mêmes identifiants ouvrent le transfert de fichiers **et** la
> ligne de commande du §5.3, indispensable plus bas.
>
> ⚠️ Ne pas confondre avec **FTPS** (FTP + TLS, port 21/990), qui chiffre aussi mais ne transporte
> **que** des fichiers — il faudrait alors un accès SSH séparé. Et jamais de **FTP simple** :
> identifiants et code source y circulent en clair.

Transférer l'arbre du projet, **sauf** ce qui se régénère ou n'appartient pas au serveur :

| À ne **jamais** transférer | Pourquoi |
|---|---|
| `.git/`, `.github/` | Inutile en production, et alourdit le transfert |
| `node_modules/` | Jamais sur le serveur (cf. §5.1) |
| `vendor/` | Régénéré par `composer install` **sur place** |
| `.env` | **Propre au serveur** — l'écraser avec celui du poste casse l'instance (et exposerait tes clés) |
| `storage/` (contenu) | Uploads, journaux et caches **du serveur** : les écraser détruit les fichiers du club |
| `tests/`, `doc/`, `design/` | Sans usage en production |

```bash
# rsync passe par le même canal SSH — la liste d'exclusions ci-dessus, en une commande
rsync -az --delete \
  --exclude='.git/' --exclude='.github/' --exclude='node_modules/' \
  --exclude='vendor/' --exclude='.env' --exclude='storage/' \
  --exclude='tests/' --exclude='doc/' --exclude='design/' \
  ./ <user>@<ssh-host>:<docroot>/
```

Avec un client graphique (FileZilla, Cyberduck, le manager OVH), c'est le même principe : envoyer
l'arbre en désélectionnant ces dossiers.

> 🚨 **`public/build/` est le piège de cette voie.** Il est **gitignoré** et hashé : il faut le
> transférer **à chaque changement front**, et **supprimer d'abord le dossier distant**, sinon les
> anciens bundles s'accumulent indéfiniment. Un `public/build/` périmé ne provoque **aucune erreur** :
> le site s'affiche, mais avec l'ancien CSS/JS. Cf. §10, « le code est déployé mais le style ne suit
> pas ».

#### Voie B — Git (si l'hébergeur expose un dépôt)

```bash
git remote add ovh <ssh-host>:<repo>.git    # une seule fois
git push ovh main

# Les assets buildés ne passent PAS par Git (public/build/ est gitignoré) :
rsync -az --delete public/build/ <user>@<ssh-host>:<docroot>/public/build/
```

> Plus rapide à répéter, mais **ne dispense d'aucune étape du §5.3** — et surtout pas du transfert
> séparé de `public/build/`, que `git push` ignore par construction.

> ⚠️ **Quelle que soit la voie**, `.env` et `storage/` restent la propriété du serveur, et la
> checklist du §5.3 reste obligatoire.

### 5.3 Finaliser côté serveur (SSH — obligatoire)

> 🚨 **Transférer les fichiers ne suffit JAMAIS**, ni par FTP/SFTP, ni par `git push`. Copier le
> code ne joue **pas** les migrations et ne régénère **pas** les caches. Si un déploiement embarque
> une migration et que cette étape est sautée, la production tombe en **500** dès qu'une route lit
> la colonne manquante — après authentification, donc **invisible depuis la page d'accueil**.

> **Cette étape exige une ligne de commande.** C'est le seul point du déploiement qui ne peut pas se
> faire depuis un client graphique : un hébergement sans aucun accès SSH ne convient pas à cette
> application.

**Checklist à dérouler d'un bloc après chaque transfert** (copiable telle quelle) :

```bash
composer install --no-dev --optimize-autoloader   # si vendor/ a changé
php artisan migrate:status                        # repère les migrations « Pending »
php artisan migrate --force                       # ⚠ obligatoire dès qu'une est en attente
[ -e public/storage ] || { rm -f public/storage; ln -s ../storage/app/public public/storage; }  # ⚠ lien RELATIF
php artisan vendor:publish --tag=livewire:assets --force
php artisan optimize:clear                        # purge les caches de l'ancien code
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

> Rien à migrer ? `migrate --force` est un no-op sans risque : le lancer systématiquement coûte
> moins cher qu'une 500 pour une migration oubliée. Idem pour la ligne du lien `public/storage`,
> qui ne fait rien s'il existe déjà.

> ℹ️ **Pourquoi `ln -s` et non `php artisan storage:link` ?** La commande Artisan crée un lien
> **absolu**, que certains hébergements mutualisés refusent de suivre (403, cf. note plus bas).
> Le lien relatif ci-dessus reste dans l'arborescence du site et fonctionne partout. Sur un
> hébergement sans cette contrainte, `php artisan storage:link` convient tout aussi bien.

> ⚠️ **Le lien `public/storage` n'est pas déployé par `git push`.** Il n'est pas versionné (il est
> créé sur le serveur, cf. la ligne `ln -s` ci-dessus). Sans lui, l'upload du logo dans « Paramètres du
> club » réussit, mais l'image répond **404** partout où elle s'affiche : lockup de connexion,
> aperçu admin, filigrane de la topbar. Symptôme trompeur — l'enregistrement est confirmé, seule
> l'image manque.

> ⚠️ **Logo en 404 alors que le lien et le fichier sont corrects ?** Vérifier le `.htaccess`
> **racine** (docroot sur la racine du dépôt). Sa règle défensive `RedirectMatch 404` liste les
> dossiers de sources à ne jamais servir, dont `storage` — or ce mot désigne deux choses :
> le dossier `storage/` du dépôt (logs, cache, GPX hors webroot), à bloquer, et l'URL
> `/storage/` du lien symbolique, par laquelle transite le logo. `RedirectMatch` (mod_alias)
> teste l'URL indépendamment des `RewriteRule`, donc il intercepte les deux. La règle doit
> porter un look-ahead négatif — `RedirectMatch 404 (?i)/storage/(?!logos/)` — exactement comme
> `/vendor/(?!livewire/)`. Diagnostic : `ls -la public/storage` et `namei -l` sont bons, mais
> l'URL répond 404 y compris en contournant la réécriture par `/public/storage/…`.
>
> Cette règle est une **liste blanche** : seul `logos/` est exposé. Les traces GPX ne sont pas
> concernées (disque `local`, hors webroot, servies par une route PHP authentifiée), mais tout
> futur dossier public devra y être ajouté, sous peine du même 404 silencieux.

> ⚠️ **Logo en 403 (et non 404) ?** C'est l'obstacle suivant, distinct : Apache atteint le
> fichier mais refuse de suivre le lien symbolique `public/storage`, qui pointe **hors** de
> l'arborescence du site. Signature : droits et `namei -l` corrects de bout en bout, réponse 403,
> et dans le log d'erreur `AH00037: Symbolic link not allowed or link target not accessible`.
>
> Le correctif est de rendre le lien **relatif**, pour qu'il ne sorte plus du site :
>
> ```bash
> cd <racine>/public && rm storage && ln -s ../storage/app/public storage
> ls -la storage        # doit afficher : storage -> ../storage/app/public
> ```
>
> (`php artisan storage:link --relative` fait la même chose, mais exige le paquet
> `symfony/filesystem`, absent des dépendances — `ln -s` ne demande rien.)
>
> Le lien relatif suffit : inutile de toucher aux `Options` de `public/.htaccess` (vérifié sur
> OVH mutualisé — `+SymLinksIfOwnerMatch` n'était pas nécessaire une fois le lien relatif en place).

Deux pièges spécifiques au mutualisé, à vérifier **une fois** :

> ⚠️ **Dialecte SQL.** Un hébergement mutualisé peut servir **MySQL** derrière un nom d'hôte en
> `*.mysql.db` alors que le développement local tourne sur MariaDB. Vérifier par
> `select version();` et aligner `DB_CONNECTION` (`mysql` ou `mariadb`) dans le `.env` **du
> serveur** — sinon Laravel parle le mauvais dialecte.
>
> ⚠️ **Asset Livewire en statique.** `vendor:publish --tag=livewire:assets` est **obligatoire** :
> servi par une route PHP, `livewire.min.js` casse le framing HTTP/2 de certains hébergeurs (§10).

### 5.4 Le cron (indispensable)

Une seule ligne à configurer dans le manager, **chaque minute** :

```
* * * * * /path/to/php /path/to/artisan schedule:run >> /dev/null 2>&1
```

C'est lui qui cadence **toutes** les tâches internes : drain des notifications (5 min), météo
(horaire), purge des données expirées (quotidien). **Sans ce cron, aucune notification ne part
jamais** — elles s'accumulent silencieusement dans l'outbox. Un cron plus espacé décale ou rate ces
créneaux.

**Vérifier qu'il tourne** — l'écran **Admin → Envois** affiche l'état du traitement automatique :

| Ce qui s'affiche | Signification | Quoi faire |
|---|---|---|
| « traitement automatique actif » (sous le titre) | Le cron est passé il y a moins de 15 min | Rien |
| Bandeau rouge « **Traitement automatique interrompu** » | Aucun passage depuis 15 min ou plus | Le cron ne tourne plus : vérifier la ligne dans le manager, le chemin de `php` et celui d'`artisan` |
| Bandeau bleu « **jamais observé** » | Aucun passage n'a encore été enregistré | Normal pendant les 5 premières minutes après l'installation. Persistant → le cron n'a jamais été mis en place |

Cette surveillance est passive et sans coût : chaque passage du drain laisse un horodatage, et
l'écran en déduit l'état. Un `php artisan cache:clear` remet le voyant à « jamais observé » sans que
ce soit une panne — il se rallume au passage suivant.

> **Pourquoi un voyant plutôt que de regarder la file** : une file vide ne prouve rien. Elle peut
> signifier « tout est parti » comme « le cron est mort et il n'y avait rien à envoyer ». C'est
> précisément le cas où la panne reste invisible jusqu'à la première annulation non notifiée.

---

## 6. Déploiement B — VPS / serveur dédié

Plus de liberté, plus d'administration système à ta charge. Les différences avec le §5 :

**Build sur place possible.** Node étant installé, `npm ci && npm run build` tourne sur le serveur —
plus de transfert séparé de `public/build/`.

**Racine web = `public/`.** C'est le point le plus important : exposer la racine du projet mettrait
`.env` en ligne. Bloc nginx minimal :

```nginx
server {
    listen 443 ssl http2;
    server_name planning.monclub.fr;
    root /var/www/club-o-clock/public;      # ⚠ /public, jamais la racine du projet

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

**Permissions** — le serveur web doit écrire dans deux dossiers, et **nulle part ailleurs** :

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

**Cron** — même ligne unique qu'en §5.4, dans la crontab de l'utilisateur web :

```
* * * * * cd /var/www/club-o-clock && php artisan schedule:run >> /dev/null 2>&1
```

**HTTPS** obligatoire (Let's Encrypt via certbot) : le push web et l'installation PWA l'exigent, et
les liens de connexion transitent par email.

**Stockage.** Les traces GPX et pièces jointes vivent **hors webroot** et sont servies par un
contrôleur PHP : rien à exposer. Le **logo du club** fait exception — c'est un asset public, servi
via le lien `public/storage` (créé sur le serveur — le préférer **relatif**, cf. §5.3). Sans ce
lien, le logo répond 404 partout. Dans les deux cas, `storage/app` est à **inclure dans les
sauvegardes**.

---

## 7. Instance de démonstration (facultatif)

> Cette section ne concerne **pas** un club qui déploie l'application pour ses adhérents. Elle
> décrit comment monter une **vitrine publique** — celle du projet, ou la tienne pour faire
> essayer l'outil à ton bureau avant de vous décider.

Une démo est un déploiement **séparé** (sous-domaine, base et `.env` dédiés), jamais un mode
activé sur l'instance du club. Son écran de connexion **affiche ses propres identifiants** : tout
visiteur y est admin.

```dotenv
DEMO_MODE=true
```

Ce seul drapeau **impose** les garde-fous, il ne se contente pas de les recommander :

| Garde-fou | Effet |
|---|---|
| Transport mail forcé sur `log` | Aucun email ne peut sortir, **même si `MAIL_MAILER=brevo`** et que la clé API est renseignée |
| Canaux de notification neutralisés | Rien n'est poussé ; l'écran des envois reste démontrable |
| `noindex, nofollow` sur toutes les pages | La démo ne pollue pas les moteurs de recherche |
| `demo:reset` déverrouillée | La commande **refuse de démarrer** sans ce drapeau (elle détruit la base) |

**Remise à zéro.** `php artisan demo:reset` reconstruit la base, rejoue le jeu de démonstration et
les parcours GPX, purge les fichiers téléversés **et les journaux**, puis vide les caches. Elle est
planifiée à **04:00** et part donc toute seule avec le cron unique du §5.4 — rien de plus à
installer.

> Les journaux sont purgés pour une raison précise : avec `MAIL_MAILER=log`, le corps **complet**
> de chaque email part dans `storage/logs`, liens magiques et jetons d'invitation compris. Le
> fichier n'est pas atteignable depuis le web, mais sans purge il grossirait indéfiniment sur un
> hébergement à quota. Règle le journal en conséquence : `LOG_LEVEL=warning` (et non `debug`,
> beaucoup trop bavard pour une instance publique) ou `LOG_STACK=daily` pour obtenir une rotation.

```bash
php artisan demo:reset   # à la demande ; refuse si DEMO_MODE n'est pas actif
```

**Deux réglages à faire à la main**, que l'application ne peut pas porter :

1. **`public/robots.txt`** — sur mutualisé c'est un fichier statique, servi sans passer par PHP,
   donc insensible au `.env`. Le remplacer par :
   ```
   User-agent: *
   Disallow: /
   ```
   La balise `noindex` couvre déjà les pages HTML ; ceci ferme le reste.
2. **Taille des téléversements** — les uploads d'une démo ouverte sont à la portée de n'importe
   qui. Sur un mutualisé le `php.ini` n'est pas éditable : la surcharge passe par un
   **`.user.ini`** déposé dans le répertoire racine du sous-domaine (celui qui contient
   `index.php`, donc `public/`) :
   ```ini
   ; public/.user.ini — instance de démonstration UNIQUEMENT (non versionné)
   upload_max_filesize = 6M
   post_max_size       = 8M
   ```
   - **Ne pas descendre sous 6 Mo** : PHP rejette la requête *avant* Laravel, donc un plafond
     inférieur au maximum applicatif (**5 Mo pour un GPX**, `GpxStats::MAX_KB`) ferait échouer un
     upload légitime sur un fichier vide et un message incompréhensible, au lieu du refus lisible
     de la validation. PHP n'est là que comme digue ; c'est l'application qui refuse proprement.
   - **`post_max_size` doit rester au-dessus** de `upload_max_filesize` : l'enveloppe multipart
     porte le fichier **plus** les champs du formulaire. S'il est en dessous, c'est la requête
     entière qui est jetée.
   - **Compter ~5 minutes** avant l'effet : PHP-FPM met `.user.ini` en cache
     (`user_ini.cache_ttl`, 300 s par défaut).
   - **Vérifier côté web** (`phpinfo()` temporaire), pas en SSH : la CLI a sa propre configuration
     et n'applique pas `.user.ini` — `php -i` y afficherait une valeur trompeuse.

   ⚠️ Ce fichier ne doit **jamais être versionné** : chaque club hériterait du plafond d'une
   démo. Il se pose à la main sur le déploiement, comme `robots.txt` (`.user.ini` est dans le
   `.gitignore` pour cette raison).

> ⚠️ **Ne jamais pointer une démo sur la base d'un club.** `demo:reset` commence par un
> `migrate:fresh` : elle détruit tout. Le refus hors `DEMO_MODE` protège l'instance du club, pas
> une démo mal branchée.

---

## 8. Flux réseau sortants (pour les mentions légales)

Pour rédiger sa page mentions légales / confidentialité (une trame est fournie sur
`/mentions-legales`), chaque club doit savoir quels services tiers son instance contacte. Voici
l'inventaire complet — tous UE ou sans flux de données personnelles identifiables :

| Service | Déclenché quand | Donnée transmise | UE / clé |
|---|---|---|---|
| **Open-Meteo** | Rafraîchissement météo (cron, J+16) pour chaque lieu de séance | Coordonnées GPS du lieu (pas de donnée personnelle) | UE, gratuit, sans clé |
| **Nominatim / OpenStreetMap** | Un admin saisit ou modifie l'adresse d'un `Location` | Texte de l'adresse saisie | UE, gratuit, sans clé, 1 req/s (mis en cache) |
| **Email transactionnel** (Brevo par défaut, Scaleway TEM en alternative) | Notification, lien de connexion (magic link), invitation | Email + nom du destinataire, contenu du message | UE, clé API |
| **Web Push (VAPID)** | Notification poussée à un appareil abonné | Payload chiffré vers l'endpoint du navigateur (Chrome/Firefox/Apple selon appareil) | Protocole standard, pas de service commercial intermédiaire côté club |
| **Google OAuth** (optionnel) | Connexion via le bouton « Google », si activé | Email + identité du compte Google de l'utilisateur | **Hors UE** (Google) — désactivable en laissant `GOOGLE_CLIENT_ID` vide |
| **OpenRunner Pro** (optionnel) | Affichage d'un parcours ayant un embed OpenRunner configuré | Chargement d'un iframe depuis OpenRunner | Self-hosting du club requis (cf. PRD) |

Pas d'analytics, pas de traqueur, pas de CDN tiers. Les polices web (Manrope) sont
**auto-hébergées** : aucun appel à Google Fonts.

---

## 9. Maintenance & sauvegardes

**Ce qu'il faut sauvegarder** — deux choses seulement :

| Quoi | Pourquoi |
|---|---|
| **La base de données** | Toutes les données du club |
| **`storage/app/`** | Fichiers téléversés (logo, pièces jointes) |

Le reste (`vendor/`, `public/build/`, `node_modules/`) se régénère depuis le dépôt.

```bash
mysqldump -u <user> -p <base> > sauvegarde-$(date +%F).sql
tar czf storage-$(date +%F).tar.gz storage/app
```

> Les sauvegardes automatiques d'un mutualisé (souvent : base quotidienne, fichiers J-14) couvrent
> l'incident courant. Prévoir en plus un **export froid mensuel téléchargé hors hébergeur** — une
> sauvegarde qui vit uniquement chez le fournisseur ne protège pas de la perte du compte.

### 9.1 Répétition de restauration — à faire **avant** la mise en production

Une sauvegarde jamais restaurée n'est pas une sauvegarde, c'est une hypothèse. La restauration se
répète **une fois à vide**, pendant que la base ne contient encore que des données de démonstration :
le jour d'un incident réel, le geste est déjà connu et le doute porte sur l'incident, pas sur l'outil.

Le principe : restaurer dans une base **neuve et séparée**, jamais par-dessus la base de production.

```bash
# 1. Sauvegarder (identique à la commande ci-dessus)
mysqldump -u <user> -p <base> > sauvegarde-$(date +%F).sql

# 2. Créer une base de restauration DISTINCTE
mysql -u <user> -p -e "CREATE DATABASE <base>_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. Restaurer dedans
mysql -u <user> -p <base>_restore < sauvegarde-$(date +%F).sql

# 4. Vérifier que l'application démarre dessus — aucune migration ne doit être en attente
DB_DATABASE=<base>_restore php artisan migrate:status

# 5. Supprimer la base de répétition
mysql -u <user> -p -e "DROP DATABASE <base>_restore;"
```

**Ce qu'il faut contrôler à l'étape 4**, au-delà du fait que les tables sont là :

| Contrôle | Requête | Attendu |
|---|---|---|
| Clés étrangères | `SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema='<base>_restore' AND constraint_type='FOREIGN KEY';` | identique à la source |
| Index | `SELECT COUNT(DISTINCT table_name,index_name) FROM information_schema.statistics WHERE table_schema='<base>_restore';` | identique à la source |
| Moteur | `SELECT DISTINCT engine FROM information_schema.tables WHERE table_schema='<base>_restore';` | `InnoDB` partout |
| Migrations | `php artisan migrate:status` | aucune ligne `Pending` |

Les clés étrangères sont le contrôle qui compte : un dump produit avec de mauvaises options restaure
des données d'apparence correcte **sans les contraintes**, et le défaut ne se voit qu'au premier
enregistrement incohérent, des semaines plus tard.

> **Ne jamais mettre le mot de passe dans la ligne de commande** (`-p<motdepasse>`) : il se retrouve
> dans l'historique du shell et dans la liste des processus, visible des autres comptes du serveur.
> Utiliser `-p` seul (saisie interactive), ou un fichier d'options en `chmod 600` :
>
> ```bash
> printf '[client]\nuser=<user>\npassword=<motdepasse>\n' > ~/.my-restore.cnf && chmod 600 ~/.my-restore.cnf
> mysql --defaults-extra-file=~/.my-restore.cnf <base>_restore < sauvegarde.sql
> rm ~/.my-restore.cnf
> ```

**Répétition effectuée sur le jeu de démonstration** (40 adhérents, 74 séances, 89 inscriptions) :
dump de 257 Ko en ~1 s, restauration en < 1 s, 38 tables / 55 clés étrangères / 117 index identiques,
aucune migration en attente, et lecture applicative vérifiée (rôles, relations, calcul de quota).
Sur un club réel, l'ordre de grandeur reste la seconde — ce n'est pas une opération à redouter.

**Ne pas oublier `storage/app/`** : la base restaurée référence des fichiers (logo, parcours GPX) qui
n'y sont pas. Une restauration complète, c'est la base **et** l'archive `storage`.

**Mise à jour** : `git pull` puis la checklist du §5.3 (ou §6). Consulter le
[CHANGELOG](../CHANGELOG.md) avant, en particulier pour les notes de version majeure.

**Commandes utiles** :

```bash
php artisan club:vapid-keys        # (re)générer les clés push
php artisan notifications:drain    # forcer l'envoi de la file
php artisan weather:refresh        # rafraîchir le cache météo
php artisan optimize:clear         # purger tous les caches
composer check                     # pint + phpstan + tests (avant contribution)
```

---

## 10. Dépannage

### Les notifications ne partent pas

Le symptôme le plus fréquent, et presque toujours l'une de ces trois causes :

| Vérifier | Commande / indice |
|---|---|
| **Le cron tourne** | Admin → Envois : un bandeau rouge « Traitement automatique interrompu » répond directement (§5.4). Sinon, tester `php artisan notifications:drain` à la main |
| **`NOTIF_EMAIL_DRIVER` est renseigné** | Vide → tout est marqué « livré » sans qu'aucun email ne parte (§3.1) |
| **`MAIL_MAILER` ≠ `log`** | En `log`, les emails vont dans `storage/logs/laravel.log` |

### Erreur 500 après un déploiement

Dans la quasi-totalité des cas : **une migration non jouée** ou **un cache périmé**.

```bash
php artisan migrate:status        # des « Pending » ?
php artisan migrate --force
php artisan optimize:clear
```

Le détail est dans `storage/logs/laravel.log` — une `QueryException` sur une colonne inconnue
confirme la migration manquante.

### Livewire ne charge pas (page figée, boutons inertes)

Trois causes distinctes, à diagnostiquer par le **message exact** dans la console du navigateur :

| Symptôme console | Cause | Correctif |
|---|---|---|
| Asset en `http://` bloqué (mixed content) | Le proxy TLS ne transmet pas `X-Forwarded-Proto` : Laravel se croit en http | Vérifier `APP_URL=https://…` dans le `.env` du serveur (`forceScheme` est déjà dans le code) |
| `net::ERR_HTTP2_PROTOCOL_ERROR` | Livewire sert le JS par une route PHP ; le HTTP/2 de l'hébergeur casse le framing | `php artisan vendor:publish --tag=livewire:assets --force` |
| `404` sur `/vendor/livewire/livewire.min.js` | Un `.htaccess` bloque `/vendor/` défensivement | `RedirectMatch 404 (?i)/vendor/(?!livewire/)` (déjà dans le code fourni) |

Après correctif : `php artisan config:clear && php artisan config:cache`, puis **rechargement forcé**
(Cmd/Ctrl + Shift + R).

> ⚠️ Un `.htaccess` cassé met **tout le site** à terre sur mutualisé : toujours tester le
> chargement d'une page **avant** de considérer une modification comme acquise.

### Le code est déployé mais le style ne suit pas (bouton mal placé, élément non stylé)

**Le symptôme classique d'un `public/build/` périmé.** `public/build/` est **gitignoré** : un
`git push` déploie le PHP et les vues, **jamais** les bundles. Si l'étape rsync/SFTP du §5.2 est
oubliée, le serveur rend le HTML du nouveau code avec le CSS de l'ancien — un élément récemment
ajouté sort alors sans aucun style, y compris sans les règles qui devaient le **masquer**.

C'est un échec **silencieux** : aucune erreur, aucun 404, la page se charge normalement. Même
mécanique que la perte des données géo d'un GPX face à un bundle JS périmé.

Le contrôle tient en une commande — le hash servi doit être celui du dernier `npm run build` :

```bash
curl -sS -A "Mozilla/5.0" https://<domaine>/login | grep -oE '/build/assets/app-[^"]+\.css'
ls public/build/assets/app-*.css        # doit donner le même nom
```

> À faire **après chaque déploiement touchant au style**. Si les noms diffèrent, rejouer le
> transfert du §5.2, puis `php artisan optimize:clear` (le cache de vues garde les Blade compilés
> de l'ancien code).

### `curl` répond 403 alors que le site marche dans le navigateur

**Ce n'est pas une panne.** Certains hébergeurs mutualisés (OVH notamment) filtrent les requêtes
sur le **User-Agent** : un `curl` nu est pris pour un robot et reçoit un `403 Forbidden` d'Apache,
avec un corps HTML générique et aucun en-tête applicatif.

Le piège est qu'il ressemble trait pour trait à une vraie panne de configuration : le 403 tombe
sur **tout**, y compris les fichiers statiques et les chemins **inexistants** (là où on attendrait
un 404), ce qui suggère à tort une racine de site mal pointée ou des permissions cassées.

```bash
curl -sS -o /dev/null -w "%{http_code}\n" https://…            # 403 — trompeur
curl -sS -o /dev/null -w "%{http_code}\n" -A "Mozilla/5.0 …" https://…  # 302/200 — la réalité
```

> **Règle** : croire le navigateur avant l'outil. Avant de conclure à une panne serveur depuis un
> script, rejouer la requête avec un User-Agent de navigateur.

### Le bouton « Google » ne fait rien

Client OAuth non configuré (§3.2), ou URI de redirection ne correspondant pas **exactement** à
`${APP_URL}/auth/google/callback`.

### Le push ne fonctionne pas sur iPhone

Comportement attendu hors **Safari 16.4+ avec PWA installée** sur l'écran d'accueil (§3.3).
L'email prend le relais.

---

## Aller plus loin

- **[README](https://github.com/stephanfo/club-o-clock/blob/main/README.md)** — présentation, architecture, flux réseau sortants
- **[COMPTES_DEMO.md](COMPTES_DEMO.md)** — comptes et scénarios du jeu de démonstration
- **[CONTRIBUTING.md](../CONTRIBUTING.md)** — contribuer au projet
- **[SECURITY.md](../SECURITY.md)** — signaler une vulnérabilité
