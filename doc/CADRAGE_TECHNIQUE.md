# Cadrage technique — Application de gestion du planning d'entraînement

**Statut : cadrage validé, stack en production.** Ce document compare les options envisagées et
justifie la cible retenue — Laravel, Blade + Livewire + Alpine, MariaDB/MySQL sur hébergement
mutualisé. Il est conservé tel qu'il a servi à décider : c'est un **instantané d'arbitrage**, utile
pour comprendre *pourquoi* la stack est celle-là, et non une description de l'état livré.

> **Ce qui a été construit** se lit dans le code et dans [INSTALL.md](INSTALL.md) ; **ce que le
> produit doit faire**, dans le [PRD](PRD.md), qui prime en cas de divergence — à l'exception des
> **assouplissements ops** listés ici (§8 et §11), arbitrés pour tenir compte du contexte « club
> bénévole, hébergement mutualisé ».
>
> Quelques renvois pointent vers des documents de travail **non publiés** (feuille de route interne,
> maquettes M0, bundle `design/`) : ils appartiennent à l'historique de fabrication et ne sont pas
> résolvables depuis ce dépôt.

---

## 1. Contexte et cadre de décision

### 1.1 Le projet en bref
PWA de gestion du planning d'entraînement d'un club de triathlon (~50-150 adhérents), maintenue par
un **bénévole solo**, distribuée en **AGPL-3.0** sur un modèle **one-instance-per-club** (chaque club
déploie sa propre instance — jamais de multi-tenant). Le périmètre fonctionnel V1 est figé.

### 1.2 Arbitrages déjà tranchés (non rediscutés ici)
| Sujet | Décision |
|---|---|
| **Hébergement disponible** | OVH mutualisé **« Pro »** (PHP/MySQL ; ressources dédiées 1 vCore / 2 Go RAM ; SSH + git intégré ; 250 Go SSD ; 10 bases MariaDB × 2 Go). |
| **Backend** | **PHP sur le mutualisé en priorité** (capitaliser sur l'hébergement déjà payé). |
| **Frontend** | **Application monolithique Laravel** : vues **Blade** rendues serveur + **Livewire** pour l'interactivité + îlots **Alpine.js** pour le pur client (cf. §6.2). Le design Claude Design (exporté en React/JSX, §6.6) sert de **référence visuelle à porter en Blade** : les CSS tokens sont réutilisés tels quels, le markup est retraduit (effort mécanique, design figé). La familiarité du mainteneur n'est pas un critère : c'est un agent de code qui implémente. |
| **Firebase** | Indifférent → pesé objectivement (RGPD / AGPL / lock-in), pas un prérequis. |
| **Posture ops** | **Pragmatique** : assouplir les NFR ops lourdes (PITR, preview-par-PR, monitoring infra) en compromis **documentés**, pas les ignorer. |

### 1.3 Capacités et limites du mutualisé OVH « Pro » (factuel)
Cette contrainte structure tout le reste du document. Caractéristiques de l'offre **Pro**
(source : page de comparaison OVHcloud) :

| Disponible | Indisponible |
|---|---|
| **PHP** (versions récentes sélectionnables, dont 8.x) | Accès **root** |
| **MariaDB / MySQL** InnoDB — **10 bases × 2 Go** (transactions, verrous) | **Docker** / conteneurs |
| **Ressources dédiées : 1 vCore / 2 Go RAM** | **Node long-running** / processus daemon / worker permanent |
| **Tâches cron** planifiables (**horaire max, minute imposée** — cf. §7.13) | **WebSocket** serveur / SSE long-running |
| **SSH** + multi-SSH · **FTP/SFTP** | Redis / file de messages persistante managée |
| **Git intégré** (déploiement par `git`) | Scheduler sub-minute, crons en très grand nombre |
| **250 Go SSD** (NAS) — confortable pour le stockage objets | PostgreSQL |
| HTTP/2, SSL gratuit illimité, sauvegardes auto OVH, trafic illimité | PITR maîtrisé / replica managé |

L'offre Pro lève deux limites supposées initialement : **git est intégré** (déploiement plus propre
que FTP, cf. §11) et **les ressources CPU/RAM sont dédiées** (perf < 2s/4G plus crédible, §8.9). Les
limites **structurantes** subsistent : pas de runtime applicatif persistant (Node/daemon/worker),
pas de Docker, pas de WebSocket serveur → une **application Laravel rendue serveur** (Blade + Livewire),
servie par PHP/FPM sur le mutualisé, est la voie naturelle. Tout mode **SSR Node (Nuxt/Next) reste
écarté**, et une **SPA JS découplée (React/Vite) n'est plus retenue** (cf. §6.2) : monolithe PHP =
un seul artefact à déployer, pas de runtime Node, pas d'API REST ni d'auth cross-origin à maintenir.

> **À confirmer dans le manager OVH au moment de l'init** : version PHP active (cibler 8.2+), version
> exacte de MariaDB (la sérialisation atomique §4.9.5 du PRD suppose InnoDB + `SELECT … FOR UPDATE`),
> et **fréquence minimale des crons** (**une fois par heure**, minute non choisissable — d'où la boucle
> interne de §7.13 et INSTALL §5.4).

### 1.4 Méthode
Exigences PRD → implications techniques (§4) → comparatif d'options (§6) → décisions sur les points
« à arbitrer » de la feuille de route (§7) → tensions & compromis (§8) → matrice de décision (§9) →
recommandation (§10).

---

## 2. Architecture cible en une vue

```
                            ┌───────────────────────────────────────────────┐
   Navigateur (PWA)         │         Mutualisé OVH « Pro » (UE, Paris)     │
 ┌────────────────────┐     │  ┌─────────────────────────────────────────┐  │
 │ Blade rendu serveur│     │  │  Application Laravel (monolithe PHP)    │  │
 │ + Livewire (interac)│◄──►│  │  - Blade + Livewire + contrôle d'accès  │  │
 │ + îlots Alpine.js  │ HTTPS  │  - auth (MDP / magic link / Google OIDC)│  │
 │ - service worker   │     │  │  - sérialisation inscriptions (InnoDB)  │  │
 │ - WYSIWYG (îlot JS)│     │  │  - compteur quota                       │  │
 │ - parsing GPX (JS) │     │  └───────┬───────────────┬─────────────────┘  │
 │ - carte OSM (JS)   │     │          │               │                    │
 │ - iframe OpenRunner│     │   ┌──────▼─────┐   ┌──────▼────────────────┐  │
           │                │   │ MySQL/Maria│   │ Filesystem objets     │  │
           │                │   │ (InnoDB)   │   │ (hors webroot)        │  │
           │                │   └────────────┘   └───────────────────────┘  │
           │                │   ┌────────────────────────────────────────┐  │
           │                │   │ CRON unique → scheduler framework :    │  │
           │                │   │ météo J-16 · drain outbox              │  │
           │                │   │ (backups natifs OVH · reset = manuel)  │  │
           │                │   └────────────────────────────────────────┘  │
           │                └───────────────────────────────────────────────┘
           │
           ▼  services externes (appelés en API / embed côté client)
 ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────────┐
 │ Email UE     │ │ Push VAPID   │ │ Open-Meteo   │ │ Google OIDC      │
 │ (Brevo /     │ │ (Web Push    │ │ (météo,      │ │ (client OAuth    │
 │  Scaleway)   │ │  standard)   │ │  cache 3h)   │ │  propre au club) │
 └──────────────┘ └──────────────┘ └──────────────┘ └──────────────────┘
        + OpenRunner Pro (embed iframe, 100% côté client) · OSM/Nominatim (géocodage)
```

Principe directeur : **tout ce qui peut être servi par le mutualisé déjà payé l'est** ; on ne sort
vers un service externe que là où le mutualisé ne peut pas (email transactic UE, météo) ou ne doit
pas (calcul lourd → reporté côté client, ex. parsing GPX).

---

## 3. Le moteur de persistance

Le modèle conceptuel du PRD (§5) compte ~17 entités relationnelles (`User`, `Category`, `Session`
à discriminator `kind`, `Registration`, `SessionTemplate`, `QuotaTag`, `Discipline`, `Qualification`,
`Location`, `AuditLog`, `ActivityLog`, `AperoFlag`, `UserCategory`, `UserQualification`, …) avec de
nombreuses relations **M:N** et des contraintes d'intégrité fortes (unicité `(sessionId, userId)`,
pas de chevauchement de catégories, FK immuables).

**Décision : SGBD relationnel = MySQL/MariaDB (InnoDB)**, déjà fourni par le mutualisé et hébergé UE.

- ✅ Aligné avec un modèle fortement relationnel + transactions sérialisables (§4.9.5 PRD).
- ✅ Gratuit, déjà payé, UE de fait, migrations versionnées triviales avec un framework PHP.
- ❌ **NoSQL document / Firestore écartés** : inadaptés au relationnel fort, et Firestore hébergé
  hors-UE par défaut + lock-in propriétaire (conflit AGPL / one-instance-per-club).
- ❌ **PostgreSQL écarté** : non disponible sur l'offre OVH mutualisée retenue (sinon préférable sur
  certains points ; à reconsidérer uniquement en cas de migration VPS, cf. §13).

---

## 4. Exigences PRD → implications techniques

Lecture : *énoncé PRD → implication → où c'est tranché*. Les exigences sont regroupées par familles.

### 4.1 PWA, offline, performance (PRD §6)
- PWA installable (manifest + service worker), **offline en lecture** du planning de la semaine,
  **chargement < 2s sur 4G** → pages **Blade rendues serveur**, **service worker maison** (Workbox ou
  natif) en **cache-first** sur l'app-shell et les pages du planning de la semaine courante, manifest
  statique, HTML/CSS/JS minifiés et compressés. La PWA dépend de la *plateforme web* (manifest + SW +
  HTTPS), **pas d'un build SPA** (cf. §6.6). → §6.2 (frontend), §8 (perf).
- **Ouverture des liens dans l'app installée.** Le manifest déclare `id` (identité stable — **à ne
  jamais changer** une fois livré, sous peine de faire apparaître une seconde app installée),
  `handle_links: preferred` et `launch_handler: navigate-existing`, et `notificationclick` réutilise
  une fenêtre existante en comparant les **chemins** (l'égalité stricte d'URL ne matchait quasiment
  jamais). **Chromium uniquement** : Safari n'implémente ni l'un ni l'autre. Sur iOS, une PWA
  installée possède un **pot de cookies distinct** de Safari — un lien cliqué dans Mail ouvre la
  session dans Safari et laisse la PWA déconnectée. Aucun réglage de manifest n'y remédie ; c'est le
  rôle du **code à usage unique** joint au magic link (PRD §4.1.1). Le service worker est enregistré
  par les layouts `app` **et** `guest` : sans quoi un visiteur qui installe l'app depuis l'écran de
  connexion n'a aucun SW avant sa première page authentifiée.

### 4.2 Souveraineté UE / RGPD / AGPL / self-hosting (PRD §1.4, §6)
- **Hébergement UE strict** (Paris/Francfort), données + backups en UE → mutualisé OVH FR + services
  tiers UE uniquement. → §6.3.
- **AGPL-3.0, reproductible par un autre club** → éviter les dépendances à lock-in propriétaire ;
  prérequis self-hosting minimaux et documentés. → §6.5, §12.
- **Pas de multi-tenant, jamais** (pas de `tenant_id`) → une instance = un club. → transverse.

### 4.3 Authentification & contrôle d'accès (PRD §4.1, §5.2, §6)
- 3 méthodes : **email+MDP** (reset lien TTL 15 min), **magic link** (passwordless, TTL 15 min,
  usage unique), **Google OAuth/OIDC** (chaque club configure **son propre** client, stocke
  **email + name** seulement). Linking multi-méthodes sur email vérifié.
- **Découpage des briques d'auth** (revue 16 juin 2026) :
  - **Laravel Fortify** (backend headless, compatible Livewire) pour MDP + reset + vérification email + throttle + hashing — pas de réécriture maison de ces primitives.
  - **Laravel Socialite** = client OAuth **seul** pour Google (échange OIDC → `email + name + sub`). Ce n'est **pas** un provider d'identité externe (cf. §6.5 Firebase Auth écarté).
  - **Maison** : magic link (token hashé, TTL 15 min, usage unique) + table `auth_identities`. Cette table ne stocke **que les providers OAuth externes** (Google `sub`), **pas** `password` (= présence `users.password`) ni `magic_link` (= dispo sur tout email vérifié). L'écran « méthodes liées » (PRD §4.1) **compose** ces sources.
- **Activation par instance (PRD §4.17)** : `App\Services\AuthMethodService` possède la règle « ce
  moyen est-il ouvert ? », lue depuis `ClubSettings` (`auth_magic_link_enabled`, `auth_google_enabled`).
  Google exige **deux** conditions : l'interrupteur ET un `services.google.client_id` renseigné —
  sinon le bouton ne mène qu'à une erreur Google. Le MDP n'a pas d'interrupteur (voie garantie ;
  `club:create-admin` reste l'échappatoire de dernier recours).
  - **Toutes les surfaces sont gardées, pas seulement l'affichage** : les 3 méthodes de
    `MagicLinkController` — dont `consume()`, **avant** `MagicLink::consume()`, sinon un refus brûlerait
    un token à usage unique que la réactivation pourrait encore honorer — et `redirect()` **plus**
    `callback()` d'`OAuthController` (le callback est une URL publique : le garder seulement à l'entrée
    laisserait la coupure contournable par un appel direct). Un moyen fermé répond **404**, comme un
    provider inconnu : un message dédié renseignerait sur la configuration de l'instance.
  - **Garde de non-verrouillage** : `AuthMethodService::lockedOutBy()` compte les comptes actifs qui
    n'auraient plus **aucun** accès sous un état cible d'interrupteurs, et la coupure est refusée s'il
    en reste. L'enjeu est concret : les comptes créés par invitation ou activation de tutelle (§4.2.1)
    sont *passwordless* et sans identité OAuth — le magic link est leur seule porte. Le même service
    corrige le garde-fou de révocation du profil, qui tenait `users.email` pour un accès valide en
    supposant le magic link toujours disponible. Le pendant **individuel** de cette règle,
    `keepsAnotherWayIn()`, vit dans le même service : révoquer une identité Google et retirer son mot
    de passe posent la même question, elle n'a pas à être réécrite par surface.
- **Invitation d'activation (PRD §4.1.3)** : `App\Services\InvitationService` frappe le jeton
  (`invitation_tokens`, hash sha256, TTL `ClubSettings.invitation_link_days`) pour les **deux**
  origines — adhérent créé par le bureau et mineur autonomisé (§4.2.1) —, qui partagent la route
  `invitation/{token}` et l'écran d'accueil `/bienvenue`.
  - Le jeton est **consommé au GET**, pas dans le composant Livewire qui suit : un écran monté sur le
    jeton le porterait en clair dans le DOM et dans chacun de ses payloads (historique, cache,
    capture). Le coût — un onglet fermé trop tôt brûle le lien — se paie quand l'adhérent est déjà
    connecté et son email vérifié.
  - `InvitationToken::prunable()` n'élague **que** les jetons expirés non consommés. Le jeton consommé
    est conservé comme **marqueur d'activation** durable, ce qui évite une colonne `activated_at` (donc
    une migration) et permet à l'invitation de masse de ne solliciter personne deux fois. Sans coût de
    minimisation : la table ne porte aucune donnée personnelle, contrairement à `magic_link_tokens`.
  - **Cadence d'envoi** : geste unitaire → `OutboxDrainer::drainNow()` ; import CSV et action de masse
    → mise en file, drainée par le cron. 200 envois SMTP synchrones dans une requête, c'est 40 à 100 s
    et un timeout sur mutualisé. L'outbox **est** la file (backoff, tentatives, rejeu, écran admin) —
    une file Laravel exigerait un worker long-running, écarté ici.
- **Secrets dans l'outbox** : un jeton d'activation voyage en clair dans `notification_outbox.payload`
  jusqu'à l'envoi. `OutboxDrainer` le retire au passage en `sent` (`SENSITIVE_PAYLOAD_KEYS`), et le
  tiroir admin n'affiche jamais que `redactedPayload()`. **Pas de purge sur `failed`** : ces lignes
  restent rejouables, les vider produirait un lien mort. `club:prune-tokens` rattrape l'historique.
- **Code à usage unique (PRD §4.1.1)** : deux colonnes sur `magic_link_tokens` (`code_hash`,
  `code_attempts`), **pas de table séparée** — le code et le lien sont les deux faces d'une même
  autorisation (même destinataire, même TTL, même `consumed_at`). Une table à part rendrait possible
  l'état incohérent « lien consommé, code encore vivant ».
  - 6 chiffres ≈ **20 bits** : le format n'est acceptable que parce que TOUS les contrôles suivants
    sont réunis. `random_int` (CSPRNG) ; stockage en **HMAC-SHA256 clé APP_KEY** — un sha256 nu de
    6 chiffres se renverse en microsecondes, donc un dump de base livrerait les codes en clair ;
    `hash_equals` ; **compteur par jeton** (5 essais, puis le jeton est brûlé → 5·10⁻⁶) ; **double
    limiteur** 5/min par (email+IP) *et* 10/10 min par IP seule — le compteur par jeton ne voit pas
    un attaquant qui essaie un code sur mille adresses, il n'épuise celui d'aucune ; TTL 15 min ;
    `UPDATE` conditionnel atomique pour l'usage unique.
  - **L'email est obligatoire à la saisie** et sert de clé de recherche. Sans lui, un attaquant
    testerait 10⁶ combinaisons contre *tous* les jetons vivants simultanément : la probabilité de
    toucher quelqu'un croîtrait avec le nombre d'adhérents au lieu de rester bornée par jeton.
    Corollaire testé : un mauvais email **n'incrémente pas** le compteur de la victime, sinon on
    offrirait un déni de service.
  - **Écran dédié**, pas de 3ᵉ onglet sur l'écran de connexion : le code n'est pas une troisième
    méthode mais la seconde moitié du lien magique, et il ne veut rien dire tant qu'aucun code n'a
    été demandé. Accessoirement, le segment de connexion est un toggle CSS à deux radios dupliqué
    dans les deux coquilles — un troisième libellé en capitales y déborde à 360 px, sur l'écran le
    plus critique de l'application.
  - **Pas de code sur l'invitation à 30 jours** : à entropie égale, la fenêtre d'exposition serait
    2880 fois plus grande, et le verrouillage à 5 essais deviendrait une arme (invalider en boucle
    les invitations d'une promotion entière). Le besoin n'existe pas non plus — le cas iOS suppose
    une PWA *déjà installée*, ce qui n'est pas vrai à la première activation. *Le code compense un
    défaut de contexte de session, pas un défaut de possession du lien ; il n'a de sens que sur un
    jeton court.*
- **Politique de mot de passe** : `Password::defaults()` = longueur seule (10). Pas de règles de
  composition (contraires aux recommandations ANSSI/NIST), pas de `uncompromised()` — l'appel HIBP est
  un aller-retour réseau sortant qui ajoute de la latence sur mutualisé et **échoue ouvert** en cas de
  coupure : coût réel, garantie nulle. TTL du reset aligné sur le magic link (15 min).
  Un `PasswordReset` supprime **les lignes de session** de l'utilisateur : Fortify ne régénère que
  `remember_token`, ce qui laissait vivre une session déjà ouverte — soit exactement ce que le reset
  est censé clore.
- **Contrôle d'accès côté backend** (pas seulement client) ; règle clé : un **parent garant** lit/écrit
  les `Registration` de ses enfants (P1/P2). → l'auth et l'autorisation **vivent dans le backend PHP**
  (cf. §6.5 : Firebase Auth écarté). → §7.3, §7.4.

### 4.4 Cœur métier critique (PRD §4.9.5, §4.10, §6)
- **Sérialisation atomique** des inscriptions sur la dernière place (premier = `participating`,
  second = `waitlist capacity`, **jamais de double-acceptation**), garantie par tests E2E.
- **Quota fair-share** évalué en **temps quasi-constant** à l'inscription.
- → réalisables nativement sur InnoDB (`SELECT … FOR UPDATE` + transaction). → §7.1, §7.2.
  **Détail figé §14.1** : verrou sur la **ligne `sessions`** + `COUNT` live (pas de compteur matérialisé) ;
  quota + capacité + rang FIFO évalués dans **une même transaction sous le même verrou**.

### 4.5 Contenu & médias (PRD §4.12, §4.13)
- **Stockage objets** : pièce jointe séance ≤ 5 Mo (PDF/PNG/JPG/WebP), GPX ≤ 5 Mo, logo ≤ 1 Mo, icône PWA ≤ 1 Mo (PNG seul, cf. §7.16).
- **WYSIWYG sanitisé** (gras/italique/barré/listes/liens/h2-h3/citation), **stocké en markdown**, anti-XSS. **Même pipeline** pour `contentMarkdown` (séance `training`), `agenda` (`club_event`) **et `Debrief.contentMarkdown`** (débrief de compétition, PRD §4.12.5) — un seul éditeur, une seule sanitisation serveur.
- **Débrief de compétition** (`Debrief`, PRD §4.12.5) : table InnoDB, FK `sessions` + `users`, **index unique `(session_id, author_id)`**, `archived_at` nullable (**soft-delete**, même pattern que `SessionTemplate` / `Location` / `Qualification`, §7.9). Autorisation **serveur** (§6.5, jamais client) : create/edit par l'auteur **si** `Registration` `participating` **et** `now() >= session.start_at` ; edit aussi par l'admin ; archive/réactivation **admin seul**. Volumétrie négligeable : lecture indexée par `session_id`.
- **`photosAlbumUrl`** (PRD §4.12.6) : colonne nullable sur `sessions`. Validation **serveur** = URL bien formée à **schéma `https` whitelisté** (rejet `javascript:` / `data:` / `http:`). Pas de validation du domaine (Google Photos non imposé), **aucun appel serveur** vers l'URL, rendu en lien `target="_blank" rel="noopener noreferrer"`.
- **GPX parsé côté client uniquement** ; tracé sur fond **OpenStreetMap**.
- **OpenRunner Pro** : embed iframe (validation **whitelist stricte** de l'URL, régénération de
  l'iframe côté client, jamais de HTML brut stocké). **Open-Meteo** : cache serveur 3h + pré-calcul.
- → §6.4 (objets, monitoring), §7.5–§7.8.

### 4.6 Notifications (PRD §4.15)
- **Push web** (VAPID, PWA installée, limite iOS Safari 16.4+ acceptée) + **email transactionnel UE**.
- Matrice de préférences type × canal + pause globale. **Temps réel non requis** (souhaitable seul.). Le **type « nouveau débrief »** (PRD §4.12.5) emprunte la même file `outbox` que les autres notifs (publication seule, pas de renotif à l'édition).
- → §6.3 (email/push), §8 (outbox/cron, temps réel).

### 4.7 Traitements planifiés (PRD §4.5, §4.13.5)
- **Pré-calcul météo** fenêtre J-16 + **drain de l'`outbox`** notifications → **un cron unique** pilotant
  le scheduler du framework (inventaire exhaustif en §7.13). Le **reset annuel des surclassements** est
  une **action admin manuelle** (pas un cron, §7.9) ; les **sauvegardes** sont natives OVH (§8.2). → §7.13, §7.14, §8.

### 4.8 Export XLSX (PRD §4.16.2, §4.18.5)
- Dashboard stats + journaux exportés en **XLSX** (pas CSV), formatage FR. → lib PHP serveur. → §7.11.

### 4.9 Plateforme d'ingénierie (PRD §6)
- **CI** sur chaque PR (lint, type-check, tests unit + E2E, couverture > 80 %), **preview par PR**
  (assouplissable), **déploiement prod manuel**, garde-fou branche principale.
- **Backups** RPO 7j (PITR ou équivalent) + export froid rétention 1 an.
- **Monitoring** erreurs + logs centralisés. **Migrations versionnées** + seed reproductible.
- → §8 (compromis ops), §11.

### 4.10 Transverses
- **Français uniquement V1** (pas d'i18n prématuré). **Pas de multi-tenant.** → conventions de code.

---

## 5. Vocabulaire et services tiers (rappel des garde-fous)

Conformément à [CLAUDE.md](https://github.com/stephanfo/club-o-clock/blob/main/CLAUDE.md) :
- Statut d'inscription **`participating` | `waitlist` | `cancelled`** (uniforme sur les 3 `kind`).
- Récurrence par **générateur `SessionTemplate`** (pas de RRULE/EXDATE).
- Seuls tiers **nommés** tolérés comme choix produit/légal : **OpenRunner Pro** (embed) et
  **Open-Meteo** (météo). Les autres services tiers ci-dessous (email, push, monitoring, géocodage)
  sont des **choix de stack** arbitrés dans ce document.

---

## 6. Comparatif des options

Le backend PHP/mutualisé et le SGBD relationnel étant actés (§1, §3), les options portent sur
4 axes. Les candidats sont volontairement **peu nombreux** (décision pour un mainteneur solo).

### 6.1 Backend — framework PHP : **Laravel** vs **Symfony**

PHP nu / micro-framework (Slim…) **écarté** : le PRD exige migrations versionnées, ORM relationnel
riche, scheduler, files/différé, scaffolding d'auth, tooling de test — un framework full-stack
amortit ce coût pour un solo.

| Critère | Laravel | Symfony |
|---|---|---|
| Productivité solo (auth, migrations+seeders, scheduler, mail, files) | ++ batteries incluses | + modulaire mais verbeux |
| ORM relationnel + verrous/transactions | + Eloquent (`lockForUpdate`) | ++ Doctrine (rigueur, UoW) |
| Écosystème push / XLSX / tests | ++ très fourni | + fourni |
| Compatibilité mutualisé PHP/MySQL | + (config FPM/FastCGI) | + (idem) |
| Familiarité / courbe pour un bénévole | ++ | 0 |
| Lock-in | aucun (OSS) | aucun (OSS) |

→ **Recommandé : Laravel.** Symfony reste une **alternative crédible** si le mainteneur le connaît mieux.

### 6.2 Frontend : **monolithe Laravel (Blade + Livewire + Alpine)** retenu — vs SPA découplée (React/Vue)

Deux architectures frontend sont envisageables sur le mutualisé (toutes deux sans runtime Node, le
SSR Nuxt/Next étant exclu, §1.3) :

- **A — SPA JS découplée** (React/Vue + Vite, buildée en assets statiques) consommant une **API REST**
  Laravel. C'était la piste du cadrage initial, justifiée par un design exporté en React/JSX.
- **B — Monolithe Laravel** : pages **Blade** rendues serveur, **Livewire** pour l'interactivité
  (composants serveur réactifs sans écrire d'API ni de JS), **Alpine.js** pour les micro-interactions
  100 % client. **Retenu.**

Le critère discriminant n'est **pas la familiarité du mainteneur** (c'est un agent de code qui
implémente) mais l'**adéquation au profil du projet** : **mainteneur bénévole solo**, **~50-150
adhérents** (charge faible), **online assumé** (l'app suppose une connexion ; l'offline se limite à la
consultation en lecture + shell installable, cf. §4.1), **one-instance-per-club** (un seul artefact à
déployer par fork).

| Critère (profil solo / petit volume / online assumé) | **B · Laravel Blade+Livewire** | A · SPA React/Vue + API |
|---|---|---|
| Surface de code (1 langage, 1 codebase) | ✅ une app PHP, zéro API REST à écrire | ⚠️ 2 codebases (front JS + API PHP), types/validation dupliqués |
| Déploiement (one-instance-per-club) | ✅ un seul artefact, `git push` OVH | ⚠️ build front + déploiement API séparés |
| Auth / autorisation (règle parent/enfant) | ✅ sessions serveur natives, gardes Laravel | ⚠️ auth cross-origin (token/CORS) à câbler |
| État serveur ↔ client | ✅ Livewire tient l'état côté serveur, pas de sync manuelle | ⚠️ gestion d'état client + cache API à maintenir |
| Cœur métier (quota, sérialisation, validation) | ✅ au plus près d'Eloquent/InnoDB, aucun aller-retour API | ✅ côté API (mais re-validation front fréquente) |
| Interactivité riche (calendrier, formulaires à branches) | ✅ Livewire + Alpine couvrent ; îlot JS ciblé si besoin | ✅ point fort SPA |
| PWA + offline-lecture (§4.1) | ✅ SW maison sur pages Blade (manifest + cache-first) | ✅ vite-plugin-pwa (mais bénéfice nul, online assumé) |
| Réutilisation du design Claude Design (React/JSX) | ⚠️ markup à porter en Blade (CSS tokens réutilisés tels quels — coût mécanique borné, §6.6) | ✅ composants React quasi tels quels |
| Pérennité / reprenabilité | ✅ écosystème Laravel mûr et stable ; PHP omniprésent | ✅ React/Vue très répandus |

- **SPA React/Vue (A) écartée** : son seul avantage net pour ce projet — réutiliser le design React
  sans le traduire — est **borné** (le coût de portage Blade est mécanique : CSS tokens réutilisés,
  markup retraduit, logique métier à écrire serveur dans les **deux** cas, cf. §6.6). Face à lui, la SPA
  impose **en permanence** une 2e codebase, une API REST, l'auth cross-origin et la synchro d'état —
  surcoût récurrent injustifié à cette échelle (solo, ~50-150 users, online assumé).
- **SSR Nuxt/Next écarté** : Node long-running indisponible sur le mutualisé (§1.3).
- → **Recommandé : monolithe Laravel — Blade + Livewire 3 + Alpine.js 3.** Vélocité solo maximale, un
  seul artefact, pas d'API ni de sync d'état, cœur métier au plus près de la base. L'interactivité
  attendue (filtres planning, onglets, formulaires de création, modales `kind`-spécifiques) est couverte
  par Livewire (round-trip serveur, acceptable en online assumé) et Alpine (micro-interactions client).

Briques frontend associées (toutes **client**, montées comme **îlots JS/Alpine** dans des vues Blade —
indépendantes de la coquille serveur) :
- **WYSIWYG** : éditeur client de la famille **ProseMirror/TipTap** restreint aux marques autorisées
  (gras, italique, barré, listes, liens, h2/h3, citation), **export markdown**. La **sanitisation serveur PHP fait foi**. Réutilisé tel quel pour `contentMarkdown`, `agenda` et `Debrief.contentMarkdown` (PRD §4.12.5).
- **Cartes** : lib de cartographie côté client (**Leaflet**) sur fond **OpenStreetMap**, **chargée à la demande** (import dynamique, pas en tête de bundle). Mutualisée entre le **tracé GPX** (`gpxMap`) et l'**aperçu de lieu** (`locationMap`, un marqueur) — ce dernier sert au formulaire Lieux (recentré en direct via l'event Livewire `location-located`) et au bloc « Lieu » de la fiche séance (§4.13.4).
- **Parsing GPX** : lib JS dédiée, **côté client uniquement** (le serveur ne stocke que fichier +
  métadonnées validées).

> **Style / Tailwind.** Le design livré repose sur du **CSS pur à variables** (`club-tokens.css` +
> `club-app.css`, couche composants déjà codée). La recommandation est de **conserver ce CSS tel quel**
> (réutilisation directe, cohérence avec le handoff §6.6). Tailwind reste **optionnel** et, s'il est
> introduit, en **mode utilities mappées sur les tokens** pour le one-off de layout — **en complément**
> du CSS livré, jamais en remplacement (qui imposerait de reconstruire la couche composants en `@apply`).

### 6.3 Services tiers (par fonction)

| Fonction | Recommandé | Alternatives | Pourquoi |
|---|---|---|---|
| **Email transactionnel UE** | Brevo (FR) | Scaleway TEM (FR), Mailjet (FR) | Serveurs UE, API/SMTP simple depuis PHP, offre gratuite/faible coût. Prérequis self-hosting documenté. |
| **Push web** | **VAPID natif** (`web-push-php`) | FCM (Firebase) — non recommandé | Standard ouvert, **aucun lock-in**, pas de flux hors-UE ; couvre Chrome/Firefox/Edge + **Safari 16.4+** (limite iOS déjà acceptée par le PRD). |
| **Stockage objets** | **Filesystem hors webroot** | S3-compatible UE (Scaleway/OVH Object Storage) | Volumes faibles (≤ 5 Mo/fichier, ~50-150 users) → le filesystem suffit ; servi par contrôleur PHP avec contrôle d'accès. S3 UE = option si le volume croît. |
| **Monitoring / erreurs** | Sentry (région UE) | Filet minimal : log fichier rotaté + alerte mail cron | SDK PHP + JS, erreurs front/back. RGPD : choisir la région UE. |
| **Géocodage** | Nominatim / OSM | Service ouvert UE | Gratuit, UE ; respecter l'usage policy. Usage principal serveur (`GeocodingService::search()`) : **autocomplétion** adresse/POI, ≤ 5 résultats **structurés** (nom + adresse formatée depuis le bloc `address` + type lisible, pas le `display_name` brut), **debounce serveur Livewire 400 ms + ≥ 4 caractères**, cache 6 h, `addressdetails=1`, `accept-language=fr`. La sélection d'une suggestion remplit tous les champs (nom/adresse/type/coords) → **plus de bouton « géocoder » manuel** dans l'UI ; `geocode()` (1 résultat, cache 30 j) reste disponible côté service (météo, scripts). **Aucun appel navigateur** (User-Agent identifiable côté serveur, conforme à la politique 1 req/s). Fallback : **saisie lat/lng manuelle** dans les champs (PRD §4.13.4). |
| **Météo** | **Open-Meteo** (figé PRD) | — | Gratuit, sans clé, UE, CC BY 4.0. Cache serveur 3h + pré-calcul cron J-16. |

### 6.4 Répartition des responsabilités mutualisé ↔ externe

| Responsabilité | Où |
|---|---|
| Auth (MDP / magic link / Google OIDC), autorisation, règles parent/enfant | **Backend PHP** |
| Persistance relationnelle, transactions, compteur quota | **MySQL/MariaDB** (mutualisé) |
| Stockage objets (PJ, GPX, logo) | **Filesystem** (mutualisé, hors webroot) |
| Crons (météo J-16, drain outbox — cf. §7.13) | **Cron mutualisé → scheduler framework** |
| Envoi email | **Service email UE** (API/SMTP) |
| Envoi push | **PHP → endpoints Web Push** (VAPID) |
| Météo | **Open-Meteo** (fetch serveur, mis en cache) |
| Carte / parcours OpenRunner / parsing GPX | **Client** (navigateur) |
| Génération XLSX | **Backend PHP** |
| Monitoring | **SDK applicatif → service UE** |

### 6.5 Place de Firebase

| Brique Firebase | Verdict | Motif |
|---|---|---|
| **Authentication** | ❌ **Écarté** | Lock-in fort, flux d'identité hors-UE, conflit avec le client Google **par club** + le linking multi-méthodes + magic link maison ; le **contrôle d'accès doit vivre côté PHP** (règle parent/enfant, RBAC). |
| **Firestore / RTDB** | ❌ **Écarté** | Modèle fortement relationnel + transactions sérialisables → SQL ; hors-UE par défaut + lock-in. |
| **Hosting / Functions** | ❌ **Écarté** | On capitalise sur le mutualisé déjà payé ; aucun besoin de Functions. |
| **Cloud Messaging (FCM)** | ⚠️ **Mentionné, non recommandé** | Voie la plus simple pour le push multi-navigateurs, mais **VAPID natif couvre déjà le besoin** (dont Safari 16.4+) sans lock-in ni flux hors-UE. |

**Conclusion : le compte Firebase du porteur n'est PAS un prérequis de l'architecture retenue** — un
prérequis self-hosting de moins pour les clubs qui forkeront.

### 6.6 Design de référence — bundle Claude Design

Les maquettes du projet existent déjà : produites avec **Claude Design** (claude.ai/design) puis
exportées en **bundle de handoff** destiné à un agent de code. Ce bundle est la **source visuelle de
vérité** pour l'implémentation du frontend.

- **Format** : **React 18 (JSX)** + **CSS pur à variables** (design tokens). Pas de Tailwind, pas de
  CSS-in-JS.
- **Contenu** (archivé hors du dépôt public, cf. encadré ci-dessous) : ~5900 lignes JSX réparties
  par écran (`screen-home`, `screen-planning`, `screen-fiche`, `screen-creation`, `screen-admin`,
  `screen-parent`, `screen-coach`, `screen-alerts`, `screen-profil`…), composants partagés
  (`ui.jsx` : `Logo`, `Avatar`, `AperoFlag`… · `modals.jsx` · `shell.jsx` · `icons.jsx`), les deux
  feuilles de style à l'origine de [`club-tokens.css`](../resources/css/club-tokens.css) et
  [`club-app.css`](../resources/css/club-app.css) (palette d'origine vert/magenta/bleu, typo
  Archivo/Manrope), variantes **mobile + desktop**, 48 captures, et 2 transcripts de chat décrivant
  l'intention.
- **Couverture fonctionnelle déjà maquettée** : switch de rôle athlète/coach/parent/admin, planning,
  fiche séance (dont apéro, parcours, encadrement), création de séance, écrans admin, UI parent.

> ⚠️ **Le bundle n'est pas distribué avec le dépôt public** : il porte le branding du club d'origine
> et n'a plus de valeur d'usage une fois le portage fait — le design system livré
> (`resources/css/`) en est le résultat et se suffit à lui-même. Les chemins `design/…` cités
> ci-dessous désignent l'archive privée ; ils ne sont pas résolvables depuis ce dépôt.

**Implications pour la stack (frontend porté en Blade, §6.2)** :
1. **Le format React du bundle n'est pas la stack cible** : il sert de **référence visuelle**, pas de
   code de prod. La stack retenue est le monolithe Laravel (Blade + Livewire + Alpine, §6.2). Le markup
   JSX est **porté en Blade** ; c'est un travail **mécanique** (`className`→`class`, `{expr}`→`{{ }}`,
   `.map()`→`@foreach`, `useState` local→`wire:model` Livewire ou `x-data` Alpine), le design étant
   **figé** et documenté (captures + `design/docs/04-ecrans.md`) — on traduit, on ne reconçoit pas.
2. **Les CSS tokens sont réutilisés tels quels** (agnostiques du framework) : `club-tokens.css` et
   `club-app.css` (couche composants `.btn`/`.scard`/`.chip`…) **copiés quasi à l'identique** → c'est
   l'actif lourd du design, il survit intégralement. Cohérence visuelle garantie. C'est ce qui **borne**
   le coût de portage (cf. §6.2 : le différentiel face à une SPA React est le portage du markup, pas le
   design system ni la logique métier).
3. **Le proto est volontairement *mocké*** (state local via Babel/UMD en CDN, données simulées dans
   `design/prototype-source/src/data.jsx`, aucun appel réseau). **Toute la logique métier** (quotas,
   FIFO waitlist, role-flip, RGPD) est à **implémenter pour de vrai côté serveur** — travail **commun à
   toute stack**, donc neutre dans le choix Blade vs SPA. **Lire les transcripts de chat d'abord**
   (l'intention y vit, cf. README du bundle).
4. **La PWA ne dépend pas de la forme du prototype.** Le mode prototypage (React UMD + Babel CDN) est
   **abandonné**. En prod, les pages sont **rendues serveur par Blade/Livewire** ; un **service worker
   maison** (Workbox ou natif) + manifest statique portent l'installabilité et l'offline-lecture du
   planning de la semaine (cf. §4.1). La capacité PWA dépend de la *plateforme web* (manifest + SW +
   HTTPS), **pas du framework front** ; le SSR Node — seul mode incompatible mutualisé — est écarté (§6.2).
5. Les **briques riches** non couvertes par le design (éditeur WYSIWYG, carte OSM, datepicker FR…)
   s'ajoutent comme **îlots JS/Alpine** dans les vues Blade, stylés avec les mêmes tokens.

> Le bundle a été **archivé comme référence versionnée** dans le dépôt privé du projet, avec son
> propre handoff design → dev. Il n'accompagne pas le dépôt public (cf. encadré du §6.6).

---

## 7. Décisions sur les points laissés « à arbitrer »

| # | Point | Décision proposée |
|---|---|---|
| 7.1 | **Sérialisation atomique** (PRD §4.9.5) | Transaction InnoDB + verrou pessimiste `SELECT … FOR UPDATE` **sur la ligne `sessions`** (pas de compteur de capacité matérialisé — `COUNT` live fait foi, cf. §14.1) au moment de l'inscription. Quota + capacité + rang FIFO évalués dans la **même transaction sous le même verrou** : le premier obtient `participating`, le second bascule `waitlist capacity`. **Garanti par tests E2E concurrents.** Pas de double-acceptation. |
| 7.2 | **Compteur de quota** (PRD §4.10, temps quasi-constant) | Quota **fixe par tag** (§4.10.3, sans dépendance à la cohorte) → **requête live indexée bornée à la semaine courante** (futures + passées du même tag), coût quasi-constant. **Pas de compteur matérialisé** (inutile, cf. §14.1). **Pas de Redis.** |
| 7.3 | **Bootstrap premier admin** (PRD §4.1.4) | Email de bootstrap en **variable d'environnement** (`.env`). Le premier user s'inscrivant avec cet email reçoit `admin`. Reproductible pour tout fork. |
| 7.4 | **Magic link / reset MDP** (PRD §4.1.1) | Token aléatoire fort, **stocké hashé** côté serveur, **TTL 15 min**, **usage unique**, invalidation à la consommation. |
| 7.5 | **Stockage objets** (PRD §4.12) | Fichiers **hors webroot**, servis par un **contrôleur PHP** appliquant le contrôle d'accès (cohérent avec la règle parent/enfant). Pas d'URL publique devinable. |
| 7.6 | **Parsing GPX** (PRD §4.13.2, §4.20) | **Client uniquement, sans exception.** Le client extrait distance/D+/D-/alt/points **et les données dérivées de la bibliothèque** (emprise, secteur, forme, tracé simplifié, profil altimétrique) ; le serveur **valide, borne et refuse**, mais ne lit jamais le XML. Étendu en §7.15. |
| 7.7 | **WYSIWYG** (PRD §4.12.1) | Éditeur client restreint, **markdown stocké**, **sanitisation serveur PHP faisant foi** (whitelist de balises). Pipeline figé §14.1 : `league/commonmark` (HTML brut désactivé) → **HTMLPurifier** (whitelist = périmètre §4.12.1). Liens externes `https`/`mailto` + `target="_blank" rel="noopener noreferrer"`. |
| 7.8 | **Météo** (PRD §4.13.5) | Open-Meteo, **cache serveur 3h** par `(lieu, créneau)`, **pré-calcul périodique** des séances de la fenêtre J-16 via cron. |
| 7.9 | **Reset surclassements / nouvelle année sportive** (PRD §4.5) | **Action admin manuelle, PAS un cron** : bouton « Démarrer la nouvelle année sportive » → recalcul de la catégorie principale + suppression des `UserCategory` non-principales, en une transaction. Bandeau de rappel passif sur le dashboard à partir du 1er sept. Décision humaine (le club se met d'accord avant). |
| 7.10 | **Conversion HEIC** (uploads iPhone, PRD §4.12.2) | **Côté client** avant upload (lib JS), pour ne pas charger le serveur ni le mutualisé. |
| 7.11 | **Génération XLSX** (PRD §4.16.2) | **Lib PHP serveur**, une feuille par tableau, en-têtes en gras, dates FR. |
| 7.12 | **OpenRunner embed** (PRD §4.13.1) | Stocker **uniquement l'URL `src`**, **validation whitelist serveur stricte** (hôte `www.openrunner.com`, path `/embed.html`, param `code`), **iframe régénérée côté client** à attributs figés. |

### 7.13 Inventaire des tâches planifiées (périmètre exact du cron)

Le mutualisé n'a pas de worker permanent. Il n'accepte pas non plus un cron fréquent : **une
exécution par heure au maximum, à une minute imposée** par l'hébergeur (contrainte vérifiée, cf.
INSTALL §5.4). On configure donc **une tâche horaire unique** pointant `cron.php`, qui lance une
boucle appelant le **scheduler du framework** (`schedule:run`) chaque minute pendant ~55 min.

⚠️ **Conséquence structurante** : la boucle couvre 55 min sur 60, et le trou restant se déplace avec
la minute imposée — **aucune minute d'horloge n'est sûre**. Une tâche en `hourly()` (`0 * * * *`)
peut n'être vue *jamais*. Toutes les tâches récurrentes sont donc planifiées `everyFiveMinutes()` et
**gardées par `--if-due`** (`DuePeriodGuard`) : « au moins une fois après l'échéance, une seule fois
effectivement ». Verrouillé par test (`CronLoopTest`).

Le scheduler porte **quatre** tâches récurrentes ; tout le reste de l'app est **événementiel** (dans
la transaction), **paresseux** (calculé à l'affichage), ou **explicitement exclu** par le PRD.

| Tâche | Cron ? | Justification |
|---|---|---|
| **Pré-calcul météo J-16** (§4.13.5) | ✅ **Oui** | Rafraîchit le cache (TTL 3h) des séances géocodées à venir → affichage instantané, source non martelée. Seule tâche réellement incontournable. |
| **Drain de l'`outbox`** notifications (§7.14) | ✅ **Oui** | Vide la file d'envois push/email par lots. |
| **Élagage des jetons d'auth** (§4.3) | ✅ **Oui** | `club:prune-tokens` — borne la croissance des tables et purge l'email résiduel des liens consommés (minimisation). |
| **Reset de la démo** (plan OS7) | ✅ **Oui** | `demo:reset` — instance de démonstration uniquement (`DEMO_MODE`), fenêtre nocturne. |
| Reset surclassements / nouvelle année (§4.5) | ❌ **Non** | Devenu **action admin manuelle** (§7.9). |
| Sauvegardes BDD/fichiers (§6) | ❌ **Non** | **Natif OVH** (quotidien 30j / fichiers J-14, §8.2). |
| Reset quota hebdo (§4.10.6) | ❌ **Non** | PRD : *« pas de cron de reset »* — recalcul naturel sur la semaine courante. |
| Suppression comptes J+7 (§4.3) | ❌ **Non** | PRD : *« pas de job auto »* — clic admin ; signaux passifs à l'affichage. |
| Alerte séance sans coach (§4.11.2) | ❌ **Non** | PRD : *« pas de cron / scheduler »* — détection in-context. |
| Rappels avant séance, bascule d'âge, purge journaux (§4.15.2, §4.2, §4.18.4) | ❌ **Non** | Explicitement exclus du V1. |

### 7.14 Stratégie de notification : `outbox` + drain (PRD §4.15)

**Règle unique : toute notification (push + email) transite par une table `outbox`.** La requête HTTP
qui déclenche l'événement ne fait qu'**insérer des lignes** (rapide, même en fan-out élevé) ; le **cron
de drain** envoie réellement et **retente** les échecs. Avantages : un **seul chemin de code** pour
appliquer la matrice de préférences (§4.15.3), la pause globale (§4.15.4) et le routage parent/enfant
(§4.15.5) ; **résilience** (aucune notif perdue si l'email/push échoue) ; **fan-out absorbé** sans
bloquer l'utilisateur.

**Drain inline (envoi immédiat après commit)** réservé aux notifs **à destinataire unique et sensibles
à la latence** où l'utilisateur attend le message : **magic link** et **reset MDP** (§4.1.1), invitation
d'activation (§4.1.3). Elles sont quand même tracées dans l'`outbox` (retry si l'envoi inline échoue).

**Drain à la demande (envoi prioritaire)** — 3e mode, en plus du drain cron et du drain inline. Un
déclenchement **synchrone après commit** qui vide *immédiatement* les lignes `outbox` concernées,
sans attendre le passage du cron. Exposé produit par la case **« envoi prioritaire »** du dialog de
modification de séance (§4.7) et le bouton **« pousser maintenant »** de l'écran de gestion (§4.15.6).
Réutilise **le même chemin de code** que le drain cron (matrice + pause + routage parent/enfant) ; le
mono-cron est conservé et `SKIP LOCKED` reste inutile (volume faible, pas de concurrence de drain réelle).

**Interrupteurs de canal (PRD §4.17)** — l'état vit sur le singleton `ClubSettings`
(`notif_push_enabled`, `notif_email_enabled`), lu par `ClubSettings::channelEnabled()`. À distinguer
de `config('club.notifications.channels.*')`, qui choisit le **transport** (quelle classe envoie) :
ici on décide **si** on envoie. Deux points d'application, volontairement :

- **à l'émission** (`NotificationDispatcher::linesFor`) — un canal fermé ne crée **aucune ligne**.
  Sans ce filtre, l'`outbox` se remplirait d'envois destinés à un canal muet ; avec `LogChannel` en
  driver, ils étaient même marqués `sent`, l'écran de gestion affichant des envois qui n'ont jamais eu
  lieu. Contrepartie assumée : ce qui est émis pendant la coupure est **perdu** pour ce canal — rien à
  rejouer à la réactivation, ce qui vaut mieux qu'un lot d'alertes périmées libéré d'un coup.
- **au drain** (`OutboxDrainer::process`) — garde de rattrapage pour les lignes **déjà en file** au
  moment de la coupure : passées en `cancelled` (statut existant, donc visible dans l'écran de
  gestion), sans consommer de tentative ni programmer de backoff. La cause n'est pas transitoire, un
  retry serait un faux échec.

**Écran de gestion de l'`outbox` (bureau, §4.15.6)** — surface admin de supervision/rattrapage,
réutilisant le chemin de drain unique : consultation/filtre (statut, canal, type, destinataire) + détail,
**annulation** d'envois `pending` (rattrapage d'une notif émise par erreur), **drain à la demande** (un
envoi ou tous les `pending`), **rejeu** des `failed` (remise `pending` + `available_at` immédiat). Toute
action reste tracée ; la simple consultation n'écrit rien.

**Fonctions à fort fan-out** (une action → potentiellement toute une liste) — c'est ce que l'outbox
protège : **annulation / restauration / modification de séance** (§4.7, tous les `participating`),
**bascule de saison** (§4.4, tous les athlètes), **génération de `SessionTemplate`** (§4.8, récap par
coach), **création de `competition`/`club_event`** (§4.15.2, toute la catégorie ciblée), **mécanisme C**
et **ajout de tag a posteriori** (§4.10, athlètes promus / repassés en waitlist). Les promotions A/B
unitaires et les notifs coach (§4.10.4, §4.11.2) suivent le même chemin sans traitement particulier.

---

### 7.15 Bibliothèque de parcours — dérivation client, confiance serveur nulle (PRD §4.20)

Extension de §7.6. La bibliothèque a besoin de bien plus que les métriques d'affichage : emprise
géographique, secteur cardinal, forme, relief, **tracé simplifié** pour la carte et profil altimétrique.
Tout cela se dérive du GPX — et **rien de tout cela n'est calculé côté serveur**.

**Pourquoi cette rigidité.** Le format GPX est du XML : le parser côté serveur ouvrirait la surface
XXE/entités externes/bombes de décompression sur un mutualisé qu'on ne durcit pas finement, et pour un
fichier de 5 Mo déposé par un utilisateur authentifié mais non administrateur. La contrepartie assumée
est que **le client peut mentir**. Le serveur ne le prévient pas, il l'**encaisse** :

- chaque valeur reçue est **bornée** à un intervalle physiquement plausible (latitudes, longitudes,
  altitudes, distance, dénivelé). Hors bornes ⇒ la valeur est mise à `null`, jamais rejetée en bloc —
  un parcours dont le bloc géographique est aberrant reste créable, il n'est simplement pas cartographiable ;
- le **tracé simplifié est tronqué** à un plafond de points côté serveur, indépendamment de ce que le
  client annonce (un tracé de 100 000 points ne peut pas être stocké) ;
- les valeurs qualitatives (secteur, forme, relief) sont **recalculées ou validées contre une liste
  fermée**, jamais stockées telles quelles.

**Conséquence opérationnelle, apprise en production** (incident 2026-08-01) : puisque le serveur ne sait
pas reconstituer ces données, **un bundle JS périmé les perd silencieusement** — le parcours est créé,
sans erreur, mais sans carte ni secteur. Le rebâtissage des assets fait donc partie de la procédure de
déploiement, pas d'une étape optionnelle.

**Carte d'ensemble : endpoint dédié, pas d'état Livewire.** Superposer N tracés demande N polylines,
soit un volume qui ne peut pas transiter par l'état du composant : Livewire **re-sérialise son état à
chaque requête**, et 70 Ko de tracés repartiraient à chaque frappe dans la recherche. Les tracés sont
donc servis par un **endpoint JSON dédié**, appelé une fois par jeu de filtres. Deux règles :
- l'endpoint **réapplique les filtres côté serveur** à partir de la même requête que la liste — il
  n'accepte jamais une liste d'identifiants fournie par le client, qui contournerait les règles de
  visibilité (parcours archivés notamment) ;
- il **plafonne** le nombre de tracés renvoyés et signale la troncature, la carte devenant illisible
  bien avant de devenir lourde.

### 7.16 Icônes PWA — surcharge par instance, repli versionné (PRD §4.17)

L'application est distribuée en open source et déployée par plusieurs clubs, plus une démo publique.
Les icônes PWA relèvent donc de l'**identité d'une instance**, comme le nom et les couleurs — mais,
contrairement à eux, elles étaient des **fichiers du dépôt** (`public/icons/`) référencés en dur.

**Le problème.** Un club qui personnalise en écrasant ces fichiers entre en conflit à chaque `git pull`,
indéfiniment. Les sortir du dépôt (`.gitignore` + copie à l'installation) déplace le défaut sans le
résoudre : les trois points d'appel pointent alors vers des 404 tant que la copie n'est pas faite, et
**ce mode de panne est silencieux** — pas d'erreur, pas de log, seulement une PWA qui ne s'installe
pas et des notifications sans icône. Inacceptable pour un déploiement fait par un bénévole.

**Décision.** Les icônes du club sont **téléversées** et stockées sur le disque `public`
(`storage/app/public/`), exactement comme le logo (§ClubBrandingService) ; le jeu livré dans
`public/icons/` **reste versionné et sert de repli**. Un déploiement neuf est donc installable en PWA
sans aucune étape, la démo montre le produit, et la personnalisation ne touche jamais l'arbre Git.
Le stockage sous `storage/` est en outre déjà exclu des transferts de déploiement (INSTALL §5.2) :
un `rsync --delete` ne peut pas effacer les icônes du club, ce qu'un dossier sous `public/` n'aurait
pas garanti.

**Trois fichiers téléversés séparément, pas un seul redimensionné.** Les trois icônes ne sont pas
trois tailles d'un même rendu : les formats manifest sont déclarés `any maskable` (donc rognés en
cercle par le lanceur Android, ce qui impose une zone de sécurité), tandis que l'icône iOS doit être
**opaque**. Dériver le tout d'une source unique obligerait à choisir un fond d'aplatissement et une
marge de rognage à la place du club, en silence. Le fond retenu pour l'aplatissement iOS est le
`background_color` du manifest (blanc), et non `primary_color` : prévisible, et stable si la palette
du club est retouchée.

**Validation.** Même barrière que le logo — seul ce que **GD sait décoder** est publié sous une URL
same-origin (un fichier refusé est corrompu ou exécutable déguisé) — plus une vérification des
**dimensions exactes** : une icône hors format casse l'installation PWA sans erreur visible.

**Deux conséquences à ne pas perdre de vue :**
- `public/sw.js` est un fichier **statique** : il ne peut pas lire l'état du serveur. L'URL de
  l'icône de notification transite donc par le **payload push**, déjà rendu côté serveur.
- la liste blanche `/storage/` du `.htaccess` racine est **fermée par défaut** : le dossier des
  icônes doit y être ajouté explicitement, faute de quoi elles répondent 404 sans autre explication.

---

## 8. Tensions mutualisé ↔ PRD et compromis

Format : *exigence → friction → option idéale → compromis retenu → risque résiduel → réexamen.*

1. **Preview par PR** (§6 PRD) — *Friction* : pas de Docker ni d'environnements éphémères.
   *Compromis* : la **CI reste complète** (lint, type-check, unit + E2E headless, couverture > 80 %)
   car elle tourne chez le fournisseur CI, **pas** sur OVH ; un **unique environnement de staging**
   déployé manuellement (sous-domaine) remplace la preview-par-PR. *Risque* : revue visuelle moins
   fluide. *Réexamen* : VPS/PaaS si le volume de contributions augmente.

2. **Backups RPO 7j « PITR ou équivalent »** (§6) — **Couvert nativement par l'offre OVH Pro, sans
   cron dédié.** OVH fournit des sauvegardes automatiques **quotidiennes** des bases (rétention
   **30 jours**) et la restauration des **fichiers jusqu'à J-14**. Le **RPO effectif ≤ 24h** est très
   en deçà des 7j exigés ; ces snapshots quotidiens valent « équivalent PITR » au sens du PRD. → **un
   crontab de dump quotidien serait redondant et n'est donc PAS retenu.** *Reste à couvrir* : l'**export
   froid rétention 1 an** (le natif OVH plafonne à 30j/14j et vit sur le même compte OVH). *Compromis* :
   **export manuel/mensuel** (dump SQL + archive du dossier objets) **téléchargé hors OVH** comme filet
   de dernier recours indépendant — geste léger, pas un cron. *Risque résiduel* : sauvegardes natives
   liées au compte OVH (mitigé par l'export froid externe). *Réexamen* : PITR réel sur VPS si la
   criticité monte.

3. **Push / email sans worker permanent** (§4.15, §4.8 PRD) — *Friction* : pas de daemon long-running.
   *Compromis* : **toute notif transite par une table `outbox`** drainée par le cron (§7.14) — la
   requête ne fait qu'insérer, le fan-out élevé (annulation de séance, bascule de saison, génération de
   template…) est absorbé sans bloquer l'utilisateur, et les échecs sont retentés. **Drain inline** après
   commit pour les seuls cas sensibles à la latence (magic link, reset MDP). VAPID natif déclenché par PHP.
   *Risque* : latence de livraison ≤ période du cron (~5 min) sur les notifs non urgentes — acceptable.

4. **Parsing GPX client** (§4.13.2) — **Pas une tension** : le PRD impose déjà le client. Alignement
   positif (charge serveur et surface d'attaque réduites).

5. **Temps réel** (§6 fraîcheur) — **Pas une tension dure** : le PRD précise « souhaitable mais non
   requis, ne doit pas contraindre la stack ». *Compromis* : **refresh à chaque chargement /
   pull-to-refresh** ; pas de polling agressif ni SSE long-running. *Réexamen* : SSE/WebSocket sur VPS.

6. **Sérialisation atomique & quota quasi-constant** (§4.9.5, §4.10) — *Friction potentielle* :
   nécessite transactions fiables + pas de Redis. *Compromis* : **aucun** — réalisable nativement sur
   InnoDB (verrous + requête indexée bornée). À garantir par tests E2E.

7. **Crons OVH limités** (granularité/nombre) — *Friction* : pas de scheduler sub-minute, crons
   comptés. *Compromis* : **un cron unique** déclenchant le **scheduler du framework**, qui dispatche
   les **deux** seules tâches récurrentes (météo J-16, drain outbox — cf. §7.13). *Risque* : granularité
   minimale (selon offre) — sans impact, aucune tâche n'est sub-5-min.

8. **Monitoring / logs centralisés** (§6) — *Friction* : pas d'agent système installable.
   *Compromis* : **SDK applicatif** (Sentry PHP/JS région UE) + logs applicatifs fichiers rotatés.
   *Risque* : pas de métriques infra fines (acceptable pour le volume).

9. **Performance < 2s sur 4G** (§6) — *Friction* : ressources mutualisées partagées, latence variable.
   *Compromis* : front **cache-first** (service worker), assets minifiés/compressés (CDN UE possible),
   payload API léger. *Risque* : pics de latence du mutualisé.

---

## 9. Matrice de décision

### 9.1 Matrice A — Exigence × candidat

Légende : ✅ couvert nativement · ⚠️ couvert avec compromis (voir §8) · ❌ incompatible/écarté · — hors périmètre de l'axe.

| Exigence PRD | Laravel | Symfony | Blade+Livewire | SPA React+Vite | VAPID natif | FCM | Filesystem | S3 UE |
|---|---|---|---|---|---|---|---|---|
| Hébergement UE strict | ✅ | ✅ | ✅ | ✅ | ✅ | ⚠️ (flux Google) | ✅ | ✅ |
| Self-hosting AGPL reproductible | ✅ | ✅ | ✅ (1 artefact) | ✅ (2 artefacts) | ✅ | ⚠️ lock-in | ✅ | ✅ |
| Réutilisation design (§6.6) | — | — | ⚠️ markup→Blade (CSS tokens tels quels) | ✅ JSX quasi tel quel | — | — | — | — |
| Sérialisation atomique (§4.9.5) | ✅ | ✅ | — | — | — | — | — | — |
| Quota quasi-constant (§4.10) | ✅ | ✅ | — | — | — | — | — | — |
| PWA + offline-lecture (§6) | — | — | ✅ (SW maison sur pages Blade) | ✅ (Vite+SW) | — | — | — | — |
| Push web (§4.15) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — |
| Stockage objets (§4.12) | ✅ | ✅ | — | — | — | — | ✅ | ✅ |
| Crons (§4.5, §4.13.5) | ✅ | ✅ | — | — | — | — | — | — |
| CI/CD + migrations (§6) | ✅ | ✅ | ✅ | ✅ | — | — | — | — |
| Backups RPO (§6) | ✅ natif OVH | ✅ natif OVH | — | — | — | — | ✅ natif OVH | ✅ |
| Temps réel (souhaitable) | ⚠️ §8.5 | ⚠️ §8.5 | ⚠️ §8.5 | ⚠️ §8.5 | — | — | — | — |

### 9.2 Matrice B — Critères pondérés (synthèse par axe)

`++` excellent · `+` bon · `0` neutre · `−` faible.

| Critère | Backend ⇒ **Laravel** | Frontend ⇒ **Blade+Livewire** | Push ⇒ **VAPID** | Objets ⇒ **Filesystem** |
|---|---|---|---|---|
| Compatibilité mutualisé (PHP/FPM, pas de Node) | + | ++ | ++ | ++ |
| UE / RGPD | ++ | ++ | ++ | ++ |
| Compatibilité AGPL | ++ | ++ | ++ | ++ |
| Self-hosting reproductible (1 artefact) | ++ | ++ | ++ | ++ |
| Coût (bénévole) | ++ | ++ | ++ | ++ |
| Vélocité solo (1 langage, pas d'API ni de sync d'état) | ++ | ++ | + | + |
| Reprenabilité (vivier dev/IA) | ++ | ++ | + | + |
| Réutilisation design Claude Design (§6.6) | — | + (markup→Blade, tokens tels quels) | — | — |
| Maturité écosystème | ++ | ++ | + | + |
| Absence de lock-in | ++ | ++ | ++ | ++ |

---

## 10. Recommandation

| Couche | Choix recommandé | Alternative crédible | Écarté |
|---|---|---|---|
| **Backend** | **Laravel** (PHP) | Symfony | PHP nu, Node, Firebase |
| **Frontend** | **Monolithe Laravel : Blade + Livewire 3 + Alpine.js 3** (rendu serveur ; PWA via SW maison ; design Claude Design porté en Blade §6.6) | SPA React/Vue + API REST | Nuxt/Next SSR |
| **Persistance** | **MySQL/MariaDB (InnoDB)** | — | Firestore, NoSQL, Postgres (indispo offre) |
| **Stockage objets** | **Filesystem hors webroot** (contrôleur PHP) | S3-compatible UE | Bucket public |
| **Email** | **Brevo** (UE) | Scaleway TEM, Mailjet | — |
| **Push** | **Web Push VAPID natif** (`web-push-php`) | — | FCM/Firebase |
| **Monitoring** | **Sentry (région UE)** | Log fichier + alerte mail | — |
| **Carte / géocodage** | **OpenStreetMap / Nominatim** | service ouvert UE | — |
| **Parcours / Météo** | **OpenRunner Pro** (embed) · **Open-Meteo** | — (figés PRD) | — |
| **WYSIWYG** | éditeur **TipTap** (îlot JS dans une vue Blade) + sanitisation PHP faisant foi | — | HTML brut |
| **Style / UI** | **CSS tokens du design conservés tels quels** (`club-tokens.css` + `club-app.css`) ; composants en Blade/Livewire | Tailwind en utilities mappées aux tokens (complément, pas remplacement) | Tailwind en remplacement du CSS livré |
| **Design de référence** | **Bundle Claude Design (React/JSX + CSS tokens)** archivé hors dépôt public, **porté en Blade** (§6.6) | — | — |

**Cohérence d'ensemble** : stack 100 % servable sur le mutualisé déjà payé, sans Docker/Node/daemon/
WebSocket, **conforme UE**, **AGPL-compatible**, **reproductible** par un autre club avec seulement
**3 prérequis externes** (client Google OAuth propre, fournisseur email UE, compte OpenRunner Pro),
et compromis ops **assumés et documentés** plutôt qu'ignorés.

---

## 11. Conséquences opérationnelles & assouplissements ops

- **CI/CD** : CI complète sur PR (lint, type-check, unit + E2E, couverture > 80 %) chez le fournisseur
  CI ; **déploiement prod manuel** via le **git intégré OVH Pro** (push/pull sur l'hébergement en SSH)
  + exécution des migrations/seed via la console du framework ; **garde-fou** sur la branche principale
  (protection + CI verte requise). Staging unique manuel en lieu et place de la preview-par-PR (§8.1) —
  faisable via un second site (l'offre Pro en autorise jusqu'à 100) ou une seconde base parmi les 10.
- **Backups** : **reposer sur les sauvegardes natives OVH Pro** (BDD quotidiennes, rétention 30j ;
  fichiers restaurables J-14 → RPO ≤ 24h, conforme §6). **Pas de cron de dump dédié.** Seul ajout :
  un **export froid manuel/mensuel** (dump SQL + archive objets) téléchargé **hors OVH** pour la
  rétention 1 an et l'indépendance vis-à-vis du compte (§8.2).
- **Monitoring** : Sentry région UE (front + back) + logs fichiers rotatés (§8.8).
- **Migrations & seed** : **versionnés dans le repo**, reproductibles sur un environnement neuf,
  exécutés au déploiement ; seed des catalogues (catégories FFTri, disciplines, qualifications…).

---

## 12. Plan du guide d'installation

> Le guide existe depuis : **[INSTALL.md](INSTALL.md)**. Cette section conserve le plan tel qu'il
> avait été arrêté, et n'en suit pas la numérotation courante.

- **Prérequis club** : hébergement PHP/MySQL UE, **client Google OAuth** propre au club, **compte
  fournisseur email UE**, **compte OpenRunner Pro**.
- **Variables d'environnement** : email de bootstrap admin, secrets/clés app, **clés VAPID**, DSN BDD,
  identifiants email, région, URL publique.
- **Étapes** : déploiement du code → exécution des migrations → seed des catalogues → premier login
  admin (via l'email de bootstrap) → configuration des paramètres club.

---

## 13. Risques, dette assumée, réexamen V2 / VPS

- **Push iOS** : limité à Safari 16.4+ avec PWA installée (déjà acté PRD) ; email = fallback documenté.
- **Pas de temps réel** : rafraîchissement manuel (déjà autorisé PRD).
- **Ops manuelles** : déploiement, vérification des dumps, surveillance du volume objets/logs reposent
  sur la discipline du mainteneur.
- **Conditions déclenchant une migration vers VPS / PaaS UE** (Scaleway, Clever Cloud, OVH VPS…) :
  besoin de **temps réel** (SSE/WebSocket), **PITR réel**, **preview-par-PR** automatisée, montée en
  **volume d'objets** justifiant un S3, ou charge cron dépassant les limites du mutualisé. Le monolithe
  Laravel (Blade + Livewire) est **portable** vers un VPS sans réécriture (seuls l'hébergement et la
  configuration ops changent) ; Postgres deviendrait alors envisageable. Un VPS lèverait aussi la
  contrainte « pas de worker » → temps réel possible (Livewire + Laravel Echo/Reverb) si le besoin émerge.

---

## 14. Revue pré-dev (16 juin 2026)

Passe d'audit critique des **15 décisions techniques** avant démarrage du développement (OVH Pro pris
comme invariant, cf. §1.2). **Verdict global : cadrage validé, aucune décision à rouvrir.** 9 décisions
confirmées sans réserve, 6 confirmées avec un détail d'implémentation à figer (tranchés ci-dessous) —
aucun n'est une remise en cause d'architecture. Cette section **grave les précisions** décidées et
**ne modifie pas** les arbitrages des §1-13, qu'elle confirme.

### 14.1 Précisions figées (4)

- **Sérialisation inscription — verrou sur quoi (raffine §4.4, §7.1)** : verrou pessimiste
  `SELECT … FOR UPDATE` sur **la ligne `sessions`**, puis `COUNT` des `participating` indexé, puis
  insert. **Pas de compteur de capacité matérialisé** (`participating_count`) : le `COUNT` live fait
  foi (coût négligeable à ~50-150 users) et supprime toute désync sur les nombreuses transitions de
  statut (annulation, role-flip, override, promotions A/B/C, anonymisation RGPD). La **même transaction
  sous le même verrou** couvre l'évaluation **quota (§14.1) + capacité + attribution du rang FIFO
  waitlist** → un seul chemin atomique `lock session → eval quota → eval capacité → insert (status +
  reason)`. Empêche la race « passe le check quota puis course sur la dernière place ».

- **Quota fair-share — requête live (raffine §4.4, §7.2)** : l'algorithme PRD §4.10.3 est un
  **quota fixe par tag** (`N = maxPerWeek(T)` constant, `C = COUNT participating` de l'athlète sur le
  tag dans la semaine courante) — **aucune dépendance à la cohorte**, pas de redistribution dynamique.
  Donc **requête live indexée** (index composite borné à la semaine courante), réellement quasi-constante
  (NFR §4.10.1 satisfaite). **Aucune matérialisation** : le plan B « compteur hebdo maintenu » est
  abandonné comme inutile. La promotion (mécanisme B, §4.10.2) compte les `participating` sur séances
  **passées uniquement** = variante du même index + filtre date.

- **Auth — intégration Fortify ↔ Livewire (raffine §4.3)** : **Laravel Fortify en backend nu**
  (primitives login/reset/vérification/throttle/hashing) **+ écrans Livewire maison** stylés aux tokens
  du design. **Jetstream écarté** (embarque `teams` = multi-tenant, interdit ; + 2FA/API tokens non
  voulus). **Breeze écarté** (écrans génériques à re-styler + magic link / Google / linking / parent
  garant absents → point de départ jetable). Les 3 méthodes d'auth + le linking + le parent garant se
  câblent par-dessus les primitives Fortify.

- **Sanitisation WYSIWYG — pipeline (raffine §4.5, §7.7)** : **markdown stocké** (conforme §4.5) ;
  rendu via **`league/commonmark`** avec **HTML brut désactivé** (pas de balises inline arbitraires),
  puis **HTMLPurifier** en sortie avec **whitelist = périmètre PRD §4.12.1** (`b/strong`, `i/em`, `s`,
  `ul/ol/li`, `a`, `h2/h3`, `blockquote`). La **sanitisation serveur fait foi**. Liens forcés à schéma
  `https`/`mailto`, `target="_blank" rel="noopener noreferrer"` (rejet `javascript:`/`data:`). Un seul
  pipeline pour `contentMarkdown`, `agenda` et `Debrief.contentMarkdown`. À implémenter au premier slice
  touchant du contenu WYSIWYG (pas un prérequis J0).

### 14.2 Notes ops (2)

- **MariaDB — version à confirmer à l'init** : viser **≥ 10.6** dans le manager OVH (déjà noté §52).
  `FOR UPDATE` + transactions suffisent au cœur métier quel que soit le millésime ; `SKIP LOCKED`
  (10.6+) n'est **pas requis** car le drain `outbox` est mono-cron (pas de concurrence de drain).

- **Email — délivrabilité auth** : Brevo reste le **défaut** (§6.3), mais le magic link et le reset MDP
  sont **bloquants si en spam**. Documenter **Scaleway TEM** (FR souverain, réputation dédiée) comme
  **alternative recommandée pour la délivrabilité** dans `doc/INSTALL.md` (§12) — chaque club arbitre à
  l'installation.
