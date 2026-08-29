# Poste de développement en conteneurs

Faire tourner l'application, la porte de qualité et le harnais navigateur **sans rien installer sur
sa machine** : ni PHP et ses dix extensions, ni MySQL, ni les navigateurs de Playwright.

> **Ceci ne concerne que le poste de développement.** La cible de déploiement de Club'O'Clock reste
> l'**hébergement mutualisé sans Docker** ([cadrage technique](CADRAGE_TECHNIQUE.md), [INSTALL §1](INSTALL.md)) :
> un cron minute et du PHP suffisent à servir l'application. Rien de ce qui suit n'est un prérequis
> d'exécution, et rien ne doit le devenir. Docker est ici un **outil de poste**, au même titre qu'un
> éditeur de texte — il évite d'installer une pile de développement complète, pas de faire tourner
> le service.

L'alternative classique reste valable : installer PHP, MySQL et Node sur la machine et suivre
[INSTALL §2](INSTALL.md). Ce document décrit le chemin conteneurisé, pour qui préfère garder sa
machine propre.

---

## 1. Ce qu'on évite d'installer

| Sans conteneur, il faut… | Fourni ici par |
|---|---|
| PHP 8.4 + `gd zip intl bcmath gmp pdo_mysql exif` | `cluboclock-php:8.4` |
| Composer, un client MySQL | `cluboclock-php:8.4` |
| Un serveur MySQL 8.4 | l'image officielle `mysql:8.4` |
| Chromium, Firefox, WebKit et leurs dépendances système (~1,5 Go) | `cluboclock-e2e:latest` |

**Ce qui reste sur la machine** : **Git**, **Docker**, et **Node + npm**. Node ne sert qu'au build
front (`npm run build`), et il doit tourner **sur la machine** : `node_modules` contient des binaires
compilés pour son système, qu'un conteneur Linux ne sait pas exécuter.

Les deux images sont décrites par [`docker/php.Dockerfile`](https://github.com/stephanfo/club-o-clock/blob/main/docker/php.Dockerfile)
et [`docker/e2e.Dockerfile`](https://github.com/stephanfo/club-o-clock/blob/main/docker/e2e.Dockerfile) —
elles sont versionnées, donc reconstructibles à l'identique.

---

## 2. Mise en place

### 2.1 Construire les images

```bash
docker build -f docker/php.Dockerfile -t cluboclock-php:8.4 .
docker build -f docker/e2e.Dockerfile -t cluboclock-e2e:latest .
```

La seconde télécharge l'image Playwright officielle : ~3,9 Go, quelques minutes la première fois.
Elle n'est nécessaire que pour les tests navigateur — on peut la remettre à plus tard.

### 2.2 Un réseau, pour que les conteneurs se voient par leur nom

```bash
docker network create cluboclock
```

Indispensable : sur le réseau `bridge` par défaut, Docker **ne résout pas** les noms de conteneurs.
C'est ce réseau qui fait que `DB_HOST=cluboclock-mysql` fonctionne.

### 2.3 La base

```bash
docker run -d --name cluboclock-mysql --network cluboclock \
  -e MYSQL_ROOT_PASSWORD='<le même mot de passe que DB_PASSWORD dans .env>' \
  -e MYSQL_DATABASE=cluboclock \
  -v cluboclock-db:/var/lib/mysql \
  -p 3307:3306 \
  mysql:8.4
```

- **`-p 3307:3306`** — le port 3306 de la machine est souvent déjà pris par un MySQL installé, ou
  par un autre projet. Le `.env` du poste pointe donc sur `DB_HOST=127.0.0.1` / `DB_PORT=3307` : c'est
  l'adresse vue **depuis la machine**, pour un client SQL graphique ou un `php artisan` lancé hors
  conteneur.
- **`-v cluboclock-db:/var/lib/mysql`** — volume **nommé**. Sans lui, Docker en crée un anonyme et
  `docker rm cluboclock-mysql` emporte la base avec le conteneur.

### 2.4 L'application

```bash
docker run -d --name cluboclock-app --network cluboclock \
  -v "$PWD":/app -w /app \
  -e DB_HOST=cluboclock-mysql -e DB_PORT=3306 \
  -e MAIL_MAILER=log \
  -e 'NOTIF_EMAIL_DRIVER=App\Notifications\Channels\LogChannel' \
  -e 'NOTIF_PUSH_DRIVER=App\Notifications\Channels\LogChannel' \
  -p 8000:8000 \
  cluboclock-php:8.4 \
  php artisan serve --host=0.0.0.0 --port=8000 --no-reload
```

L'application répond alors sur **`http://localhost:8000`**.

Quatre points méritent une explication.

**`-v "$PWD":/app`** — le code n'est **pas copié dans l'image**, il est monté. Un `git checkout`, une
édition de vue, une modification de contrôleur sont visibles immédiatement, sans reconstruire quoi
que ce soit. C'est aussi pour ça que les captures E2E et les journaux atterrissent bien dans le
dépôt et non dans un conteneur.

**`DB_HOST` et `DB_PORT` surchargés** — le `.env` dit `127.0.0.1:3307`, ce qui est vrai **depuis la
machine** et faux **depuis le conteneur**, où `127.0.0.1` désigne le conteneur lui-même. Les deux
variables d'environnement corrigent l'adresse sans toucher au `.env` : le même fichier sert donc aux
deux points de vue.

**`--no-reload` — obligatoire, et pas pour la raison qu'on croit.** Ce n'est pas un réglage de
confort : c'est lui qui fait que les variables ci-dessus **atteignent réellement le serveur HTTP**.

Sans ce drapeau, `artisan serve` surveille le `.env` pour se relancer quand il change — et, pour ne
pas figer un environnement obsolète dans le processus fils, il **filtre les variables transmises**
au travers d'une courte liste blanche (`APP_ENV`, `PATH`, quelques variables Xdebug et Herd). Tout
le reste est mis à `false`. `DB_HOST`, `MAIL_MAILER` et les deux `NOTIF_*_DRIVER` n'y figurent pas :
le serveur retombe alors sur les valeurs du `.env`.

Constaté, pas supposé : le même conteneur lancé **sans** `--no-reload` répond **HTTP 500** — il
cherche la base sur le `127.0.0.1:3307` du `.env`, qui à l'intérieur du conteneur ne désigne rien.
Le même mécanisme emporte `MAIL_MAILER` : le garde-fou d'envoi tomberait avec la connexion, en
silence celui-là. **Ne pas retirer ce drapeau.**

**Les trois variables de notification : le garde-fou à ne pas retirer.**

> ⚠️ Le `.env` du poste peut contenir une **vraie clé d'API email** — celle qui sert aux essais
> d'envoi réels. Le jeu de démonstration, lui, contient des dizaines d'adresses fictives, et un
> scénario de test peut déclencher un envoi de masse (annulation de séance, promotion de liste
> d'attente). Les trois variables ci-dessus forcent l'email et le push à n'être qu'**écrits dans le
> journal**. Elles sont posées **sur le conteneur**, jamais dans le `.env` — le fichier reste
> exactement tel que son auteur l'a écrit, et c'est l'environnement d'exécution qui décide.
>
> Le drapeau `--no-reload` fait partie du garde-fou (voir juste au-dessus) : sans lui, ces trois
> variables n'atteignent pas le serveur HTTP et le `.env` reprend la main.
>
> Vérifier que le garde-fou tient :
> ```bash
> docker exec cluboclock-app php artisan tinker --execute="
>   echo config('mail.default'), PHP_EOL;
>   echo config('club.notifications.channels.email'), PHP_EOL;
>   echo config('club.notifications.channels.push'), PHP_EOL;"
> ```
> Attendu : `log`, puis deux fois `App\Notifications\Channels\LogChannel`.

### 2.5 Le schéma et le jeu de démonstration

```bash
docker exec cluboclock-app php artisan migrate:fresh
docker exec cluboclock-app php artisan db:seed --class=CatalogSeeder
docker exec cluboclock-app php artisan db:seed --class=DemoSeeder
```

Les comptes créés sont décrits dans [Comptes de démonstration](COMPTES_DEMO.md). Ne **pas** rejouer
`DemoSeeder` seul sur une base déjà peuplée : il est additif sur les séances et les dupliquerait.

### 2.6 La base de tests

`phpunit.xml` fait tourner la suite sur une base **séparée**, `cluboclock_test`, qu'il faut créer une
fois :

```bash
docker exec cluboclock-mysql sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" \
  -e "CREATE DATABASE IF NOT EXISTS cluboclock_test"'
```

C'est ce cloisonnement qui rend `composer check` inoffensif pour le jeu de démonstration : même le
groupe `destructif`, qui reconstruit tout le schéma, ne touche jamais `cluboclock`.

---

## 3. Travailler au quotidien

Toute commande du dépôt se joue par `docker exec` :

```bash
docker exec cluboclock-app php artisan migrate
docker exec cluboclock-app php artisan tinker
docker exec cluboclock-app composer check          # la porte de qualité, en entier (base cluboclock_test)
docker exec cluboclock-app vendor/bin/pint         # style seul
docker exec cluboclock-app vendor/bin/phpunit --filter=Quota
```

Pour une session interactive : `docker exec -it cluboclock-app bash`.

**Le build front reste sur la machine** — `npm run build`, jamais dans un conteneur (cf. §1). Le
garde-fou `front:check-drift` refuse de valider un bundle qui ne correspond plus aux sources, et il
fait partie de `composer check` : un oubli de build fait rougir la porte de qualité, il n'atteint pas
la production ([INSTALL §5.1](INSTALL.md)).

> ⚠️ **`schema:dump` ne se joue PAS dans ce conteneur pour le dump MySQL.** Sur Debian, le paquet
> `default-mysql-client` installe en réalité le client **MariaDB** : `mysqldump --version` y répond
> `from 11.8.6-MariaDB`. Lancé depuis l'image contre un serveur MySQL 8.4, `schema:dump` produit
> alors un `mysql-schema.sql` en dialecte MariaDB — la première ligne devient
> `/*M!999999\- enable the sandbox mode */`, que MySQL ne sait pas lire —, et l'intégration continue
> échoue à recharger le schéma. Chaque dump se régénère **sur son propre moteur, avec le client de ce
> moteur** ([CONTRIBUTING](../CONTRIBUTING.md)) : ce conteneur convient au dump **MariaDB**, pas au
> dump **MySQL**, qui demande une installation PHP disposant du vrai client Oracle.

---

## 4. Les tests navigateur (E2E)

```bash
docker run --rm \
  --network container:cluboclock-app \
  -v "$PWD":/app -w /app \
  -e DB_HOST=cluboclock-mysql -e DB_PORT=3306 \
  -e MAIL_MAILER=log \
  -e 'NOTIF_EMAIL_DRIVER=App\Notifications\Channels\LogChannel' \
  -e 'NOTIF_PUSH_DRIVER=App\Notifications\Channels\LogChannel' \
  cluboclock-e2e:latest node tests/E2E/run.mjs
```

Les captures atterrissent dans `tests/E2E/shots/` (non versionné) grâce au montage — **les regarder**
fait partie du travail dès qu'une modification touche l'interface.

### `--network container:` et non `--network cluboclock`

C'est le point non évident. [`tests/E2E/lib.mjs`](https://github.com/stephanfo/club-o-clock/blob/main/tests/E2E/lib.mjs)
code l'adresse en dur :

```js
export const BASE = 'http://127.0.0.1:8000';
```

`--network container:cluboclock-app` fait **partager au conteneur de test la pile réseau du conteneur
applicatif** : ils ont la même `127.0.0.1`, donc `BASE` tombe juste sans qu'on ait à paramétrer le
harnais ni à toucher au code pour les besoins de son propre outillage. Avec un simple
`--network cluboclock`, `127.0.0.1:8000` ne désignerait que le conteneur de test, vide, et tous les
scénarios échoueraient au premier `page.goto`.

Effet de bord à connaître : un conteneur qui partage une pile réseau **ne peut pas** publier de port
ni rejoindre un autre réseau. D'où la surcharge `DB_HOST=cluboclock-mysql` — la base reste joignable,
puisque la pile partagée est déjà raccordée au réseau `cluboclock`.

### Pourquoi PHP est dans l'image de test

Le harnais ne fait pas que cliquer : il ouvre les sessions par magic link (`php auth.php`) et vérifie
l'état **en base** après chaque action (`php sql.php`). Sans PHP dans la **même** image que Node, le
premier `session()` échoue. C'est la raison d'être de la couche `php8.3-cli` de
[`docker/e2e.Dockerfile`](https://github.com/stephanfo/club-o-clock/blob/main/docker/e2e.Dockerfile).

Les deux scripts refusent de s'exécuter si `APP_ENV != local` — un garde-fou de plus, indépendant du
conteneur.

### Garder Playwright et son image alignés

L'image est figée sur `mcr.microsoft.com/playwright:v1.62.1-noble`, et le `package.json` sur
`playwright ^1.62.1`. Playwright **refuse de piloter** des navigateurs dont la révision ne correspond
pas à celle qu'il attend : après une montée de version du paquet, **reconstruire l'image** avec la
balise correspondante, sinon le harnais s'arrête au lancement du navigateur.

Le paquet `playwright` du `node_modules` monté est du JavaScript pur, donc utilisable depuis le
conteneur ; les navigateurs, eux, viennent de l'image (`PLAYWRIGHT_BROWSERS_PATH=/ms-playwright`),
jamais du cache de la machine.

### Rejouer en conditions de production

Le poste tourne en `APP_DEBUG=true`, la production en `false` — et ce n'est pas cosmétique : Livewire
sert son bundle **non minifié** dans le premier cas, **minifié** dans le second, et une erreur
JavaScript n'y porte alors ni le même message ni la même trace. Pour rejouer le harnais dans les
conditions du serveur, sans toucher au conteneur de travail :

```bash
docker run -d --name cluboclock-app-prod --network cluboclock \
  -v "$PWD":/app -w /app \
  -e DB_HOST=cluboclock-mysql -e DB_PORT=3306 -e APP_DEBUG=false \
  -e MAIL_MAILER=log \
  -e 'NOTIF_EMAIL_DRIVER=App\Notifications\Channels\LogChannel' \
  -e 'NOTIF_PUSH_DRIVER=App\Notifications\Channels\LogChannel' \
  cluboclock-php:8.4 php artisan serve --host=0.0.0.0 --port=8000 --no-reload
```

Pas de `-p` : ce conteneur n'a pas à être joignable depuis la machine. On lui adosse le harnais avec
`--network container:cluboclock-app-prod` au lieu de `cluboclock-app`. `APP_ENV` reste `local`, sans
quoi `auth.php` et `sql.php` refusent de s'exécuter.

**Garder `APP_DEBUG=false` hors du conteneur de travail** : en `false`, Laravel remplace la page
d'erreur détaillée par un 500 muet, ce qui rend le développement pénible.

### Scénarios destructifs

```bash
docker run --rm --network container:cluboclock-app -v "$PWD":/app -w /app \
  -e DB_HOST=cluboclock-mysql -e DB_PORT=3306 \
  -e MAIL_MAILER=log \
  -e 'NOTIF_EMAIL_DRIVER=App\Notifications\Channels\LogChannel' \
  -e 'NOTIF_PUSH_DRIVER=App\Notifications\Channels\LogChannel' \
  cluboclock-e2e:latest node tests/E2E/destructif.mjs --oui-je-sais
```

Ils **reconstruisent la base** en fin d'exécution. Conventions détaillées dans
[`tests/E2E/README.md`](https://github.com/stephanfo/club-o-clock/blob/main/tests/E2E/README.md).

---

## 5. Ce que le conteneur ne remplace pas

- **Le build front** — `npm run build` sur la machine (§1).
- **Safari.** Le harnais pilote Chromium, et l'image ne fournit que le WebKit **de Linux**. Des
  défauts de rendu propres à Safari sur macOS ou iOS y échappent : c'est arrivé sur une modale dont
  la hauteur ne se résolvait que sur WebKit. Ces vérifications-là restent manuelles, sur un vrai
  Safari.
- **Les tests PWA, offline et push** — appareil réel requis ([PLAN_TESTS](PLAN_TESTS.md) §9).
- **La production.** Rien de ce document ne s'applique à un serveur : le déploiement se fait par
  transfert de fichiers sur un mutualisé ([INSTALL §5](INSTALL.md)).

---

## 6. Arrêter, reprendre, repartir de zéro

```bash
docker stop cluboclock-app cluboclock-mysql      # libère le port 8000, garde tout
docker start cluboclock-mysql cluboclock-app     # reprendre plus tard
docker logs -f cluboclock-app                    # journal du serveur PHP
```

Rebâtir une base propre — la remise en état d'un scénario E2E interrompu, par exemple :

```bash
docker exec cluboclock-app php artisan migrate:fresh
docker exec cluboclock-app php artisan db:seed --class=CatalogSeeder
docker exec cluboclock-app php artisan db:seed --class=DemoSeeder
```

Tout supprimer, y compris les données :

```bash
docker rm -f cluboclock-app cluboclock-mysql
docker volume rm cluboclock-db
docker network rm cluboclock
```

### Petits dépannages

| Symptôme | Cause probable |
|---|---|
| `SQLSTATE[HY000] [2002] Connection refused` depuis le conteneur | `DB_HOST`/`DB_PORT` non surchargés : le conteneur lit le `127.0.0.1:3307` du `.env` (§2.4) |
| `getaddrinfo … cluboclock-mysql` | conteneurs pas sur le réseau `cluboclock`, ou réseau absent (§2.2) |
| E2E : `net::ERR_CONNECTION_REFUSED` sur `127.0.0.1:8000` | `--network container:cluboclock-app` oublié (§4) |
| E2E : `Executable doesn't exist` / révision de navigateur | image et paquet `playwright` désalignés (§4) |
| HTTP 500 dès la page d'accueil, journaux muets côté conteneur | `--no-reload` oublié : les variables passées à `docker run` n'atteignent pas le serveur (§2.4) |
| Port 8000 ou 3307 déjà pris | changer la partie gauche du `-p`, et `DB_PORT` du `.env` pour le second |
