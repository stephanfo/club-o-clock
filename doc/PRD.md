# PRD — Application de gestion du planning d'entraînement (Club Triathlon)

**Statut** : cadrage fonctionnel figé, **V1 livrée**. Ce document reste volontairement agnostique de la stack — les choix techniques et leurs contreparties vivent dans le [cadrage technique](CADRAGE_TECHNIQUE.md).

> Ce document est la **source de vérité produit** pour la V1. Il décrit *l'état cible* sans historique de versions et sans choix d'implémentation. Toute proposition qui s'en écarte doit être discutée explicitement avant codage.

---

## 1. Vision et objectifs

### 1.1 Vision
Remplacer le tableur Google Sheets actuel par une **PWA** qui offre aux adhérents, coachs et bureau une **vue claire et toujours à jour du planning d'entraînement**, avec un système d'inscription fiable.

### 1.2 Objectifs métier
- **Réduire la friction** : qu'un adhérent sache à tout moment quelles séances ont lieu, où, avec qui.
- **Permettre aux coachs** d'anticiper la fréquentation et d'éviter le sur-effectif (notamment en piscine).
- **Désengorger les groupes WhatsApp** des annulations / modifications de dernière minute.
- **Garder la maîtrise du contenu** : code maintenable par le club, **réutilisable par d'autres clubs (AGPL)**.

### 1.3 Critères de succès (proxy)
- > 80 % des adhérents actifs consultent l'app ≥ 1×/semaine après 2 mois.
- > 70 % des inscriptions aux séances passent par l'app.
- Diminution mesurable du « est-ce qu'il y a piscine ce soir ? » sur WhatsApp.

### 1.4 Distribution et licence
- **AGPL-3.0**, code public, `LICENSE` + `NOTICE` (attributions) à la racine.
- **Modèle one-instance-per-club**. **Pas de multi-tenant, jamais.** Un autre club déploie sa propre instance. Le code ne contient ni `tenant_id` ni isolation logique multi-club.

---

## 2. Personas et rôles

| Persona | Profil | Besoins principaux |
|---|---|---|
| **Adhérent / athlète** | Membre licencié, âge variable | Voir son planning, s'inscrire/désinscrire, recevoir notifs |
| **Parent garant** | Représentant légal d'un athlète mineur | Recevoir les notifs et agir au nom de son ou ses enfants |
| **Coach** | Encadrant d'un ou plusieurs groupes | Créer/modifier les séances, s'inscrire comme encadrant, déclarer ses qualifications |
| **Bureau / admin** | Élu·e du club | Gérer comptes, paramètres club, stats |

**Rôles cumulables globalement** : un même `User` peut avoir `athlete` + `coach` + `admin`. Pas de hiérarchie.

**Exclusivité par séance** : sur une séance donnée, un utilisateur ne peut pas être à la fois dans `coaches[]` et dans les inscriptions athlète. Voir §4.11 pour la mécanique de bascule.

**Parent garant ≠ rôle** : c'est une **relation** vers un ou plusieurs `User` enfants, à distinguer de `roles[]`. Un parent peut cumuler n'importe quel rôle propre. Capacité, quota et préférences notif sont calculés indépendamment pour parent et enfant.

**Périmètre exclu V1** : invités / non-adhérents (pas d'accès séance d'essai).

---

## 3. Périmètre V1 et hors-V1

### 3.1 Dans la V1
- Authentification 3 méthodes (email/MDP, magic link, Google).
- Comptes mineurs en 3 phases avec parent garant (P1 → P2 → P3).
- Gestion des adhérents : import CSV initial + ajout one-shot en cours de saison.
- Suppression de compte avec tampon minimum de 7 jours.
- Bascule de saison par désactivation manuelle de l'accès athlète.
- Catalogues paramétrables (catégories d'âge, disciplines, types d'épreuve, tags de quota, qualifications, lieux), seedés au déploiement.
- Catégories d'âge multi-rattachement (M:N).
- Planning hebdomadaire avec 3 `kind` de `Session` : `training`, `competition`, `club_event`.
- Générateur de séances récurrentes (`SessionTemplate`, admin uniquement).
- Inscription/désinscription libre jusqu'au début, bloquée après.
- Capacité + waitlist (`capacity`, `quota_exceeded`) avec visibilité publique.
- Quota fair-share par tag avec 3 mécanismes de promotion (A auto place libérée, B auto silencieuse sur libération propre quota, C déblocage manuel coach).
- Override coach (forçage avec motif optionnel).
- Encadrement coach par inscription (M:N) + qualifications agrégées sur la fiche séance.
- Contenu de séance (texte enrichi WYSIWYG + 1 pièce jointe ≤ 5 Mo).
- Compétitions (intention déclarée, lien organisateur externe).
- Débrief de compétition rédigé par les membres participants (texte enrichi), éditable par l'auteur, archivable/réactivable/éditable par l'admin.
- Lien externe vers un album photos (compétitions et événements club), saisi par coach/admin.
- Événements club (AG, soirée, sortie loisirs…).
- Parcours OpenRunner Pro (embed iframe) et/ou GPX (parsing client, ≤ 5 Mo).
- Météo prévisionnelle (cible Open-Meteo) sur séances géocodées dans la fenêtre J-16.
- Flag « j'offre l'apéro » sur les inscrits actifs.
- Notifications push web + email transactionnel UE, matrice de préférences + pause globale ; choix à la sauvegarde d'une séance (annuler / silence / notifier) avec option d'envoi immédiat vs différé.
- Gestion des envois sortants côté bureau : consultation/filtre de la file de notifications, rattrapage (annulation d'envois en attente), envoi manuel immédiat, rejeu des échecs.
- Dashboard stats bureau + export XLSX.
- Paramètres club (identité, fuseau, catalogues, lien d'invitation, bascule de saison).
- Deux journaux séparés : `AuditLog` (gouvernance) et `ActivityLog` (opérationnel), accès admin uniquement.
- Pages d'information (notes club) éditables par l'admin, avec visibilité par niveau de rôle et épinglage en bannière d'accueil (ajout post-cadrage, cf. §4.19).

### 3.2 Hors V1 (V2+)
- Cotisations / paiements en ligne.
- Suivi de performance individuel (Strava, courbes de progression).
- Inscription collective gérée par le club (dossard, tarif groupé, covoiturage).
- Section invités / séance d'essai.
- App mobile native.
- Intégration FFTri / vérification automatique de licence.
- Pointage présence / no-show formel (en V1 le statut `participating` sur séance passée fait foi).
- Compteur de fiabilité athlète.
- Plusieurs tags de quota par séance.
- Récap hebdomadaire email.
- Apple Sign-In.
- Stockage du téléphone et du certificat médical (minimisation RGPD).
- Compteur / classement public d'apéros offerts.
- Débrief sur `training` / `club_event` (V1 = compétition uniquement).
- Réactions / commentaires / fil de discussion sous un débrief.
- Hébergement ou intégration des photos dans l'app (V1 = simple lien externe vers un album tiers ; aucune galerie native).

---

## 4. Spécifications fonctionnelles

### 4.1 Authentification et comptes

#### 4.1.1 Méthodes d'auth
- **Email + mot de passe** : disponible pour tout compte avec email. Reset par lien email à usage unique, **TTL 15 min**.
- **Magic link** : méthode d'auth complète à part entière, utilisable seule (passwordless) ou en complément d'un MDP. À chaque connexion, l'adhérent choisit Google / MDP (si défini) / « recevoir un lien ». Sert également de mécanisme « mot de passe oublié ». **TTL 15 min**, usage unique.
- **Google OAuth 2.0 / OIDC** : chaque club self-hosté configure **son propre** client OAuth dans sa Google Cloud Console (à documenter dans le guide d'installation). Données récupérées et stockées : **email + name** uniquement (pas de photo). Le nom Google n'écrase pas un nom déjà saisi.

**Méthodes proposées par le club.** Le **magic link** et **Google** sont activables par instance
(§4.17) : un club qui n'a pas fait la démarche Google Cloud Console, ou qui ne veut pas de connexion
sans mot de passe, les retire de l'écran de connexion. Le bouton Google n'est de toute façon proposé
que si un client OAuth est effectivement configuré — sans quoi il ne mènerait qu'à une erreur.
**Email + mot de passe n'est pas désactivable** : c'est la voie garantie, ce qui borne le risque de
rendre l'application inaccessible. Couper un moyen ne peut jamais priver un compte actif de son
dernier accès (§4.1.2) : la coupure est refusée tant que c'est le cas. Un lien déjà envoyé cesse
d'être honoré dès la coupure, sans être consommé — il redevient valide si le club réactive la méthode
avant son expiration.

#### 4.1.2 Linking multi-méthodes
- Un même email peut être lié à plusieurs méthodes sur un seul compte.
- **Liaison automatique sur email vérifié** : si un compte inscrit en email/MDP se connecte ensuite via Google avec `email_verified=true`, les méthodes sont fusionnées sans double prompt.
- Si l'email Google n'est pas vérifié, refus + message expliquant de se connecter avec la méthode initiale puis de lier Google depuis le profil.
- L'utilisateur peut révoquer ses méthodes liées depuis son profil, en conservant toujours au moins une méthode active.

#### 4.1.3 Flow de création
1. Admin importe un CSV (nom, prénom, email, catégorie, date de naissance, parent garant éventuel). L'**email est facultatif** pour les mineurs en phase 1 (cf. §4.2) : pas d'invitation, le `User` enfant est créé sans credential.
2. Pour les adhérents avec email : invitation par email avec lien d'activation **valide 30 jours** (durée configurable admin, régénérable).
3. L'adhérent choisit sa méthode d'auth à l'activation. **La définition d'un mot de passe est optionnelle**.

Voie complémentaire : la **page admin « Ajouter un adhérent »** permet la création one-shot en cours de saison (mêmes champs, même invitation).

#### 4.1.4 Bootstrap du premier admin
Au déploiement, un email de bootstrap admin est configuré (mécanisme arrêté au cadrage technique : variable d'env, fichier de config, équivalent). Le premier user s'inscrivant avec cet email reçoit automatiquement le rôle `admin`. Reproductible pour tout fork OSS.

#### 4.1.5 Édition du profil utilisateur
**L'utilisateur édite seul** :
- Identité : nom, prénom.
- Préférences de notifications : matrice §4.15 + toggle pause global.
- Mot de passe : changement avec MDP actuel, ou définition initiale si arrivé via magic link / Google sans MDP.
- Méthodes d'auth liées : voir et révoquer.

**L'admin uniquement** (depuis la fiche utilisateur §4.17) :
- Email (avec mail de confirmation au nouvel email pour validation).
- Date de naissance (impacte la catégorie principale et la bascule de saison).
- Catégories (rattachements manuels, surclassements).
- Rôles (`athlete` / `coach` / `admin`).
- Qualifications (en complément de la saisie coach).
- Flag `athleteAccessSuspended` (cf. §4.4).

#### 4.1.6 Visibilité de l'historique d'inscriptions
- L'**athlète** voit son propre historique (futures + passées).
- **Coach** et **admin** voient l'historique complet de n'importe quel athlète depuis sa fiche.
- **Parent garant** voit l'historique de ses enfants en phases 1 et 2.
- **Pas de visibilité croisée** sur l'historique d'un autre athlète (un athlète ne voit pas la chronologie d'un autre). Les **listes par séance** (cf. §4.9) sont en revanche visibles à tous les membres connectés.

### 4.2 Cycle de vie des comptes mineurs (P1 → P2 → P3)

Tout athlète mineur (< 18 ans) a un **parent garant lié**. Consentement RGPD implicite via la création du parent garant. Le statut de parent garant est une **relation** vers un ou plusieurs `User` enfants, pas une valeur dans `roles[]`.

Trois phases distinctes. Les transitions sont explicites et tracées en `AuditLog` ; il n'y a **aucune bascule automatique liée à l'âge**.

#### P1 — Jeune enfant sans email
- `User` enfant existe en base (identité, naissance, catégorie, historique inscriptions) **sans credential d'auth**, `email` nul.
- **Le parent agit au nom de l'enfant** depuis une UI « mes enfants » (consultation planning, inscription/désinscription).
- **Notifs uniquement au parent** (l'enfant n'a pas de canal propre).

#### P2 — Adolescent supervisé
- L'enfant a son propre email et son propre compte. Il se connecte et s'inscrit lui-même.
- Lien de tutelle actif. Le parent conserve :
  - Réception **de toutes les notifs en parallèle** (push + email sur ses canaux).
  - Inscription / désinscription pour l'enfant.
  - Consultation de l'historique complet d'inscriptions de l'enfant.
- Le parent **ne peut pas** modifier les préférences notif de l'enfant ni imposer un workflow d'approbation.

#### P3 — Athlète adulte autonome
- Le lien de tutelle est rompu (cf. §4.2.2).
- Identité et historique conservés sans rupture.
- Le parent ne reçoit plus de notifs, ne voit plus l'historique, ne peut plus agir.
- **Parent orphelin** (plus aucun lien de tutelle, pas lui-même `athlete`/`coach`/`admin`) : aucune action automatique ; auto-suppression possible via §4.3.

#### 4.2.1 Transition P1 → P2 : activation du compte autonome
Le **parent OU l'admin** saisit l'email de l'enfant → invitation envoyée (30j) → à l'activation, l'enfant choisit sa méthode d'auth. Lien de tutelle **conservé automatiquement**. Le routage des notifs bascule du mono-destinataire (parent seul) vers le double (enfant + parent).

**Validation d'email — confiance simple V1**. Pas d'OTP croisé. L'envoi puis le clic sur le lien valent validation implicite. Mitigation pédagogique côté UI : message « l'email doit appartenir à l'enfant ». Risque résiduel (parent qui saisit son propre email → compte enfant captif) assumé.

#### 4.2.2 Transition P2 → P3 : rupture du lien de tutelle
**Manuelle uniquement**. Déclencheurs possibles :
- L'enfant lui-même (depuis P2 uniquement — interdit en P1 sinon il serait captif).
- Le parent garant.
- L'admin.

**Effet immédiat** : lien supprimé, entrée `AuditLog` `guardianship_severed`, notif unique aux deux destinataires pour information, l'enfant passe en P3.

Pas de rappel automatique aux 18 ans civils en V1 (risque RGPD résiduel assumé).

### 4.3 Suppression de compte

**Deux voies** convergent vers le même état final (compte supprimé, journaux anonymisés) après un **tampon minimum bloquant de 7 jours** et une **confirmation manuelle admin**. Le tampon est un délai **minimum**, pas maximum : le compte reste « éligible à suppression » indéfiniment tant que l'admin n'a pas cliqué.

#### Voie 1 — Demande à l'initiative de l'athlète
- Bouton « Supprimer mon compte » dans le profil → notif à l'admin.
- L'admin valide ou refuse avec motif.
- À la validation : `isActive=false`, `User.deletionRequestedAt` posé. Pendant le tampon, données et lien de tutelle conservés ; l'athlète ne peut plus se connecter.
- **Annulation possible** pendant le tampon par l'admin (et par l'athlète qui se rétracte via email à l'admin) → `isActive=true`, `deletionRequestedAt=null`, AuditLog `account_deletion_cancelled`.

#### Voie 2 — Suppression à l'initiative de l'admin
- Sur la fiche utilisateur, bouton **« Supprimer ce compte »** avec **modale de confirmation initiale** (saisie du nom complet + double validation).
- Cas d'usage : athlète parti du club sans demander, doublon, compte de test.
- Même flow tampon 7j + confirmation manuelle.

#### Convergence à J+7
À partir de `now() ≥ deletionRequestedAt + 7j`, le bouton **« Confirmer la suppression définitive »** sur la fiche utilisateur devient cliquable (grisé avant J+7, côté UI ET serveur). Au clic : modale de confirmation forte → suppression effective (credentials + données personnelles) + anonymisation des journaux (cf. §4.18). `AuditLog account_deleted` avec `actorId = admin` qui confirme (jamais `system`).

**Pas de job auto de suppression en V1.** Trois signaux passifs aident l'admin à identifier les comptes à traiter :
- Bandeau d'alerte douce sur le dashboard admin dès qu'au moins un compte est éligible.
- Compteur en en-tête de la page Adhérents.
- Filtre dédié « Statut suppression » sur la page Adhérents.

Aucune notification active (push, email, in-app) n'est envoyée à J+7.

### 4.4 Bascule de saison : suspension/réactivation manuelle des athlètes

L'app **ne stocke pas les n° de licence FFTri** (minimisation RGPD ; l'admin gère sur l'extranet fédéral externe). Le mécanisme est purement applicatif : suspendre l'accès athlète, le réactiver au fur et à mesure de la vérification des licences.

#### Suspension en masse
Bouton admin **« Désactiver tous les athlètes pour la nouvelle saison »** (paramètres club §4.17). Effet :
- **Périmètre** : tous les `User` ayant `athlete` dans `roles[]` (inclut les coachs et admins qui sont aussi athlètes).
- `User.athleteAccessSuspended = true`.
- **Inscriptions futures auto-annulées** : toutes les `Registration` futures (`participating` ou `waitlist`, sur les 3 `kind`) basculent en `cancelled` (soft flag). Ces annulations déclenchent les promotions auto mécanisme A (cf. §4.10). `ActivityLog` `registration_cancelled` avec `actorId = system`.
- **Capacité d'encadrement / d'administration préservée** : le flag est **séparé** de `isActive` et n'affecte que la capacité d'inscription comme athlète. Un coach reste coach, un admin reste admin.
- **Confirmation forte** avant clic : modale avec compteurs (« Tu vas suspendre N comptes et annuler M inscriptions futures »), case à cocher de double validation, motif optionnel.
- `AuditLog` : **1 entrée globale** `bulk_athlete_deactivation` avec compteur affecté et motif optionnel (pas d'entrées individuelles).
- **Pas d'email automatique de suspension de masse** : geste routinier annuel communiqué hors-app (briefing, mail du président, AG).
- **Bannière in-app persistante** à la prochaine connexion d'un athlète suspendu : *« Ton accès athlète est suspendu pour le démarrage de la saison [N]. Contacte l'admin pour réactivation. Tes inscriptions futures ont été annulées. »*. Affichée tant que `athleteAccessSuspended = true`.

#### Réactivation individuelle
Sur la fiche utilisateur, bouton **« Réactiver l'accès athlète »** :
- `athleteAccessSuspended = false`.
- **Email transactionnel** à l'utilisateur : *« Ton accès athlète est réactivé pour la saison [N]. »* (les inscriptions précédemment annulées **ne sont pas restaurées** — l'utilisateur doit ré-effectuer ses choix).
- `AuditLog` `account_activated`.

#### Périmètre exact du flag `athleteAccessSuspended`
La suspension affecte **uniquement la capacité d'inscription comme athlète**.

**Reste accessible** : connexion, consultation planning (4 vues), contenu de séance, agendas d'événements club, fiches compétitions, listes d'inscrits, parcours/GPX, météo, stats personnelles, historique perso, compteurs quota, édition du profil (identité, préfs notif, MDP, méthodes d'auth liées), demande de suppression de compte, UI parent garant.

**Bloqué** : inscription self-service aux 3 `kind` ; inscription par le parent garant si l'enfant est suspendu.

**Bypass coach** : un coach peut **forcer une inscription via override §4.10** sur un athlète suspendu (cas : licence renouvelée, admin pas encore passé à la réactivation). `AuditLog override_quota` avec motif optionnel.

**Notifs métier conservées** pendant la suspension (matrice §4.15 + pause globale appliquées normalement). En pratique, comme les `Registration` futures sont soft-cancelled, seules les notifs « vie du club » (création de compétition, contenu de séance) restent fonctionnellement émises.

### 4.5 Catégories d'âge

**Catalogue paramétrable** (admin) : label, **borne d'âge min**, **borne d'âge max** (inclusives). Géré depuis les paramètres club (cf. §4.17) : ajout, renommage, édition des bornes, archivage.

**Seed au déploiement** : référentiel **FFTri** (mini-poussins, poussins, pupilles, benjamins, minimes, cadets, juniors + deux catégories adultes par défaut : adulte 20–39, master 40+). Entièrement reconfigurable par l'admin.

**Pas de chevauchement** entre catégories actives (validation à la saisie). Garantit qu'à tout âge correspond une et une seule catégorie principale dérivable.

**Multi-catégorie M:N** : un athlète peut appartenir à plusieurs catégories. La **catégorie principale** est dérivée automatiquement (date de naissance + année sportive sept→août). Les autres sont ajoutées manuellement par l'admin (surclassement, double rattachement).

**Bascule de saison — action admin manuelle (pas de cron) :**
La bascule n'est **pas automatique** : le club se met d'accord avant de l'enclencher. Un bouton admin
**« Démarrer la nouvelle année sportive »** (paramètres club §4.17), avec modale de confirmation
affichant les compteurs (« N catégories principales recalculées, M surclassements seront effacés »),
déclenche en une transaction :
- **Recalcul de la catégorie principale** de tous les athlètes (date de naissance + nouvelle année sportive sept→août).
- **Reset annuel des surclassements manuels** : tous les rattachements `UserCategory` non-principaux sont supprimés. Seule la principale recalculée subsiste. L'admin re-surclasse chaque saison.
- **Inscriptions futures grandfathered** (conservées même si la nouvelle catégorie ne correspond plus à la séance).
- `AuditLog season_rollover` (1 entrée globale, `actorId` = admin déclencheur).

**Rappel passif (pas de notif active)** : à partir du 1er septembre, tant que la bascule n'a pas été
déclenchée, un **bandeau d'alerte douce** sur le dashboard admin invite à démarrer la nouvelle année
sportive. Aucune action automatique liée à la date. Entre le 1er sept et le clic admin, les athlètes
ayant changé de tranche d'âge conservent temporairement leur catégorie principale de l'année écoulée
(sans incidence sur les inscriptions, grandfathered).

**Athlète sans catégorie active** (cas limite) : voit dans son planning **toutes les séances actives** (fallback ouvert) **mais ne peut s'inscrire à aucune** — bouton désactivé avec message « Aucune catégorie attribuée à ton compte — contacte l'admin ». L'admin voit ces comptes via le filtre dédié de la page Adhérents.

**Archivage (soft delete)** : une catégorie référencée par au moins un athlète, séance passée ou future, **ne peut pas être supprimée définitivement**. L'admin l'archive (disparaît des sélections, conservée sur les références historiques). Suppression dure possible si zéro référence. `AuditLog category_archived`.

**Ciblage par séance** : par défaut, une nouvelle séance cible **toutes les catégories actives** ; le coach peut restreindre. Un athlète voit dans son planning toutes les séances dont le ciblage inclut au moins une de ses catégories.

**Pas de groupes de niveau (A/B/C) en V1** — reporté V2.

### 4.6 Catalogues paramétrables (vue d'ensemble)

Pattern commun : seed au déploiement, **renommage rétroactif** (référence par ID — l'affichage est mis à jour sur les références historiques), **archivage soft delete** si la valeur est référencée, suppression dure possible si zéro référence.

| Catalogue | Qui crée | Seed | Cardinalité par Session |
|---|---|---|---|
| **Catégories d'âge** (§4.5) | Admin uniquement | Référentiel FFTri | M:N |
| **Disciplines** (§4.6.1) | Coachs au fil de l'eau, admin gère/archive | `Natation`, `Course à pied`, `Vélo`, `Enchaînement`, `PPG`, `Autre` | 1 (`training`, `competition`), 0 (`club_event`) |
| **Types d'épreuve** (§4.6.2) | Admin uniquement | `Triathlon`, `Duathlon`, `Aquathlon`, `Course à pied`, `Trail`, `Autre` | 1 (`competition` uniquement), 0 sinon |
| **Tags de quota** (§4.10) | Admin uniquement | aucun | 0 ou 1 (`training` uniquement) |
| **Qualifications** (§4.11.3) | Admin uniquement | `BF1-5`, `BNSSA`, `MNS`, `PSC1`, `PSE1`, `AFPS` | N (M:N via `UserQualification`) |
| **Lieux** (§4.13.4) | Coachs au fil de l'eau, admin gère/archive | aucun | 0 ou 1 par Session + champ libre `locationText` |

#### 4.6.1 Disciplines
- Référencées par `Session.disciplineId` et `SessionTemplate.disciplineId`.
- **Coachs créent au fil de l'eau** depuis le sélecteur de séance (raccourci « + Nouvelle discipline »). Admin gère/archive depuis les paramètres club.
- **Garde-fou minimum 1 active** : l'archivage et la suppression de la **dernière discipline active** sont bloqués (UI + serveur). Garantit qu'une création de `training` ou `competition` (où `discipline` est obligatoire) reste toujours possible.
- `AuditLog discipline_modified` à chaque opération.
- Le filtre planning « discipline » liste les disciplines actives + les archivées référencées sur la fenêtre affichée.

#### 4.6.2 Types d'épreuve
- Référencés par `Session.eventTypeId`, **uniquement pour `kind = competition`**.
- Gérés par l'**admin uniquement** depuis les paramètres club (ajout, renommage, archivage). Les coachs ne créent pas de type d'épreuve au fil de l'eau (geste de paramétrage réservé à l'admin).
- **Renommage rétroactif par ID** : l'affichage est mis à jour sur toutes les compétitions existantes sans rupture de référence.
- **Archivage soft-delete** si au moins une compétition référence le type ; **suppression dure** possible si zéro référence.
- **Garde-fou minimum 1 actif** : l'archivage et la suppression du **dernier type actif** sont bloqués (UI + serveur) — même logique que §4.6.1. Le type d'épreuve étant obligatoire à la création d'une `competition`, une liste vide rendrait la création impossible.

  > **Note** : ce garde-fou est décrit par symétrie avec les Disciplines (§4.6.1). Si la politique souhaitée est de tolérer temporairement une liste vide (ex. purge d'un type obsolète avant ajout du remplacement), il peut être assoupli — à arbitrer au cadrage admin/UX.

- `AuditLog event_type_modified` à chaque opération.

### 4.7 Modèle `Session` (training | competition | club_event)

Modèle unique avec un **discriminator `kind`**.

#### Champs communs
- `kind` (`training` | `competition` | `club_event`).
- `title`.
- `disciplineId` (FK Discipline ; **obligatoire** pour `training` et `competition`, nullable pour `club_event`).
- `startAt`, `durationMin`.
- `locationId?` + `locationText?` (le `locationText` peut surcharger un `Location` choisi).
- `capacity?` (optionnelle).
- `categoryIds[]` (par défaut : toutes les catégories actives).
- `createdBy` (FK User, **immuable** après création, tracé pour audit).
- `coaches[]` (M:N, voir §4.11 — interprété « organisateurs » pour `club_event`, « accompagnateurs » pour `competition`).
- `sourceTemplateId?` (FK `SessionTemplate` informative — purement audit, aucune propagation comportementale).
- `cancelledAt?` / `cancelledBy?` (soft flag annulation).

#### Champs spécifiques `training`
- `quotaTagId?` (0 ou 1 tag, voir §4.10).
- `contentMarkdown` (texte enrichi WYSIWYG, voir §4.12).
- `contentAttachment` (1 pièce jointe ≤ 5 Mo, formats PDF / PNG / JPG / WebP).

#### Champs spécifiques `competition`
- `eventTypeId` (FK vers le catalogue **Types d'épreuve** §4.6.2 — ex. Triathlon, Duathlon, Aquathlon, Trail). **Obligatoire** pour une `competition`.
- `distance` (texte libre).
- `externalUrl`.
- `photosAlbumUrl?` (optionnel — lien externe vers un album photos partagé, voir §4.12.5).

> La granularité de distance (S/M/L, Ironman 70.3, semi, sprint…) vit dans `distance`, pas dans le type d'épreuve. Le type décrit la *nature* de la compétition ; `distance` en précise le format quantitatif, librement saisi.

#### Champs spécifiques `club_event`
- `agenda` (texte enrichi WYSIWYG).
- `externalUrl?` (optionnel : visio, formulaire externe, page d'info).
- `photosAlbumUrl?` (optionnel — lien externe vers un album photos partagé, voir §4.12.5).

#### Champs parcours (tous `kind`, optionnels — voir §4.13)
- `routeOpenrunnerEmbedUrl?`.
- `routeOpenrunnerPublicUrl?`.
- `routeOpenrunnerId?` (dérivé du `code` opaque).
- `route?` — **référence à un parcours de la bibliothèque** (§4.20). Le GPX et ses métriques ne sont plus portés par la séance : un même parcours sert plusieurs séances, et le supprimer d'une séance ne le détruit pas.

> **Évolution 2026-08-02.** Les champs `routeGpxFile?` et `routeStats?` portés par la séance sont remplacés par cette référence. Motif : le club refait les mêmes boucles toute l'année, et un GPX par séance imposait de re-déposer le même fichier à chaque fois, sans vue d'ensemble ni possibilité de retrouver un parcours passé. Voir §4.20.

#### Création
- **Coachs et admins** peuvent créer une `Session` standalone (formulaire individuel) sur les 3 `kind`.
- **`createdBy` ≠ encadrant** : la personne qui crée n'est pas nécessairement dans `coaches[]` (voir §4.11.1).

#### Modification d'une séance avec inscriptions
- Inscriptions **conservées** (pas de reconfirmation requise).
- **Choix coach à la sauvegarde** : dialog de confirmation à **trois issues** dès qu'un champ structurant a changé —
  - (a) **Annuler les changements** : revient à la fiche sans rien sauvegarder.
  - (b) **Sauvegarder en silence** : enregistre sans envoyer de notification.
  - (c) **Sauvegarder et notifier** (push + email aux inscrits) — **défaut suggéré**.
  Champs structurants déclenchant le dialog : `startAt`, `durationMin`, `locationId/locationText`, `capacity`, `quotaTagId`, `categoryIds`, annulation.
- **Option « envoi prioritaire »** (case à cocher sous (c)) : envoie la notification **immédiatement** au lieu de la mettre en file pour l'**envoi différé par lots** (cf. §4.15.1). Décochée par défaut.
- Notif « ajout/modification de contenu » (texte, parcours, météo) : sauvegarde **silencieuse** par défaut (compléments silencieux), avec la **même** possibilité de notifier — économise une notif sur des correctifs mineurs.

#### Annulation d'une séance
- **Qui** : n'importe quel coach + admin.
- **Effet immédiat** : statut séance `cancelled`, **libère le quota** de tous les `participating` (déclenche mécanisme B §4.10), inscriptions passent en `cancelled` (soft flag), notif aux inscrits **toujours envoyée** (pas de case à cocher — événement trop structurant), `AuditLog cancel_session`.

#### Réversibilité — restauration d'une séance annulée
Tant que `startAt` n'est pas dépassé, un coach ou admin peut **réactiver** la séance depuis sa fiche :
- **Restauration naïve** : toutes les inscriptions soft-cancelled à l'annulation sont restaurées en `participating` (resp. `waitlist` avec leur raison initiale).
- **Conflit quota** : si entre-temps un athlète restauré a profité du quota libéré ou s'est ré-arbitré ailleurs et que la restauration le ferait dépasser son quota, **override silencieux** : `AuditLog override_quota` automatique (`actorId = system` ou coach, motif « restauration après annulation »). Le quota est secondaire face à la priorité de remettre les inscrits initiaux.
- **Notif aux athlètes restaurés** : push + email « la séance [X] a été réactivée, ton inscription est rétablie ».
- **Garde-fou** : restauration impossible une fois `startAt` dépassé. Au-delà, l'annulation est définitive.

#### Édition en lot
**Pas en V1.** Pour propager un changement sur plusieurs séances futures, édition séance par séance, ou annulation en lot + regénération depuis un `SessionTemplate` mis à jour.

#### Inscription anticipée
**Pas de limite haute** : un athlète peut s'inscrire à n'importe quelle distance dans le futur. L'horizon est borné de fait par la plage de génération des `SessionTemplate`.

### 4.8 Générateur de séances récurrentes (`SessionTemplate`)

**Pas de récurrence iCal (RRULE/EXDATE) en V1.** Modèle alternatif : générateur persisté qui produit des `Session` **indépendantes** en base.

**Périmètre d'accès** : **admin uniquement** pour la création / édition / archivage d'un `SessionTemplate`. Justification : un template produit N `Session` à l'enregistrement (impact potentiel sur des dizaines de séances) — geste de pilotage de saison. Les coachs continuent de créer librement des `Session` standalone.

#### Champs du template
`label`, `kind` (essentiellement `training` en V1), `disciplineId`, `dayOfWeek` (1..7), `startTimeOfDay`, `durationMin`, `locationId?`/`locationText?`, `capacity?`, `quotaTagId?`, `categoryIds[]`, **`defaultCoachIds[]`** (pré-affectation), `generationStartDate`, **`generationEndDate` (obligatoire — pas de récurrence infinie)**, `createdBy` (= admin), `status` (`active` / `archived`).

#### Comportement à l'enregistrement
- Le système **génère immédiatement N `Session` indépendantes**.
- Chaque `Session` créée a sa propre identité, son propre `createdBy` (= admin), ses propres champs.
- `Session.coaches[]` est initialisé à `defaultCoachIds[]`, modifiable ensuite séance par séance.
- **Aucun lien retour comportemental** : `Session.sourceTemplateId?` purement audit, jamais utilisé pour propager une modif.
- **Notif récapitulative unique par coach** présent dans `defaultCoachIds[]` (push + email) : *« [admin] t'a affecté comme coach sur N séances [titre] du [date début] au [date fin] »*. Évite le spam (ex. 30 séances × 3 coachs = 3 notifs, pas 90). **N entrées `ActivityLog coach_registered`** sont quand même créées (traçabilité fine).

#### Réutilisation
Le template est conservé en base, relançable (nouvelle saison, prolongation) — la regénération crée de nouvelles `Session` sans écraser les précédentes.

### 4.9 Inscriptions, capacité, liste d'attente

#### 4.9.1 Règles uniformes sur les 3 `kind`
- **Inscription et désinscription libres jusqu'au début de la séance.** Pas de délai d'ouverture, pas de délai limite.
- **Inscription / désinscription après le début bloquées** sur les 3 `kind`. Préserve le proxy de présence du quota fair-share côté `training` (cf. §4.10) et fige la liste des « déclarés présents » côté `competition` / `club_event`.
- **Désinscription = soft flag** (`status = 'cancelled'`).
- Pour les compétitions et les événements club, l'inscription est une **intention déclarée** (pas une inscription officielle au sens de l'organisateur, qui se fait via `externalUrl` ou hors-app).

#### 4.9.2 Statut unifié et sémantique
`Registration.status ∈ { participating, waitlist, cancelled }` — **uniforme sur les 3 `kind`**. La sémantique d'**engagement ferme** (place réservée par le club pour un `training`, soumis à capacité + quota + blocage post-début) vs **intention déclarée** (`competition` / `club_event`) est portée par **`Session.kind` + l'absence de `quotaTagId`** et la capacité optionnelle, **pas par la valeur de `status`**.

Les libellés UI/notifs côté athlète restent en français naturel (« ton inscription est confirmée », « tu participes à la compétition X ») — indépendants de la valeur enum interne.

#### 4.9.3 Capacité, waitlists
- **Capacité atteinte** : bouton « rejoindre la liste d'attente », `status = waitlist`, `waitlistReason = capacity`.
- **Quota dépassé** (`training` avec `quotaTagId`) : bandeau d'avertissement « Tu as déjà N séance(s) [T] cette semaine. Tu seras placé en liste d'attente. Continuer ? » → si confirmé, `status = waitlist`, `waitlistReason = quota_exceeded`.
- **Tri unique des deux waitlists** : par `timestamp d'entrée en waitlist ASC` (FIFO pur). Pas d'autre critère en V1.
- **Position waitlist + raison** visibles à l'athlète concerné, **à jour à chaque chargement / pull-to-refresh** (cf. §6).

#### 4.9.4 Visibilité des listes par séance
Pour toute `Session` des 3 `kind`, **tout membre connecté du club** voit :
- La liste **`participating`** (les inscrits actifs).
- Les **deux waitlists `capacity` et `quota_exceeded`** affichées **séparément** dans leur **ordre FIFO timestamp visible** (le rang individuel est donc dérivable par tous).

**Pas d'accès public anonyme** (authentification club requise).

**Niveau d'affichage** :
- **Entre athlètes** : prénom + initiale. **Extension automatique de l'initiale en cas d'homonymie** sur une même séance (« Marc Sa. » / « Marc Sm. ») — l'algorithme étend à la longueur minimale nécessaire pour différencier.
- **Coachs encadrants** (sur la fiche séance, partout) : prénom + nom complet (cf. §4.11.4).
- **Coachs / admins consultant** : prénom + nom complet partout.

**Exclusion** : le compteur **« N séances faites cette semaine »** par inscrit reste **vue coach/admin uniquement** (info de pilotage du fair-share — éviter la stigmatisation). Le **badge d'override coach** (cf. §4.10) reste également limité aux coachs/admins.

#### 4.9.5 Sérialisation atomique (exigence non-fonctionnelle)
Sur la dernière place disponible, deux athlètes qui cliquent « s'inscrire » en quasi-simultanéité sont traités **séquentiellement** sur la base de leur **timestamp serveur d'arrivée** : le premier obtient `participating`, le second bascule en `waitlist capacity`. **Pas de double-acceptation, jamais.** Implémentation libre (verrou pessimiste, transaction sérialisable, file persistante, compare-and-swap…) ; garantir par tests E2E.

#### 4.9.6 Conflit horaire
Si l'athlète s'inscrit à une séance qui chevauche une autre où il est déjà inscrit : **alerte + confirmation utilisateur**, pas bloquant.

#### 4.9.7 Inscription par un coach (cas légitime, athlète peu à l'aise avec l'app)
- **Athlète actif, sous quota** : inscription normale en `participating` (ou `waitlist capacity` si plein). Notif à l'athlète. `ActivityLog inscription_by_coach`.
- **Athlète actif, quota déjà atteint** : **dialog explicite au coach** « Cet athlète a déjà N/N séance(s) [T] cette semaine. Choisis : (a) Placer en `waitlist quota_exceeded` (cohérent fair-share) / (b) Forcer (override §4.10) ». Le coach décide. Si override, `AuditLog override_quota` avec motif optionnel.
- **Athlète avec `athleteAccessSuspended = true`** : self-service bloqué, mais **bypass coach via override §4.10** possible.

### 4.10 Quota fair-share et mécanismes A/B/C

Mécanisme central pour éviter qu'un même athlète monopolise les places sur des séances à capacité fortement limitée (ex. natation).

#### 4.10.1 Concepts
- **Tag de quota** : étiquette configurable attachée à une `Session` (ex. `piscine`, `home-trainer-collectif`, `endurance`). **0 ou 1 tag par séance en V1**. **Universel** : pas de restriction de discipline portée par le tag.
- **`maxPerWeek`** : nombre d'inscriptions `participating` autorisées **par tag, par athlète, par semaine** (entier ≥ 1, paramétrable, défaut 1).
- **Semaine** : lundi 00:00 → dimanche 23:59 dans le fuseau du club (paramétrable §4.17).
- **Compteur hebdo unique par tag**, additionnant les inscriptions toutes disciplines confondues (ex. tag `endurance` `maxPerWeek=2` plafonne à 2 séances « endurance » dans la semaine, qu'elles soient course, vélo ou natation).

**Exigence non-fonctionnelle** : l'évaluation du quota est en **temps quasi-constant** à l'inscription, indépendamment du nombre d'inscriptions historiques de l'athlète.

#### 4.10.2 Modèle hybride
- **Check d'inscription** : compte les inscriptions `participating` de la semaine (futures + passées) pour décider d'accepter ou de placer en waitlist.
- **Priorité de promotion depuis waitlist** : compte les inscriptions `participating` sur séances **passées** uniquement (proxy de présence en l'absence de pointage V1).

#### 4.10.3 Algorithme à l'inscription
```
soit T = tag de quota de la séance (s'il y en a un, sinon null)
soit N = maxPerWeek(T)
soit C = nb d'inscriptions 'participating' de l'athlète sur les séances
         portant le tag T de la semaine courante (futures + passées)

si T est null :
    appliquer la logique standard (capacité + waitlist FIFO)
    fin

si C < N :
    si capacité disponible → status = 'participating'
    sinon                  → status = 'waitlist', reason = 'capacity'
sinon (C >= N) :
    afficher bandeau d'avertissement à l'athlète
    si confirmé → status = 'waitlist', reason = 'quota_exceeded'
    sinon       → annule l'inscription
```

#### 4.10.4 Trois mécanismes de promotion

##### Mécanisme A — Auto-promotion sur place libérée (désistement d'autrui, annulation…)
À chaque place libérée :
```
si la file 'capacity' de la séance est non vide :
    promouvoir le 1er FIFO timestamp → status = 'participating'
    notifier l'athlète promu (push + email)
    répéter tant qu'il reste des places ET des athlètes en 'capacity'
sinon :
    la file 'quota_exceeded' n'est PAS touchée par ce mécanisme.
    Elle reste en attente d'un déblocage manuel (mécanisme C).
```

##### Mécanisme B — Auto-promotion silencieuse sur libération de son propre quota
Si l'athlète A se désinscrit d'une séance du tag T cette semaine (ou si la séance est annulée), pour chacune de ses inscriptions `waitlist quota_exceeded` sur **une autre séance du même tag dans la même semaine** :

- **Cas (a) — capacité disponible** : bascule auto en `participating`. Notif push à A : *« ton inscription à [X] est confirmée »*. `ActivityLog auto_promoted_self_quota` avec `resultingStatus = 'participating'`.
- **Cas (b) — capacité pleine** : **migration** de `waitlist quota_exceeded` → `waitlist capacity`. Le quota n'est plus saturé pour A, c'est la capacité qui bloque. **A conserve son `registeredAt` initial** (FIFO timestamp ASC pur) et devient éligible à une promotion mécanisme A. Notif push à A : *« ton quota est libéré sur [X] mais la capacité est pleine — tu es désormais en liste d'attente pour place libérée (position N) »*. `ActivityLog auto_promoted_self_quota` avec `resultingStatus = 'waitlist_capacity'`.

Le mécanisme B ne dépend pas du timestamp FIFO de la file `quota_exceeded` pour décider **si** A est promu — c'est A qui libère son propre quota. Les autres athlètes en `quota_exceeded` derrière A restent en attente d'un déblocage coach.

##### Mécanisme C — Déblocage manuel coach de `quota_exceeded`
Action globale **« Remplir avec quota_exceeded »** sur la séance :
- **Précondition stricte** : `capacity` vide ET places restantes (bouton désactivé sinon, infobulle).
- Au clic, le système promeut **autant d'athlètes de `quota_exceeded` que de places restantes**, en FIFO timestamp.
- Champ « motif » **optionnel** partagé pour tout le batch.
- `AuditLog` : **N entrées individuelles** `action = 'promote_quota_exceeded'`, une par athlète promu (`actorId` = coach, `targetId` = athlète promu, `sessionId`, `motif` partagé).
- Notif push + email aux athlètes promus.
- Le compteur de quota des athlètes promus s'incrémente naturellement (effet recherché).

> **Non-conflit A/B/C** : A pioche dans `capacity` sur place libérée, B traite le propre quota libéré par l'athlète sur sa propre file `quota_exceeded`, C est une action coach explicite sur la file `quota_exceeded` quand `capacity` est vide. Périmètres disjoints.

#### 4.10.5 Override coach
Le coach peut **forcer une inscription `participating`**, en outrepassant quota et/ou capacité.

- **Distinct du mécanisme C** : l'override sert à inscrire un athlète qui n'est **pas en file** OU à dépasser strictement la capacité max ; pas la voie normale pour piocher dans `quota_exceeded`.
- **Pas de limite logicielle** : ni nombre max d'overrides par séance, ni plafond de débordement au-dessus de `capacity`. La capacité max devient indicative. La discipline humaine et la traçabilité priment. UX : la fiche séance affiche « N inscrits (capacité M, surcapacité +K) » dès dépassement.
- **Cas d'usage** :
  - Inscrire un athlète qui n'est pas en file.
  - Dépasser la capacité max stricte.
  - Inscrire un athlète suspendu (`athleteAccessSuspended=true`).
  - Inscrire un athlète qui dépasse son quota hebdo (via le dialog §4.9.7).
- **Compte dans le compteur de quota** de l'athlète (pas un bonus hors quota).
- `AuditLog action = 'override_quota'`, motif **optionnel**.
- Badge + motif visibles à **coachs + admins** (transparence interne). Athlètes ne voient pas le badge.

#### 4.10.6 Cycle de vie du quota
- **Désistement avant le début** (autorisé librement, cf. §4.9) : libère la place ET libère 1 unité de quota → déclenche le mécanisme B.
- **Désistement après le début** : bloqué côté UI.
- **Annulation de la séance par le coach** : libère le quota de tous les `participating`.
- **Pas de cron de reset** : le quota se recalcule naturellement sur la semaine courante.

#### 4.10.7 UX et transparence
- Bandeau d'avertissement systématique avant validation d'une inscription qui partira en waitlist pour cause de quota.
- **Compteurs visibles dans le profil athlète, regroupés par tag** (et non par discipline) : « cette semaine : 1/1 piscine, 0/2 home-trainer-collectif, 2/3 endurance ». Les séances sans tag n'apparaissent dans aucun compteur.
- **Vue coach** : sur la liste des inscrits/waitlist, indication visuelle du statut (`quota_exceeded` vs `capacity`) et du nb de séances déjà faites cette semaine.
- **Vue coach (gestion de séance)** : bouton **« Remplir avec quota_exceeded »** disponible uniquement si `capacity` vide ET places restantes. Désactivé sinon. Au clic, modale de confirmation : nombre d'athlètes qui seront promus, liste nominative, champ « motif » optionnel partagé, action « Confirmer » / « Annuler ».

#### 4.10.8 Cas limites
- **Semaine N→N+1** (dimanche soir / lundi matin) : compteurs distincts.
- **Séance à cheval** entre deux semaines : appartient à la semaine de sa date de début.
- **Modification du `maxPerWeek` d'un tag** : inscriptions existantes pas remises en cause, seule la prochaine évaluation utilise le nouveau quota.
- **Ajout d'un tag a posteriori** sur une séance avec inscrits : **recalcul automatique** ; les inscrits qui dépassent le nouveau quota sont remis en `waitlist quota_exceeded` avec notif.
- **Changement de discipline d'une séance déjà taguée** : aucun effet de bord (tag universel).
- **Même tag sur disciplines différentes** : compteur additionne toutes disciplines confondues. Pas de garde-fou logiciel sur la cohérence du tagging — la mitigation repose sur des libellés clairs côté admin.

### 4.11 Encadrement : inscription coach et qualifications

> **Périmètre : `kind = 'training'` uniquement.** Pour `competition` et `club_event`, `coaches[]` est saisie librement par le créateur (« accompagnateurs » / « organisateurs »), sans mécanisme d'inscription, sans bloc qualifications agrégées sur la fiche, sans comptage dans les stats §4.16.

#### 4.11.1 Créateur vs encadrants
- **`Session.createdBy`** : FK User, audit, **immuable** après création.
- **`Session.coaches[]`** : liste M:N des coachs **inscrits comme encadrants**. Détermine : qui apparaît sur la fiche séance, quelles qualifs sont agrégées (§4.11.4), quelles séances comptent dans les stats d'activité (§4.16).
- **À la création** d'une `training`, le formulaire propose un **multi-sélecteur de coachs encadrants** parmi les `User` ayant le rôle `coach` :
  - Si le créateur a le rôle `coach`, la case **« M'inscrire comme coach sur cette séance »** est **cochée par défaut** (décochable). Un admin pur ne se voit pas proposer cette auto-affectation.
  - **Tout coach + tout admin créateur peut ajouter d'autres coachs** au sélecteur.
  - À l'enregistrement, chaque coach autre que le créateur auto-affecté reçoit une **notif immédiate** (push + email) et une entrée `ActivityLog coach_registered` (`actorId` = créateur, `userId` = coach affecté).

#### 4.11.2 Inscription / désinscription comme coach
**Qui peut être inscrit ?** Tout `User` ayant le rôle `coach` (pas de cloisonnement par catégorie ni discipline — coach global).

**Trois voies d'inscription équivalentes** :
- **Voie 1 — Affectation au formulaire de création / génération** : à la création d'une `Session` standalone (cf. §4.11.1) ou d'un `SessionTemplate` (admin uniquement, cf. §4.8).
- **Voie 2 — Self-inscription post-création** : bouton **« S'inscrire comme coach »** sur la fiche séance (visible aux coachs pas encore dans `coaches[]`).
- **Voie 3 — Inscription d'un autre coach par un tiers post-création** : tout coach + tout admin (y compris admin pur) peut inscrire n'importe quel autre coach via le bouton **« Inscrire un coach »** sur la fiche séance. **Pas de workflow d'acceptation** : le coach inscrit reçoit une **notif immédiate** *« tu as été inscrit comme coach sur [X] par [Y] »* et conserve le droit de se désinscrire librement.

**Désinscription** :
- **Self-désinscription** : bouton **« Me retirer de l'encadrement »** sur la fiche séance, tant que la séance n'a pas commencé.
- **Désinscription d'un autre coach par un tiers** : tout coach + tout admin peut retirer un autre coach (symétrique à l'inscription tierce). Notif au coach concerné.
- **Dernier coach** : la désinscription du **dernier coach inscrit** est autorisée, mais un **dialog de confirmation explicite** apparaît : *« [Coach X] est le seul coach inscrit. La séance se retrouvera sans encadrement après son retrait. Continuer ? »*. Responsabilité humaine prime.

**Pas d'alerte programmée V1** sur séance sans coach (pas de cron / scheduler en V1). Détection par deux signaux in-context :
- **Bandeau d'alerte douce** sur la fiche séance (§4.11.4).
- **Compteur « séances futures sans coach »** dans le dashboard admin (§4.16).

**Droits opérationnels inchangés** : l'inscription comme coach **ne confère aucun droit supplémentaire** par rapport au rôle `coach` global. Tout coach du club (inscrit ou non sur la séance) peut éditer la séance, déclencher un override, déclencher le mécanisme C. La présence dans `coaches[]` sert uniquement à : afficher qui anime, agréger les qualifications, alimenter les stats d'activité.

**Traçabilité** : chaque action génère une entrée `ActivityLog` (`coach_registered` ou `coach_unregistered`, sans motif). `actorId` = utilisateur qui déclenche, `userId` = coach concerné — **peuvent différer** en cas d'inscription/désinscription par un tiers.

**Notifs** : à chaque action, push + email aux **autres coachs déjà inscrits** + à **l'admin**. Si l'action est déclenchée par un tiers, notif **également au coach concerné**.

#### 4.11.3 Qualifications coach
**Catalogue paramétrable** (admin). Champs minimaux : `label` libre, `code` court optionnel (ex. `BNSSA`). **Seed au déploiement** : `BF1`, `BF2`, `BF3`, `BF4`, `BF5`, `BNSSA`, `MNS`, `PSC1`, `PSE1`, `AFPS`.

**Attribution** : entité `UserQualification(userId, qualificationId, expiresAt?, attributedAt, attributedBy)`. `expiresAt` **optionnel par attribution** (certaines qualifs ont une validité réglementaire — BNSSA 5 ans, PSC1 annuel — d'autres non).

**Saisie** :
- **Par le coach lui-même** depuis son profil (page « Mes qualifications »).
- **Par l'admin** depuis la fiche utilisateur.
- **Pas de workflow de validation** : confiance.

**Badges d'expiration** (sur profil et fiche séance) :
- `expiresAt` nul : pas de badge temporel.
- `expiresAt` futur lointain : qualif normale, date en infobulle.
- `expiresAt` < **30 jours** : badge « expire bientôt » (alerte douce).
- `expiresAt` passé : badge « expirée » (alerte forte). Qualif reste affichée mais visuellement marquée.

**Pas de blocage de l'inscription coach** sur l'expiration. Information seulement.

**Archivage** : soft delete si référencée par au moins une `UserQualification`. Suppression dure possible si zéro référence.

#### 4.11.4 Affichage sur la fiche séance (`training`)
Bloc **« Encadrement »** :
- **Liste nominative** des coachs inscrits (`coaches[]`) — prénom + nom complet (**visibilité publique** à tout membre connecté, contrairement aux athlètes affichés en prénom + initiale, cf. §4.9.4).
- Bloc **« Qualifications disponibles »** : **chips agrégés uniques** des qualifs des coachs inscrits (déduplication par `qualificationId`). Au tap / hover d'un chip : détail des coachs qui portent cette qualif + badge d'expiration éventuel.
- **Si `coaches[] = ∅`** : **bandeau d'alerte douce** « Pas de coach inscrit pour le moment ». S'efface dès qu'un coach s'inscrit. Pas de blocage d'inscription athlète.

Sur `competition` / `club_event` : liste nominative des `coaches[]` (« accompagnateurs » / « organisateurs ») — pas de bloc qualifs, pas de bandeau « pas de coach ».

#### 4.11.5 Bascule athlète ↔ coach sur la même séance (4 cas symétriques)
Toute tentative d'inscription dans un rôle pour un utilisateur **déjà inscrit dans l'autre rôle sur la même séance** déclenche un **dialog de confirmation explicite** décrivant la bascule + ses conséquences directes + les cascades utilisateur visibles. **Aucune bascule implicite, jamais.**

- **Cas 1 — Self, athlète → coach** : un athlète inscrit clique « S'inscrire comme coach » sur la fiche séance. Dialog : *« Tu es actuellement inscrit comme athlète sur cette séance. Pour t'inscrire comme coach, ton inscription athlète sera annulée — ta place et ton quota seront libérés. Conséquences possibles : un athlète en `waitlist capacity` pourra être promu automatiquement (mécanisme A) ; si tu étais en `waitlist quota_exceeded` sur une autre séance du même tag cette semaine, ton inscription pourra y basculer en `participating` automatiquement (mécanisme B). Continuer ? »*. À la validation : `Registration` soft-cancelled + ajout à `coaches[]`.
- **Cas 2 — Self, coach → athlète** : un coach inscrit clique « S'inscrire comme athlète ». Dialog : *« Tu es actuellement inscrit comme coach sur cette séance. Pour t'inscrire comme athlète, ton inscription coach sera retirée. [Si dernier coach inscrit, ajout : "La séance se retrouvera sans encadrement après ce changement."] Continuer ? »*. À la validation : retrait de `coaches[]` + inscription comme athlète selon §4.9 (statut résultant selon capacité + quota).
- **Cas 3 — Par tiers, coach → athlète** : un tiers (coach C2 ou admin) inscrit C1 (déjà dans `coaches[]`) comme athlète. Dialog **au tiers** symétrique au cas 2. Notif à C1 push + email.
- **Cas 4 — Par tiers, athlète → coach** : un tiers inscrit C1 (déjà inscrit comme athlète) comme coach. Dialog **au tiers** symétrique au cas 1. Notif à C1 push + email.

**Garde-fou « dernier coach »** (cas 2 et 3) : warning supplémentaire dans le dialog si la bascule retire le dernier coach inscrit. Bascule autorisée — responsabilité humaine. Pas de symétrique « dernier athlète » (retirer un athlète libère simplement une place).

#### 4.11.6 Retrait du rôle `coach` par un admin
- **Maintien sur les séances en cours** : les inscriptions `coaches[]` existantes sur des séances futures sont **conservées** jusqu'au début de chaque séance (continuité opérationnelle).
- **Refus implicite des nouvelles** : le user ne peut plus s'inscrire comme coach sur de nouvelles séances après le retrait du rôle, et ne peut plus en inscrire d'autres.
- Pour retirer le user des `coaches[]` futurs, l'admin utilise la voie « Désinscrire un coach par un tiers ».
- `AuditLog role_changed`.

### 4.12 Contenu de séance et édition WYSIWYG

#### 4.12.1 Champ texte enrichi (`contentMarkdown`, `agenda` de club_event, futurs champs équivalents)
- **Mode d'édition : WYSIWYG à barre d'outils.** Pas de syntaxe markdown brute exposée. Public cible : coachs et admins majoritairement bénévoles.
- **Stockage interne en markdown** (portabilité, export).
- **Périmètre exposé** (volontairement restreint pour cohérence visuelle et minimisation XSS) :
  - Gras, italique, barré.
  - Listes à puces et numérotées (avec imbrication).
  - Liens hypertexte (URL externe).
  - Titres h2 et h3 (pas de h1 — réservé au titre de la séance/événement).
  - Citation (blockquote).
- **Hors V1** : tableaux, images inline (la pièce jointe couvre le besoin média), couleurs, polices, blocs de code, vidéos embed, alignement, listes de tâches.
- **Rendu côté lecteur** : sanitisé, neutre, sans styles personnalisés au-delà du thème club. Liens externes : `target="_blank" rel="noopener noreferrer"`.
- **Accessibilité** : raccourcis clavier standards (Ctrl/Cmd+B, +I, +K pour lien), focus visible, nav clavier dans la barre d'outils.

#### 4.12.2 Pièce jointe
- **1 fichier, taille max 5 Mo**. Formats : PDF, PNG, JPG/JPEG, WebP. Pas de GIF animé, pas de HEIC (conversion côté client si upload iPhone, comportement à arbitrer techniquement).

#### 4.12.3 Visibilité
**Visible à tous les membres connectés du club** (athlètes, coachs, admins, parents) — qu'ils soient inscrits à la séance ou non. Pas d'accès public anonyme. La pièce jointe suit la même règle (cohérence : une seule règle pour toute la fiche). Voir n'est pas s'inscrire — le mécanisme d'inscription/quota reste inchangé.

#### 4.12.4 Édition après début / après fin
**Libre.** Le coach peut compléter ou corriger le contenu en cours de séance (correctifs derniers) ET après la fin (notes post-séance, retours, photos). Notif via la même case à cocher §4.7 pour permettre des compléments silencieux. Distinct du blocage d'**inscription/désinscription** post-début (§4.9) qui reste strict.

#### 4.12.5 Débriefs de compétition (`Debrief`)

Sur une séance `kind = competition`, chaque membre **ayant participé** peut publier son **débrief** : un retour personnel sur la compétition, en texte enrichi. Distinct du `contentMarkdown` (réservé `training`, rédigé par le coach) : le débrief est **rédigé par l'athlète**, signé, et il peut y en avoir plusieurs par compétition.

**Auteur et cardinalité**
- Auteur = un `User` ayant une `Registration` `participating` sur la compétition.
- **0..N débriefs par compétition**, **au plus 1 par (compétition, auteur)**. Pas d'entité « participation » dupliquée : unicité `(sessionId, authorId)`.

**Champ texte** : texte enrichi **WYSIWYG**, réutilisant **exactement le périmètre exposé §4.12.1** (gras, italique, barré, listes, liens, h2/h3, citation), stockage markdown, **sanitisation serveur faisant foi**. Pas de pièce jointe en V1 (les photos vivent dans l'album externe, voir §4.12.6).

**Cycle de vie et droits**
- **Création** : par l'auteur, **uniquement une fois la compétition commencée** (`startAt` dépassé) — c'est un débrief *après* la course. Le bouton « Rédiger mon débrief » n'apparaît qu'aux participants, après `startAt`.
- **Édition** : par l'**auteur** (libre, à tout moment) **et** par l'**admin**.
- **Archivage / réactivation** : **admin uniquement**, en **soft-delete** (`archivedAt` / `archivedBy`) — pas de suppression dure dans l'UI admin standard. Un débrief archivé **disparaît de la liste publique** mais reste consultable et **restaurable** par l'admin. Cohérent avec le pattern d'archivage des catalogues (§4.6) et des `SessionTemplate` (§4.8).

**Visibilité** : tous les **membres connectés** du club (athlètes, coachs, admins, parents), inscrits ou non à la compétition. Pas d'accès public anonyme. L'auteur est affiché avec la **même convention que partout dans l'app** (§4.9.4) : un athlète consultant voit **prénom + initiale du nom** (étendue à deux lettres en cas d'homonymie), un coach/admin consultant voit prénom + nom complet. Pas de traitement « signature » particulier.

**Notification** : à la **publication** d'un débrief, push + email aux **autres participants** de la compétition (nouveau type de la matrice §4.15, opt-out cellule par cellule). L'**édition** ultérieure ne renotifie pas (même esprit que les compléments silencieux §4.12.4).

**RGPD** : à l'anonymisation d'un compte (§4.3), `authorId` est anonymisé comme les autres références (`anon:user:<hash>`) ; **le texte du débrief est conservé** (valeur pour le club), aligné sur le traitement des inscriptions anonymisées. Recommandation aux rédacteurs : éviter de citer le nom de tiers ou des informations de santé (recommandation, pas de validation algorithmique — cf. §4.12.1).

#### 4.12.6 Album photos externe (`photosAlbumUrl`)

Une `competition` ou un `club_event` peut porter un **lien externe vers un album photos** partagé (typiquement Google Photos), publié **en amont** par l'organisateur club et **alimenté hors-app** par les participants.

- **1 seule URL** par séance, **optionnelle**, saisie/éditée par **coach + admin** (le créateur), au formulaire de création/édition de séance.
- **Aucune intégration** : pas d'embed, pas d'API, aucun appel serveur vers l'URL, aucune photo stockée par l'app. Simple lien ouvert `target="_blank" rel="noopener noreferrer"` (cohérent §4.12.1).
- Distinct d'`externalUrl` (qui pointe l'organisateur / le formulaire d'inscription) : `photosAlbumUrl` = galerie partagée.
- **Visibilité** : tous les membres connectés du club.

### 4.13 Parcours (OpenRunner Pro + GPX) et météo

Toutes les séances peuvent **optionnellement** porter des informations de parcours.

#### 4.13.1 OpenRunner Pro — embed iframe officiel
**Principe** : la fiche séance affiche le parcours via le **widget iframe officiel d'OpenRunner Pro** — carte interactive (zoom, déplacement, profil altimétrique) visible **dans la fiche** sans clic supplémentaire. **iframe HTML standard**, pas de SDK JS à charger ni d'API REST côté serveur.

**Compte OpenRunner Pro = prérequis assumé du club déployant**. Un club self-hosté souscrit son propre compte OR Pro (à documenter dans le guide d'installation, comme le client Google OAuth et le fournisseur d'email transactionnel).

**Pas de credential OpenRunner stockée côté app.** Aucune API key, aucun OAuth, aucun champ de configuration admin V1 dédié à OR.

**Deux champs côté `Session`, indépendants** (l'un, l'autre, ou les deux ; chacun rendu selon qu'il est renseigné) :
- `routeOpenrunnerEmbedUrl?` (optionnel) : URL `src` du widget d'embed, format `https://www.openrunner.com/embed.html?code=<token_opaque>`. Le `code` est un **token opaque chiffré** généré par OR Pro, **non dérivable** depuis l'URL publique. Le coach extrait cette URL depuis OR Pro (parcours > Partager > Embed > copier l'URL `src`). Renseigné ⇒ **carte iframe** affichée dans la fiche.
- `routeOpenrunnerPublicUrl?` (optionnel) : URL publique du parcours `https://www.openrunner.com/r/<id_numerique>`. Renseigné ⇒ **bouton permanent** « Ouvrir sur OpenRunner » (nouvel onglet, `target="_blank" rel="noopener noreferrer"`), indépendamment de la présence ou du chargement de l'iframe.

> Les deux champs ne se dérivent **pas** l'un de l'autre (le `code` opaque de l'embed n'est pas l'`id` numérique public). La section « Parcours » s'affiche dès qu'au moins l'un des deux (ou un GPX) est renseigné.

**Stockage : URL `src` uniquement, jamais le bloc iframe HTML brut.** Stocker du HTML brut ouvrirait une porte XSS (un coach malveillant ou un compte compromis pourrait substituer n'importe quel HTML/JS) et ferait perdre le contrôle uniforme du rendu. L'app **régénère l'iframe côté client à chaque affichage** avec attributs figés (`width="100%"`, `height="650"`, `loading="lazy"`, `style="border:none"`, éventuellement `sandbox` selon ce qu'OR exige).

**Validation serveur stricte (whitelist)** :
- Sur `routeOpenrunnerEmbedUrl` : `https` + hostname **exact** `www.openrunner.com` + path **exact** `/embed.html` + param `code` présent et non vide. Tout écart = refus avec message *« Lien d'embed OpenRunner invalide — colle l'URL `src` issue de la fonctionnalité Embed d'OR Pro »*.
- Sur `routeOpenrunnerPublicUrl` : `https` + hostname `www.openrunner.com` (sans contrainte de path — OR utilise plusieurs schémas d'URL publique).
- Validation symétrique côté client (feedback immédiat), serveur fait foi.

**Conséquence RGPD** : aucune donnée personnelle de l'application n'est transmise à OR ; l'iframe expose à OR l'**IP** et l'**user-agent** de l'utilisateur consultant (comportement standard d'embed tiers). À mentionner dans la politique de confidentialité (rédaction différée pré-prod).

**Fallback si l'iframe échoue à charger** (parcours retiré, compte Pro expiré, OR en maintenance, CSP) : message *« Carte indisponible »* à la place de la carte. Le bouton « Ouvrir sur OpenRunner » (si `routeOpenrunnerPublicUrl` renseignée) reste affiché en dessous et couvre l'accès au parcours. Pas de retry automatique.

#### 4.13.2 GPX
- Upload direct, max **5 Mo** (côté client ET serveur).
- **Parsing client uniquement, jamais serveur** (surface d'attaque réduite). Le client extrait distance totale, D+/D-, alt min/max, nb points, durée estimée, et envoie les métadonnées + fichier brut.
- Affichage du tracé sur fond OpenStreetMap.
- **Téléchargement visible à tous les membres connectés** du club (cohérence §4.12 — pas d'info de fiche séance restreinte aux inscrits).
- **Le GPX déposé sur une séance alimente la bibliothèque de parcours** (§4.20) : la séance le *référence*, elle ne le possède pas. Retirer le parcours d'une séance ne supprime donc jamais le fichier.

#### 4.13.3 Coexistence OR + GPX
Les deux sources peuvent coexister sur une même séance (URL OR Pro **et** GPX). Elles sont alors présentées en **deux onglets**, et c'est le **tracé GPX qui s'ouvre par défaut** (arbitré le 2026-08-15) : c'est la source que le club maîtrise — hébergée chez lui, sans dépendance à un compte tiers ni à un jeton d'embed qui peut expirer, et accompagnée de ses métriques. La carte OpenRunner reste accessible en un clic, et **n'est chargée qu'à l'ouverture de son onglet** — un appel tiers de moins sur chaque consultation de fiche, cf. la conséquence RGPD du §4.13.1.

#### 4.13.4 Lieux et géocodage
**Bibliothèque `Location`** (cf. §4.6) : nom, adresse, latitude, longitude, type, notes, `is_archived`. **Coachs créent au fil de l'eau**, **admin gère/archive**.

À la création de séance, le coach choisit dans la liste OU tape un `locationText` libre (géocodé via un service ouvert). **Override par séance** : surcharge possible du `locationText` même en partant d'un `Location`.

**Saisie assistée de l'adresse (gestion des `Location`)** : un champ unique propose une **autocomplétion** — la recherche porte sur une **adresse OU un nom de lieu** et affiche des suggestions au fil de la frappe (fournies par un service de géocodage ouvert), présentées de façon lisible (**nom du lieu en titre, adresse en dessous, type de lieu**) plutôt qu'en chaîne brute concaténée. La **sélection d'une suggestion auto-remplit** nom (si vide), adresse, type (si déductible) et coordonnées (latitude/longitude). Les coordonnées restent éditables à la main si besoin. Évaluation en temps quasi-constant attendue (suggestions mises en cache, requêtes limitées pour respecter la politique d'usage du service).

**Aperçu cartographique** : dès qu'un `Location` est géocodé, sa **localisation est affichée sur une carte** (marqueur) — dans le **formulaire de lieu** (recentrée en direct à la sélection d'une suggestion) **et en consultation de séance** (bloc « Lieu » de la fiche, uniquement si le lieu est géocodé).

**Échec / lieu non trouvé** : si aucune suggestion ne correspond, **saisie manuelle de lat/lng** possible directement dans les champs coordonnées. Pas de fallback en cascade.

#### 4.13.5 Météo prévisionnelle
- **Source** : service **gratuit ou peu coûteux**, **hébergé UE**, licence permissive, idéalement sans clé d'API. **Cible : Open-Meteo** (CC BY 4.0). Mention obligatoire de la source en pied de cartouche si Open-Meteo retenu.
- **Paramètres affichés** : température 2 m, probabilité de précipitations, précipitations (mm), vent (km/h + direction), code météo (pictogramme).
- **Logique** : affichage sur séances géocodées uniquement, et seulement si la séance est dans la fenêtre **J-16**. Si lieu non géocodé → cartouche masquée. Si > 16 j → cartouche absente, note discrète.
- **Cache serveur 3h** par couple `(lieu, créneau horaire)` pour ne pas marteler la source. Pré-calcul périodique pour les séances de la fenêtre.

### 4.14 Flag « j'offre l'apéro »

Tradition club : au moins une fois par semaine, un adhérent paye l'apéro post-séance (anniversaire, podium, record, mutation pro…). Affordance simple sur la fiche séance, sans alourdir le modèle ni créer de pression sociale.

#### 4.14.1 Périmètre
- **3 `kind` couverts** (`training`, `competition`, `club_event`).
- **Qui peut flagger ?** Uniquement un `User` dont la `Registration` est **active** (`status = 'participating'`). Pas de flag depuis une `waitlist` (promesse implicite « je serai là pour offrir »). Si l'inscription bascule de `waitlist` à `participating` (mécanismes A/B/C), l'athlète refait le geste s'il le souhaite.
- **Pas de flag par procuration** : un coach / admin ne peut pas flagger un athlète à sa place (le geste est personnel — engagement, pas attribution). Pas de pouvoir parent garant.

#### 4.14.2 Contenu et cardinalité
- **Plusieurs flags simultanés** sur la même séance, sans plafond (tournées partagées).
- **Un seul flag par utilisateur par séance** (unicité `(sessionId, userId)`).
- **Motif texte libre optionnel** (`AperoFlag.motif`, max **140 caractères**) : précise la raison (« mon anniversaire », « podium dimanche »). Affiché en infobulle. Recommandation documentaire : pas d'infos confidentielles ni de noms tiers.

#### 4.14.3 Fenêtre temporelle
- **(Dé)flag libre jusqu'au début de la séance** (`Session.startAt`).
- **Bloqué après `startAt`** — règle uniforme avec la fenêtre d'inscription/désinscription §4.9.

#### 4.14.4 Cycle de vie : retrait du flag
Trois voies, toutes tracées en `ActivityLog apero_unflagged` :
- **Voie 1 — Self-déflag** : le payeur retire son propre flag. `actorId = userId`.
- **Voie 2 — Modération coach / admin** : tout `User` ayant le rôle `coach` ou `admin` peut retirer le flag d'un athlète (bouton « Retirer ce flag » sur chaque entrée de la liste des payeurs, visible côté coach/admin uniquement). Cas d'usage : blague, doublon non intentionnel, malentendu. Pas de motif structuré requis.
- **Voie 3 — Cascade automatique sur perte d'inscription active** : dès que la `Registration` perd `participating`, le flag est **supprimé**. Cas couverts :
  - Désinscription self ou par un coach.
  - Annulation de la séance (cascade sur **tous** les flags).
  - Bascule de saison (`bulk_athlete_deactivation`).
  - Bascule athlète → coach sur la même séance.
  - `actorId = system`.

**Réversibilité** : à la **restauration** d'une séance annulée, les `AperoFlag` cascadés sont **également restaurés** (cohérent avec la restauration des inscriptions). `ActivityLog apero_flagged` avec `actorId = system`. Si entre-temps l'athlète a perdu son inscription active pour une autre raison, le flag n'est pas restauré.

#### 4.14.5 Affichage
Visibilité **publique** à tous les membres connectés (cohérent avec §4.9.4). Pas d'accès public anonyme. Niveau :
- **Athlètes consultant** : prénom + initiale (extension auto homonymes).
- **Coachs / admins consultant** : prénom + nom complet.
- **Motif libre** : infobulle au survol / tap. Tronqué visuellement à ~60 caractères avec « … » + infobulle complète au-delà.

Trois affordances UI (sémantique figée, pictogramme et placement à arbitrer maquettes) :
1. **Planning compact** (4 vues §4.7) : **icône bien visible** sur la pastille de séance dès qu'au moins un flag actif existe.
2. **Fiche séance détaillée** : section dédiée **« Apéro offert par »** + bouton « J'offre l'apéro » / « Je ne l'offre plus » pour les inscrits actifs. Coachs / admins voient en plus un bouton **« Retirer ce flag »** par entrée.
3. **Home athlète** (« mes prochaines séances ») : badge sur chaque carte où l'athlète est inscrit dès qu'au moins un payeur est flaggé.

#### 4.14.6 Notifications
**Aucune notification** envoyée à un (dé)flag. Diffusion par consultation du planning. Évite de surcharger la matrice §4.15.

#### 4.14.7 Traçabilité
- `ActivityLog` actions `apero_flagged` / `apero_unflagged`.
- Pas de motif structuré dans le journal : le motif vit sur l'entité `AperoFlag`.
- **Pas de double trace en `AuditLog`** : l'apéro n'est pas une action de gouvernance.
- Anonymisation à la suppression de compte selon §4.18.

### 4.15 Notifications

#### 4.15.1 Canaux
- **Push** : web push (PWA installée).
- **Email** : transactionnel hébergé UE.

Chaque canal peut être **fermé à l'échelle du club** (§4.17) : un club n'a pas forcément les moyens
techniques du push, ni de fournisseur d'email. L'interrupteur s'applique **en amont** des préférences
individuelles (§4.15.3) — un canal fermé ne part pour personne, quelle que soit la matrice, et les
préférences déjà exprimées sont conservées telles quelles pour une éventuelle réactivation. Les deux
canaux peuvent être fermés simultanément ; les emails d'authentification (§4.1.1) ne sont jamais
concernés, sans quoi la connexion deviendrait impossible.

**Mode d'envoi** : par défaut, les notifications partent en **envoi différé par lots** (la latence de livraison reste bornée par la période de traitement). Une option **envoi immédiat** est proposée aux points de déclenchement (ex. modification de séance §4.7) et depuis l'écran de gestion des envois (§4.15.6), pour pousser sans attendre le lot suivant. L'évaluation des préférences (§4.15.3), de la pause (§4.15.4) et du routage parent/enfant (§4.15.5) est identique dans les deux modes.

#### 4.15.2 Types
- Annulation de séance.
- Promotion depuis la liste d'attente (mécanismes A, B, C — un seul opt-out couvre les trois).
- Inscription forcée par un coach (override).
- Modification de séance (date, heure, lieu).
- Ajout/modification de contenu de séance (sous-types texte, parcours, météo).
- Création d'une compétition ou d'un événement club ciblant une catégorie de l'utilisateur.
- **Nouveau débrief sur une compétition à laquelle tu participes** : push + email aux autres participants à la publication d'un débrief (cf. §4.12.5).
- **Inscription / désinscription d'un coach** sur une `training` : push + email aux **autres coachs déjà inscrits** + à **l'admin**. Si l'action est déclenchée par un tiers, **également au coach concerné** (distinct de l'`actorId`). Pas de notif aux athlètes inscrits (sauf si une modification de séance est par ailleurs déclenchée).
- **Affectation d'un coach au formulaire de création d'une `Session`** : notif immédiate push + email à chaque coach autre que le créateur auto-affecté.
- **Affectation via `defaultCoachIds[]` à la génération en lot d'un `SessionTemplate`** : **notif récapitulative unique par coach** (cf. §4.8 — évite le spam).
- **Accès athlète suspendu** : **pas d'email ni de push** à la suspension de masse → **bannière in-app persistante** à la prochaine connexion (cf. §4.4).
- **Accès athlète réactivé** : **email** à l'utilisateur réactivé individuellement.

**Pas de rappel temporel automatique avant événement en V1** (J-1, H-2, J-7 etc.), toutes `kind` confondues.

#### 4.15.3 Préférences — matrice granulaire
- Chaque utilisateur a une **matrice type × canal** dans son profil.
- **Défaut : tout activé.** Opt-out cellule par cellule.
- **Push iOS** : limitation Safari 16.4+ et PWA installée acceptée. Email = fallback pour les iOS non-PWA.
- **Pas de quiet hours côté app** (l'OS de l'utilisateur gère iOS Focus, Android DND).

#### 4.15.4 Pause globale des notifications
- Toggle **« Pause toutes les notifs »** dans le profil. Aucune notif (push + email) jusqu'à **réactivation manuelle explicite**.
- **Pas de durée auto** ni d'expiration. Si l'utilisateur oublie de la lever, c'est sa responsabilité (mention claire « tant que tu ne réactives pas, tu ne reçois rien »).
- Distinct de la matrice fine : matrice = filtres permanents par type/canal ; pause = interrupteur master temporaire.
- Pendant une pause, les événements applicatifs continuent ; seul le canal est suspendu.

#### 4.15.5 Notifs parent / enfant
Routage selon la phase (§4.2) :
- **P1** : uniquement au parent garant.
- **P2** : à l'enfant **ET** au parent garant (push + email sur les deux).
- **P3** : à l'enfant uniquement.

La matrice §4.15.3 est **propre à chaque utilisateur**. Le parent gère ses opt-outs sur son compte, l'enfant (P2) gère les siens. Le parent ne peut pas modifier les préférences de l'enfant.

#### 4.15.6 Gestion des envois sortants (bureau)
Écran **bureau / admin** donnant la main sur la **file des notifications sortantes** (`NotificationOutbox`, §5.1), pour superviser et rattraper les envois sans attendre le traitement automatique.

- **Consultation filtrée** : par statut (en attente / envoyée / en échec), canal (push / email), type (§4.15.2) et destinataire ; **détail** d'un envoi (canal, contenu, nombre de tentatives, horodatages).
- **Rattrapage** : **annulation** d'un (ou de plusieurs) envoi(s) **encore en attente** — utile quand une notification a été générée par erreur, avant qu'elle ne parte.
- **Envoi manuel immédiat** : pousser tout de suite un envoi (ou tous les envois en attente) sans attendre le lot différé suivant (même effet que l'option « envoi prioritaire » §4.7).
- **Rejeu des échecs** : relancer les envois en échec.
- **Accès** : **admin uniquement** (acte de gouvernance, cohérent avec l'accès aux journaux §4.18). La consultation ne ré-émet jamais ; seules les actions explicites (annuler / pousser / rejouer) agissent sur la file.

### 4.16 Dashboard statistiques bureau

#### 4.16.1 Indicateurs V1
- **Adhérents actifs** : total, par section, par sous-catégorie.
- **Taux de remplissage** : `participating` + `waitlist` **combinés et distingués** (waitlist comme indicateur de demande non satisfaite), par séance / série / discipline / catégorie sur période.
- **Top séances** : les plus / moins fréquentées.
- **Évolution mensuelle** : graphique d'inscriptions.
- **Compétitions** : nb de participants déclarés par course.
- **Liste d'attente** : nb en waitlist, taux de promotion.
- **Overrides coach** : nb d'inscriptions forcées par coach, par motif (agrégat — détail brut dans la page « Journaux » §4.18).
- **Activité coachs** (bloc dédié) : nb de séances **`training`** encadrées par coach × discipline × période, évolution mensuelle, compteur live de **séances futures sans coach**. Les `competition` et `club_event` ne sont pas comptabilisés.
- **Filtres globaux** : période, discipline, catégorie.

**Bandeau d'alerte douce — comptes éligibles à suppression définitive** : dès qu'au moins un `User` est éligible (`deletionRequestedAt ≥ J-7`), bandeau passif en tête de dashboard avec lien direct vers la page Adhérents filtrée. Disparaît dès que la liste est vide. Pas de notification active.

Pas de stats de présence / no-show V1 (absence de pointage).

Depuis le dashboard, lien **« Consulter les journaux »** mène à la page admin « Journaux » §4.18.

#### 4.16.2 Export
- **Format XLSX** (pas CSV).
- Une feuille par tableau, formatage minimal (en-têtes en gras, dates formatées FR).
- L'export du journal d'audit / activité est distinct du dashboard et applique les filtres en cours de la page « Journaux ».

### 4.17 Paramètres club configurables (admin)

Page admin « Paramètres du club » :

- **Identité** : nom du club, **logo** (upload PNG/JPG/JPEG/WebP/SVG, max **1 Mo**), couleurs primaires.
- **Localisation** : fuseau horaire (défaut `Europe/Paris`).
- **Catégories d'âge** : catalogue éditable (cf. §4.5).
- **Disciplines** : catalogue éditable (cf. §4.6.1).
- **Types d'épreuve** : catalogue éditable (cf. §4.6.2).
- **Tags de quota** : création, renommage, définition du `maxPerWeek`, **archivage soft delete**. Suppression dure si zéro référence. `AuditLog quota_tag_modified`.
- **Qualifications coach** (cf. §4.11.3).
- **Comptes** : durée du lien d'invitation/activation (défaut 30 jours).
- **Notifications** : activation de chaque **canal** (push, email) pour tout le club — cf. §4.15.1.
- **Moyens de connexion** : activation du **lien magique** et de la **connexion Google** (cf. §4.1.1).
  La connexion par mot de passe n'est pas désactivable : elle garantit qu'une voie d'accès subsiste.
  Couper un moyen est **refusé** tant que des comptes actifs n'auraient plus aucun accès (invariant
  §4.1.2) — l'écran indique combien de comptes sont dans ce cas avant toute tentative.
- **Bascule de saison** : bouton « Désactiver tous les athlètes pour la nouvelle saison » (cf. §4.4) et bouton **« Démarrer la nouvelle année sportive »** (recalcul des catégories principales + reset des surclassements, cf. §4.5).

Toutes ces valeurs sont stockées en singleton côté serveur (sauf catalogues multi-entrées).

#### 4.17.1 Page « Adhérents » (admin)
Distincte de la page Paramètres :
- **Liste paginée** : nom complet, email, catégorie principale, rôles, accès athlète (actif / suspendu), parent garant si applicable, date de création.
- **Compteurs en en-tête** : suspendus restants + comptes éligibles à suppression définitive (`deletionRequestedAt ≥ J-7`).
- **Recherche** : texte libre nom / prénom / email (autocomplete).
- **Filtres** :
  - Catégorie d'âge (multi, actives + archivées).
  - Accès athlète (actif / suspendu / tous).
  - Rôles (multi parmi `athlete`, `coach`, `admin`, `parent_garant` — pseudo-rôle dérivé du lien de tutelle).
  - Statut suppression (aucun / en cours de tampon / éligible / tous).
- **Actions sur la fiche d'un user** : éditer email + date de naissance + rôles + catégories + qualifications, réactiver accès athlète, supprimer le compte (avec bouton **« Confirmer la suppression définitive »** distinct qui apparaît / devient cliquable uniquement à partir de J+7 ; bouton **« Annuler la demande de suppression »** disponible pendant le tampon).
- **Bouton « Ajouter un adhérent »** : formulaire one-shot (cf. §4.1.3).

### 4.18 Traçabilité : `AuditLog` et `ActivityLog`

Deux journaux **séparés**, stockés dans deux entités distinctes (cf. §5). Séparation motivée par des profils de volume, valeur unitaire et schéma très différents — éviter de polluer la recherche d'actions humaines à motif par le flux d'événements d'inscription.

#### 4.18.1 `AuditLog` — Gouvernance
Trace les **actions humaines à enjeu** (administration, gouvernance, sécurité, RGPD). Faible volume, valeur unitaire élevée, motif libre optionnel.

| Catégorie | Actions |
|---|---|
| Quota / inscriptions | `override_quota`, `promote_quota_exceeded` (mécanisme C, N entrées par batch), `cancel_session` |
| Tutelle / mineurs | `guardianship_severed`, `child_account_activated` |
| Cycle de vie compte | `account_deletion_requested`, `account_deletion_cancelled`, `account_deleted` (`actorId = admin` qui confirme, jamais `system`), `account_activated`, `role_changed`, `auth_method_linked`, `auth_method_unlinked`, `password_reset`, `bulk_athlete_deactivation` (1 entrée globale par clic admin) |
| Configuration club | `quota_tag_modified`, `category_archived`, `discipline_modified`, `event_type_modified`, `season_rollover` (1 entrée globale par démarrage de nouvelle année sportive, cf. §4.5) |

Enum **extensible** au cadrage technique si une action sensible non identifiée émerge.

**Champs** : `id`, `actorId`, `actorRole` (snapshot du rôle effectif : `admin` / `coach` / `athlete` / `parent`), `action`, `targetType`, `targetId`, `sessionId?`, `motif?` (libre, optionnel), `timestamp`.

#### 4.18.2 `ActivityLog` — Opérationnel
Trace les **événements d'inscription** au fil de l'eau. Volume élevé, valeur unitaire faible mais ensemble précieux pour débug et cascades.

| Catégorie | Actions |
|---|---|
| Inscriptions | `registration_created` (avec `resultingStatus` : `participating` / `waitlist_capacity` / `waitlist_quota_exceeded`), `inscription_by_coach` |
| Désinscriptions | `registration_cancelled` |
| Promotions auto | `auto_promoted_capacity` (mécanisme A — `resultingStatus = participating`), `auto_promoted_self_quota` (mécanisme B — `resultingStatus = participating` ou `waitlist_capacity`) |
| Encadrement coachs | `coach_registered`, `coach_unregistered` |
| Apéro club | `apero_flagged`, `apero_unflagged` |

**Pas de double journalisation** : le mécanisme C `promote_quota_exceeded` reste **uniquement** dans `AuditLog`.

**Champs** : `id`, `actorId` (peut valoir `system` pour les promotions auto et cascades), `action`, `userId` (utilisateur concerné — peut différer de `actorId` si l'action est déclenchée par un tiers ou cascade), `sessionId`, `registrationId?`, `resultingStatus?`, `timestamp`. **Pas de champ `motif`** (sans objet).

#### 4.18.3 Anonymisation à la suppression de compte
Dans les deux journaux, à la suppression d'un compte :
- `actorId`, `targetId` (AuditLog), `userId` (ActivityLog) du compte supprimé sont remplacés par un **marqueur stable** (ex. `anon:user:<hash>`), permettant de continuer à corréler sans révéler l'identité.
- `action`, `actorRole`, `sessionId`, `registrationId`, `resultingStatus`, `motif`, `timestamp` conservés.
- Recommandation documentaire : ne pas saisir de noms propres dans le champ `motif` libre (pas de validation algorithmique en V1).

#### 4.18.4 Rétention
**Indéfinie en V1.** Pas de purge automatique. Volume `ActivityLog` à monitorer ; à reconsidérer V2 (purge glissante) s'il devient problématique.

#### 4.18.5 Accès en lecture
**Admin uniquement** : page dédiée **« Journaux »** dans le back-office admin.
- **Sélecteur en-tête** : `Audit` / `Activity` / `Tous`.
- **Filtres** : acteur (autocomplete), action (multi-select), type de cible, séance (autocomplete), période (défaut 30 j).
- **Vue chronologique DESC paginée**. Colonnes : `timestamp`, `acteur` (nom + rôle snapshot), `action`, `cible`, `séance liée`, `motif` tronqué + tooltip (AuditLog uniquement).
- **Export XLSX** : applique les filtres en cours. Une seule feuille avec colonne `source` (audit | activity) pour le mode « Tous ».

**Coachs** : pas d'accès à la page « Journaux ». **Badges in-context** conservés sur les fiches séance (overrides quota + déblocages `quota_exceeded` — motif visible si renseigné).

**Athlètes / parents** : pas d'accès direct in-app. **RGPD article 15** satisfait sur **demande email à l'admin**, qui exporte manuellement via les filtres. À reconsidérer V2.

### 4.19 Pages d'information (notes club)

> **Ajout de périmètre post-cadrage** (demande bureau, juillet 2026). Non prévu au cadrage initial (§3.1). Assumé comme extension produit ; documenté ici pour rester source de vérité.

Pages courtes d'information à destination des membres : bons d'achat partenaires, codes promo magasin, informations générales du club. Objectif : centraliser dans l'app ce qui circulait sur WhatsApp/oralement.

- **Contenu** : titre + texte enrichi (même éditeur WYSIWYG que le contenu de séance, cf. §4.12.1 ; **pas** d'images ni de pièces jointes). Sanitisation serveur identique.
- **Édition** : **admin uniquement** (back-office). Création, édition, **archivage soft** (réactivable) puis suppression dure.
- **Visibilité par niveau cumulatif** : chaque page cible un niveau minimum —
  - `all` : tous les adhérents ;
  - `coach` : coachs **et** bureau ;
  - `admin` : bureau uniquement.
  Une page n'est jamais servie ni atteignable pour un rôle hors périmètre. La visibilité dépend du **regardeur** (rôles de l'utilisateur connecté), jamais du sujet parent/enfant consulté (cf. §4.2).
- **Bannière d'accueil** : une page peut être **épinglée**. Les pages épinglées visibles par le membre s'affichent, empilées, en haut de l'**Accueil** (titre + lien vers la page complète). Plusieurs bannières simultanées possibles.
- **Consultation** : page « Infos » accessible à tout membre connecté (entrée de menu), listant les pages qu'il a le droit de voir.

---

### 4.20 Bibliothèque de parcours

> **Ajout de périmètre post-cadrage** (2026-08-01). Non prévu au cadrage initial (§3.1). Assumé comme extension produit ; documenté ici pour rester source de vérité.

Un parcours GPX cesse d'être un attribut jetable de séance (§4.13.2) pour devenir une **entité réutilisable** que les séances *référencent*. Motif : le club refait les mêmes boucles toute l'année ; un fichier par séance obligeait à re-déposer le même GPX indéfiniment, sans jamais pouvoir répondre à « quel était le parcours de la sortie longue de mars ? ».

- **Création** : coachs et admins, par deux chemins équivalents — formulaire dédié de la bibliothèque, ou upload direct depuis le formulaire de séance (qui verse alors le parcours à la bibliothèque). Les deux passent par la même validation.
- **Doublons** : un parcours re-déposé à l'identique est **détecté** (empreinte du fichier) et l'app propose de réutiliser l'existant plutôt que de créer un doublon. Le coach garde la main pour créer quand même.
- **Consultation ouverte à tous les membres connectés**, cohérente avec §4.13.2 : la liste, la fiche et le téléchargement sont accessibles à tout adhérent. Seules la création, la modification et l'archivage sont réservés aux coachs/admins.
- **Métadonnées** : nom, description libre, discipline, et les métriques dérivées du tracé (distance, D+/D−, altitudes). Les métriques restent **calculées côté client** (§4.13.2) — le serveur ne lit jamais le fichier.
- **Qualification automatique du tracé**, pour rendre la bibliothèque triable sans saisie manuelle :
  - **Secteur géographique** (rose des vents à 8 directions) — où se trouve le parcours par rapport au point de départ habituel du club ;
  - **Forme** — boucle *arrondie* ou parcours *étiré* (aller-retour, trajet) ;
  - **Relief** — *roulant* / *vallonné* / *exigeant*, dérivé du dénivelé rapporté à la distance.
- **Exploration** : liste filtrable (recherche texte, secteur, discipline, distance, forme, relief — plusieurs valeurs par filtre, unies entre elles) et **carte d'ensemble** superposant les tracés du jeu filtré, chaque tracé identifiable et menant à sa fiche.
- **Fiche parcours** : tracé sur fond OpenStreetMap, profil altimétrique, métriques, **séances qui l'utilisent**, téléchargement du GPX.
- **Cycle de vie** : **archivage soft** (le parcours sort de la liste, ses séances passées restent intactes et consultables) puis suppression dure. Un parcours **utilisé par au moins une séance ne peut pas être supprimé** — seulement archivé. L'archivage **ne détruit pas le fichier** : restaurer un parcours le rend pleinement fonctionnel.
- **Retirer un parcours d'une séance** ne fait que rompre la référence : le parcours et son fichier restent dans la bibliothèque. Le remplacement d'un parcours par un autre sur une séance est un **changement structurant** (§4.9) et suit le régime de notification des inscrits.

---

## 5. Modèle conceptuel (entités et relations)

Vue logique des entités. **Volontairement agnostique** à la techno de stockage (relationnel, NoSQL document, autre) — la modélisation physique (schéma SQL, collections, dénormalisations, index, contraintes d'intégrité) sera produite au cadrage technique.

### 5.1 Entités principales

- **`User`** : `firstName`, `lastName`, `email?` (nullable en P1), `dob`, `isActive`, `athleteAccessSuspended` (booléen — suspend la capacité d'inscription comme athlète, séparé de `isActive`), `deletionRequestedAt?` (timestamp — posé au déclenchement du flow §4.3, remis à `null` à l'annulation), `isMinor`, `roles[]` (`athlete` / `coach` / `admin` cumulables). Lien optionnel `0..1` vers le parent garant. **Pas de stockage du n° de licence FFTri** (RGPD).
- **`Category`** : `label`, `ageMin`, `ageMax` (inclusives), `sortOrder`, `archivedAt?`. Pas de chevauchement entre catégories actives.
- **`UserCategory`** (M:N) : un `User` ↔ une ou plusieurs `Category`. Une catégorie est dite « principale » (dérivée auto, surclassable).
- **`Session`** :
  - Communs : `kind` (`training` | `competition` | `club_event`), `title`, `disciplineId?`, `startAt`, `durationMin`, `locationId?` + `locationText?`, `capacity?`, `categoryIds[]`, `createdBy` (immuable), `coaches[]` (M:N), `sourceTemplateId?` (informatif uniquement), `cancelledAt?` / `cancelledBy?`, `visibility`.
  - `training` : `quotaTagId?`, `contentMarkdown`, `contentAttachment`.
  - `competition` : `eventTypeId` (FK Types d'épreuve), `distance`, `externalUrl`, `photosAlbumUrl?`.
  - `club_event` : `agenda` (markdown), `externalUrl?`, `photosAlbumUrl?`.
  - Parcours : `routeOpenrunnerEmbedUrl?`, `routeOpenrunnerPublicUrl?`, `routeOpenrunnerId?` (dérivé du `code` opaque, non exposé), `route?` (référence `0..1` vers un **`GpxRoute`** — cf. §4.20 ; remplace les anciens `routeGpxFile?` / `routeStats?` portés par la séance).
- **`Registration`** (rattachée à une `Session`) : `userId`, `status` (`participating` | `waitlist` | `cancelled`), `waitlistReason?` (`capacity` | `quota_exceeded`), `waitlistPosition?`, `registeredAt`, `promotedAt?`, `promotedBy?`, `overrideBy?`, `overrideReason?`.
- **`SessionTemplate`** : `id`, `label`, `kind`, `disciplineId`, `dayOfWeek` (1..7), `startTimeOfDay`, `durationMin`, `locationId?` / `locationText?`, `capacity?`, `quotaTagId?`, `categoryIds[]`, `defaultCoachIds[]`, `generationStartDate`, `generationEndDate` (**obligatoires**), `createdBy` (= admin), `status` (`active` / `archived`). Aucun lien retour comportemental vers les `Session` générées.
- **`QuotaTag`** : `code`, `label`, `maxPerWeek`, `archivedAt?`. Universel — aucune restriction de discipline.
- **`Discipline`** : `id`, `label`, `archivedAt?`. Référencée par `Session.disciplineId` et `SessionTemplate.disciplineId`. Garde-fou : la dernière discipline active ne peut être ni archivée ni supprimée.
- **`EventType`** : `id`, `label`, `archivedAt?`. Référencé par `Session.eventTypeId` (uniquement pour `kind = competition`). Garde-fou : le dernier type actif ne peut être ni archivé ni supprimé (cf. §4.6.2 — note ouverte sur ce point).
- **`Qualification`** : `id`, `label`, `code?`, `archivedAt?`.
- **`UserQualification`** (M:N) : `userId`, `qualificationId`, `expiresAt?`, `attributedAt`, `attributedBy`. Unicité `(userId, qualificationId)` — les renouvellements mettent à jour `expiresAt` plutôt que créer une nouvelle ligne.
- **`Location`** : `name`, `address`, `latitude`, `longitude`, `kind`, `notes`, `createdBy`, `isArchived`.
- **`GpxRoute`** (§4.20) : `name`, `description?`, `disciplineId?`, `gpxFile` (référence stockage objets), `fingerprint` (empreinte du fichier, pour la détection de doublon), métriques dérivées du tracé (`distanceKm?`, `dPlusM?`, `dMinusM?`, `altMin?`, `altMax?`), qualification automatique (`sector?` — rose des vents 8 directions, `shape?` — arrondi/étiré, `grade?` — roulant/vallonné/exigeant), données géographiques dérivées (emprise du tracé et **tracé simplifié** servant l'affichage cartographique), `createdBy`, `archivedAt?`. Référencé par `Session.route` (`0..n` séances par parcours). **Toutes les métriques et données dérivées sont produites côté client** (§4.13.2) : le serveur ne lit jamais le fichier, il borne et refuse les valeurs aberrantes.
- **`WeatherCacheEntry`** : indexée par `(location, créneau horaire)`, `forecast`, `fetchedAt`.
- **`AuditLog`** : `id`, `actorId` (anonymisable), `actorRole` (snapshot), `action` (enum extensible), `targetType`, `targetId` (anonymisable), `sessionId?`, `motif?`, `timestamp`.
- **`ActivityLog`** : `id`, `actorId` (anonymisable, peut valoir `system`), `action`, `userId` (anonymisable — concerné par l'action, peut différer de `actorId`), `sessionId`, `registrationId?`, `resultingStatus?`, `timestamp`.
- **`AperoFlag`** : `id`, `sessionId`, `userId` (payeur), `registrationId` (index pour la cascade), `motif?` (max 140 caractères), `flaggedAt`, `flaggedBy`. **Unicité `(sessionId, userId)`**. Cardinalité par séance : `0..N`. Hard delete au retrait (traçabilité dans `ActivityLog`).
- **`Debrief`** : `id`, `sessionId` (FK, `kind = competition` en V1), `authorId` (FK User, immuable, anonymisable), `contentMarkdown` (texte enrichi WYSIWYG §4.12.1), `createdAt`, `updatedAt`, `archivedAt?` / `archivedBy?` (soft-delete admin). **Unicité `(sessionId, authorId)`** — au plus 1 débrief par membre et par compétition. Cardinalité par séance : `0..N`. Nom d'entité volontairement **générique** (pas spécifique à la compétition) pour rester réutilisable, même si le périmètre V1 se limite à `kind = competition` (cf. §4.12.5).
- **`InformationPage`** (note club, §4.19) : `id`, `title`, `contentMarkdown?` (texte enrichi WYSIWYG §4.12.1), `visibility` (`all` / `coach` / `admin` — niveau minimum cumulatif), `pinned` (épinglée en bannière d'accueil), `createdBy` (FK User, anonymisable), `createdAt`, `updatedAt`, `archivedAt?` / `archivedBy?` (soft-delete admin). Édition admin uniquement. Ajout de périmètre post-cadrage.
- **`ClubSettings`** (singleton) : `name`, `logo`, `primaryColor`, `timezone`, `invitationLinkDays`, etc.
- **`NotificationPreferences`** (rattachée à un `User`) : matrice `type × canal` + flag pause globale.

### 5.2 Notes importantes
- Le statut `participating` est **uniforme sur les 3 `kind`**. La distinction engagement ferme vs intention déclarée est portée par `Session.kind` + l'absence de `quotaTagId` + capacité optionnelle pour competition/club_event — **pas par la valeur de `status`**. Pas d'entité `Competition` ni `ClubEvent` séparée.
- Pas d'entité `Attendance` en V1 (pas de pointage formel).
- Pas de notion de tenant ni d'isolation multi-club.
- Pas de stockage de téléphone, ni de certificat médical (minimisation RGPD).
- `AuditLog` et `ActivityLog` sont deux entités séparées — pas de relation directe (jointure éventuelle via `sessionId` ou via le marqueur anonymisé).
- **Cycle de vie compte mineur** : en P1, le `User` enfant existe en base **sans credential** et avec `email` nul. La transition P1→P2 crée le credential et renseigne l'email. La transition P2→P3 rompt le lien de tutelle, mais le `User` athlète et son historique restent intacts.
- **Règle d'accès clé** : un parent doit pouvoir lire et écrire sur les `Registration` de tout `User` enfant dont il est garant (sans quoi il ne peut pas agir en P1 ni P2). À formaliser dans le moteur de règles d'accès choisi au cadrage technique.

---

## 6. Contraintes non-fonctionnelles

| Domaine | Contrainte |
|---|---|
| **Plateforme** | PWA installable (manifest + service worker). Fonctionne offline en lecture du planning de la semaine en cours. |
| **Hébergement** | **UE obligatoire** pour le stockage de toutes les données utilisateurs et leurs sauvegardes (RGPD). Région privilégiée : Paris (sinon Francfort ou équivalent UE). |
| **Distribution** | **AGPL-3.0 self-hosting un-instance-par-club.** Pas de multi-tenant. |
| **Sécurité** | Contrôle d'accès appliqué côté backend (pas seulement client). Toute donnée a ses règles d'accès définies dès sa première migration en production. |
| **RGPD** | Données UE. Suppression de compte = anonymisation des inscriptions et des débriefs (auteur anonymisé, texte conservé — cf. §4.12.5). Pas de stockage santé (certif médical exclu) ni de téléphone (minimisation). Mentions légales / CGU accessibles depuis le footer (rédaction différée pré-prod). |
| **Conservation** | Pas de purge auto, conservation indéfinie. Monitoring du volume de stockage. |
| **Performance** | Chargement initial < 2s sur 4G. Planning quasi-instantané après mise en cache. **Évaluation du quota en temps quasi-constant** à l'inscription (cf. §4.10). |
| **Sérialisation des inscriptions** | Sur la dernière place, deux inscriptions concurrentes sérialisées atomiquement sur timestamp serveur. Premier = `participating`, second = `waitlist capacity`. **Pas de double-acceptation, jamais.** Implémentation libre. À garantir par tests E2E. |
| **Fraîcheur des données** | Listes d'inscrits, positions waitlist et compteurs dashboard **à jour à chaque chargement de page** (ou pull-to-refresh). Le **temps réel push** est **souhaitable mais non requis V1** — activé si la stack le permet sans surcoût, sinon refresh manuel suffit. Cette exigence ne doit pas contraindre le choix de stack. |
| **Accessibilité** | Niveau AA recommandé (lisibilité mobile, contraste). |
| **Langue** | **Français uniquement V1.** |
| **Tests** | Couverture > 80 % (unitaires + E2E sur parcours critiques : inscription, désinscription, promotion, override). |
| **CI/CD** | CI obligatoire sur chaque PR (lint, type-check, tests unit + E2E, seuil de couverture > 80 %). Environnement de **preview** déployable par PR. **Déploiement prod manuel** par le mainteneur. Garde-fou sur la branche principale. |
| **Monitoring** | Outillage de monitoring d'erreurs + logs serveur centralisés. |
| **Backups** | RPO 7j minimum (point-in-time recovery ou équivalent) + export complet périodique à froid (rétention 1 an). |
| **Migrations** | Toutes les migrations de schéma, règles d'accès et données de seed **versionnées dans le repo** et reproductibles sur un environnement neuf. |

---

> **Choix techniques non figés** : frontend, backend, persistance, auth (provider exact), push, email transactionnel, hébergement, monitoring, CI/CD, frameworks de test, bibliothèques de cartes / parsing GPX, génération XLSX, etc. **Tous à arbitrer au cadrage technique dédié**, après validation des maquettes (M0). Les services tiers nommés dans ce PRD (**OpenRunner Pro**, **Open-Meteo**) sont des **choix produit / légaux**, pas des choix de stack applicative.
