# Plan de tests — validation technique (organisateur)

> ⚠️ **Ce plan est réservé à l'organisateur du test** (admin technique). Il suppose un accès aux
> **logs** (`storage/logs/laravel.log`), aux **commandes artisan**, à la **base**, et couvre des
> opérations sensibles (RGPD, bascule de saison, envois). **Ne pas le distribuer aux testeurs
> novices** : pour eux, utiliser [PLAN_TESTS_MEMBRES.md](PLAN_TESTS_MEMBRES.md) — un parcours
> 100 % interface, sans log ni commande.
>
> **But** : valider fonctionnellement l'application avant ouverture aux adhérents.
> Chaque chapitre est un **parcours guidé pour un rôle**, avec le compte à utiliser, des étapes
> numérotées et le **résultat attendu** à cocher. Référence des comptes : [COMPTES_DEMO.md](COMPTES_DEMO.md).
>
> **Mot de passe de tous les comptes : `password`.**
>
> **Avant de commencer** (ou pour repartir de zéro) :
> ```bash
> php artisan migrate:fresh
> php artisan db:seed --class=CatalogSeeder
> php artisan db:seed --class=DemoSeeder
> ```
> Toutes les dates du jeu de démo sont relatives au jour du seed.
>
> **Une partie de ce plan est automatisée.** Le harnais E2E (Playwright) rejoue en navigateur les
> parcours critiques et les cas limites — voir [tests/E2E/README.md](../tests/E2E/README.md) :
> ```bash
> node tests/E2E/run.mjs                       # suites non destructives
> node tests/E2E/destructif.mjs --oui-je-sais  # RGPD + rupture de tutelle, puis reconstruction
> ```
> Les points marqués **`[auto:Sn]`** ci-dessous sont couverts par un scénario ; le testeur humain
> peut s'y limiter à un contrôle visuel. Tout le reste demande un passage manuel — notamment
> PWA/offline/push (§9), import CSV (§8.3), export XLSX (§8.6) et la bascule de saison (§8.8).
>
> **Consignes générales**
> - Tester chaque parcours **sur mobile ET sur desktop** (la mise en page diffère, les fonctions sont identiques).
> - Cocher `[x]` quand le résultat attendu est constaté ; sinon noter l'écart (capture d'écran bienvenue).
> - ⚠️ Le parcours **Admin §8.8 (bascule de saison)** modifie tous les comptes : le faire **en dernier**, puis recharger le seed.
> - En environnement de démo, les emails partent dans les logs (`storage/logs/laravel.log`) et le push
>   nécessite les clés VAPID (voir §9). Les notifications restent visibles dans **Alertes** et **Admin → Envois**.

---

## 1. Parcours Athlète — `marie@demo.club`

### 1.1 Authentification (3 méthodes — PRD §4.1)
- [ ] Connexion **email + mot de passe** → arrivée sur l'Accueil.
- [ ] Déconnexion (Profil → onglet Connexion), puis **lien magique** : depuis l'écran de connexion, demander un lien pour `marie@demo.club` → récupérer l'URL dans `storage/logs/laravel.log` → le lien connecte. Le **réutiliser** → refusé (usage unique).
- [ ] Demander un lien magique pour un email **inconnu** → même message neutre (pas de fuite d'existence de compte).
- [ ] (Si OAuth configuré) **Google** : le linking n'est accepté que si l'email Google est vérifié et correspond à un compte club vérifié.

### 1.2 Accueil
- [ ] Salutation personnalisée + **prochaine séance** en vedette (badge « Tu participes » si inscrite).
- [ ] Liste « Mes prochaines séances » : ne liste que les séances où **Marie est inscrite** (participating), pas tout le planning ; la prochaine (en vedette) n'y est pas dupliquée.
- [ ] Bloc **quotas de la semaine** (ex. NAT 0/1 ou 1/1 selon les inscriptions seedées).
- [ ] Bloc « Apéro à venir » si un flag apéro existe sur une séance future.
- [ ] **Bannière info épinglée** : la note « Sport Attitude » (épinglée) apparaît en tête de l'accueil ; clic → page **Infos** ancrée sur la note (voir §1.10).

### 1.3 Planning (PRD §4.7, §4.5)
- [ ] Vues **Semaine / Jour / Mois** ; navigation précédent/suivant/aujourd'hui ; « cette semaine » signalée.
- [ ] Filtres : Tout / Natation / Vélo / Course / Compét. + case « Mes inscriptions ».
- [ ] Vue Mois : pastilles par discipline, clic sur un jour → vue Jour, clic sur « `S<n>` » → vue Semaine.
- [ ] La séance **annulée** (Course à pied jeudi) apparaît barrée/grisée avec chip « Annulée ».
- [ ] `[auto:S9]` **Filtrage par catégorie (§4.5)** : Marie (Adulte) voit les séances **adultes** (Natation du mercredi et du samedi 10:45, Cuisses du dimanche) et les séances **ouvertes à tous** (Vélo piste du mardi, PPG du vendredi), mais **PAS** les séances réservées aux jeunes (« Natation samedi matin — jeunes », « Enchaînement — jeunes » du dimanche). Contre-épreuve avec un mineur autonome (`enzo@demo.club`, Cadets) : il voit les séances jeunes + tout-public, **pas** les séances adultes.

### 1.4 Inscription / désinscription (PRD §4.9)
- [ ] S'inscrire sur une séance future avec des places → chip « Tu participes » immédiat (bouton `+` planning mobile ou fiche).
- [ ] Se désinscrire → confirmation demandée, chip disparaît.
- [ ] `[auto:S16]` **Séance pleine** : sur « Natation samedi matin — jeunes » (capacité 6, saturée) → bouton « Rejoindre la liste d'attente » → statut « En liste d'attente · `<rang>` ».
- [ ] `[auto:S8]` **Quota** : Marie a 1 natation cette semaine (quota NAT = 1/sem). S'inscrire à une **2ᵉ natation de la même semaine** → dialog « Quota atteint » ; confirmer → waitlist « quota ». Annuler → aucune inscription.
- [ ] **Conflit horaire** : s'inscrire à une séance qui chevauche une inscription existante → alerte non bloquante (confirmation), l'inscription reste possible.
- [ ] `[auto:S11]` **Séance commencée / passée** : aucun bouton d'inscription ni de désinscription.
- [ ] `[auto:S2,S9]` **Garde catégorielle (§4.5)** : une séance réservée aux jeunes (« Enchaînement — jeunes ») **ne propose aucun bouton d'inscription** à Marie (Adulte), et une tentative forcée est refusée (« cette séance ne cible pas ta catégorie »). Symétriquement, un mineur autonome (`enzo@demo.club`) ne peut pas s'inscrire à une Natation adultes. Une séance **sans ciblage** (ouverte à tous) reste inscriptible par tous.
- [ ] **Libération de place** : se désinscrire d'une séance pleine où quelqu'un attend → le 1ᵉʳ de la file passe automatiquement « participant » (vérifiable en se connectant avec lui, ou dans Admin → Envois : notification de promotion).

### 1.5 Fiche séance (PRD §4.7, §4.12, §4.13)
- [ ] Onglets : Infos / Encadrement / Inscrits / Waitlist / Parcours / Apéro (+ Débriefs sur compétition).
- [ ] **Inscrits** : les autres athlètes s'affichent en « Prénom I. » (pas de nom complet pour un athlète).
- [ ] **Encadrement** : coachs + qualifications agrégées ; sur une séance encadrée par Vincent, badge **PSC1 expirée**.
- [ ] **Contenu** : une séance avec contenu enrichi (gras, listes) s'affiche proprement ; pièce jointe téléchargeable si présente.
- [ ] **Parcours** : sur un « Vélo — Cuisses » du dimanche → onglet Parcours présent (l'embed OpenRunner utilise un code factice en démo, la carte ne s'affiche donc pas ; la **bibliothèque GPX**, elle, affiche de vraies traces). Sur « Sortie trail nature » → lieu en texte libre, pas de carte ni météo.
- [ ] **Météo** : sur une séance < 16 jours avec lieu géocodé → prévision affichée ; au-delà → « trop loin ».
- [ ] **Ciblage (§4.5)** : panneau « Ciblage » listant les catégories visées en chips (triées) ; une séance sans ciblage n'affiche pas ce panneau (ou indique « ouverte à toutes les catégories »).
- [ ] `[auto:S10]` La séance **annulée** affiche le bandeau « Séance annulée », aucune action d'inscription.

### 1.6 Apéro (PRD §4.14)
- [ ] Sur une séance où Marie **participe** (future) : bouton « J'offre l'apéro » + motif (max 140).
- [ ] Le flag apparaît (chope) sur la carte planning + fiche ; retirer son flag → disparaît.
- [ ] Se désinscrire de la séance → le flag saute aussi (cascade).

### 1.7 Compétition + débrief (PRD §4.12.5)
- [ ] La compétition **future** (« Triathlon M du lac ») : fiche avec type d'épreuve, distance, lien externe, album photos ; déclarer sa participation possible.
- [ ] La compétition **passée** (« Triathlon M de printemps ») : onglet Débriefs → 2 débriefs publiés (Marie, Lucas).
- [ ] Marie (participante) peut **modifier son débrief** ; unicité : pas de second débrief possible.
- [ ] Un athlète **non participant** (ex. `paul@demo.club`) ne voit pas le bouton « rédiger ».

### 1.8 Alertes & préférences (PRD §4.15)
- [ ] Écran **Alertes** non vide (annulation de séance, promotions…).
- [ ] Profil → Notifications : matrice type×canal modifiable ; **pause globale** activable.
- [ ] (Si VAPID configuré) activer le push sur l'appareil → notification test reçue.

### 1.9 Profil (PRD §4.1.5, §4.3, §4.10)
- [ ] Identité + catégorie ; compteurs **quotas de la semaine** cohérents avec l'Accueil.
- [ ] Méthodes de connexion listées ; révocation impossible s'il n'en reste qu'une.
- [ ] **Demande de suppression de compte** → message « demande envoyée » ; un bandeau propose l'**annulation** pendant 7 jours ; annuler.

### 1.10 Pages d'information (PRD §4.19)
- [ ] `[auto:S7]` Menu **Infos** : Marie (athlète) voit **exactement 2 notes** — les 2 codes promo (« Sport Attitude » et « Aquagliss ») destinés à **tous**. Elle ne voit **ni** le code portail piscine (coachs) **ni** la fiche identifiants extranet (admin).
- [ ] Contenu enrichi (gras, listes, liens) rendu proprement ; la note épinglée « Sport Attitude » remonte aussi en **bannière d'accueil** (§1.2) et l'ancre depuis la bannière ouvre la bonne note.
- [ ] Aucun bouton d'édition/création côté athlète (lecture seule).

---

## 2. Parcours Athlète suspendu — `kevin@demo.club`

- [ ] Connexion possible (le compte est actif).
- [ ] `[auto:S3]` Planning/fiches consultables, mais **aucune inscription possible** : message « accès aux inscriptions suspendu — contacte le bureau ».
- [ ] `[auto:S15]` Kévin n'apparaît **pas** dans le sélecteur « Inscrire un athlète » d'un coach (voir §3.4).

---

## 3. Parcours Coach — `vincent@demo.club`

### 3.1 Création / édition de séance (PRD §4.7, §4.5)
- [ ] « Créer une séance » : entraînement complet (discipline, date future, durée, lieu, capacité, **catégories ciblées**, tag quota, contenu enrichi, pièce jointe ≤ 5 Mo) → visible au planning.
- [ ] **Ciblage catégoriel (§4.5)** : créer une séance ciblant seulement une catégorie jeune → elle apparaît chez un mineur de cette catégorie mais **pas** chez un athlète adulte ; laisser le ciblage vide → séance visible et inscriptible par **tous**.
- [ ] Créer une **compétition** (type d'épreuve, distance, lien) et un **club_event** (agenda) → champs spécifiques OK.
- [ ] Éditer une séance **avec inscrits** en changeant un champ structurant (date/heure/lieu) → dialog de choix de notification ; vérifier la ligne dans Admin → Envois.
- [ ] Le créateur (`createdBy`) n'est pas modifiable.

### 3.2 Annulation / restauration (PRD §4.7, §4.10.6)
- [ ] Annuler une séance future **avec inscrits** → bandeau « annulée », notification à chaque inscrit (Alertes/Envois), flag apéro éventuel « parké ».
- [ ] **Restaurer** la séance → inscriptions rétablies telles quelles, flag apéro de retour, notification de réactivation.
- [ ] La séance annulée **ne compte plus** dans le quota des inscrits (un inscrit peut se réinscrire ailleurs sur le même tag).

### 3.3 Parcours / GPX (PRD §4.13)
- [ ] Coller une URL d'embed OpenRunner valide → carte sur la fiche ; URL hors whitelist → refusée.
- [ ] Téléverser un **GPX ≤ 5 Mo** → tracé sur fond OSM + bouton téléchargement.

### 3.4 Gestion des inscrits (PRD §4.9.7)
- [ ] `[auto:S15]` Fiche → Inscrits → **« Inscrire un athlète »** : le picker liste les athlètes actifs **sans Kévin (suspendu)**, sans les déjà-inscrits, sans les encadrants ; recherche par nom OK.
- [ ] Inscrire un athlète **sous quota** → participant + **notification à l'athlète** (Envois : `enrolled_by_coach`).
- [ ] Inscrire un athlète **au-dessus du quota** → dialog : (a) **file quota** ou (b) **override** avec motif → badge « override » sur l'inscrit, trace en Journal d'audit.
- [ ] **Retirer** un inscrit d'une séance pleine → le 1ᵉʳ de la file « capacité » est promu (FIFO).
- [ ] **Débloquer le quota** (mécanisme C) : sur une séance avec file « quota » et des places → bouton de déblocage → promus, AuditLog par athlète. Refusé tant que la file « capacité » n'est pas vide.
- [ ] **Augmenter la capacité** d'une séance pleine avec file → promotions FIFO automatiques.

### 3.5 Encadrement (PRD §4.11)
- [ ] S'inscrire comme **encadrant** sur une séance ; ses qualifications (BF2, PSC1 expirée) s'agrègent sur la fiche.
- [ ] Inscrire un **autre coach** comme encadrant (voie 3).
- [ ] Retirer le **dernier coach** d'une séance → confirmation explicite « séance sans encadrement ».
- [ ] `[auto:S1]` Un encadrant inscrit **athlète** sur la même séance : impossible sans **bascule** ; « Je participe » ouvre le dialog de bascule (place + quota revalidés).

### 3.6 Pages d'information (PRD §4.19)
- [ ] `[auto:S7]` Menu **Infos** : Vincent (coach) voit **3 notes** — les 2 codes promo (tous) **plus** le code de portail piscine réservé aux **coachs**. Il ne voit **pas** la fiche identifiants extranet (admin seul). Toujours en lecture seule (pas d'édition).

---

## 4. Parcours Coach-athlète — `mathieu@demo.club`

- [ ] Les deux tiers de navigation apparaissent (athlète + coach).
- [ ] Mathieu s'inscrit comme **athlète** à une séance, et **encadre** une autre : les deux chips « Tu participes » / « Tu encadres » cohabitent sur des séances différentes.
- [ ] `[auto:S1]` Sur une même séance : jamais les deux rôles à la fois (bascule obligatoire, §3.5).

---

## 5. Parcours Parent P1 — `florence@demo.club` (garante de Lucie, sans compte)

- [ ] À la connexion : entrée **« Mes enfants »** dans la navigation + sélecteur **« Tu consultes : Moi / Lucie »** sur l'Accueil et le Planning.
- [ ] **Mes enfants** : carte Lucie (âge, catégorie, **phase P1**, « tu agis en son nom ») + jusqu'à **3 prochaines séances inscrites** (ou repli sur une séance ouverte si aucune inscription) ; chaque carte bascule le sujet sur Lucie au clic.
- [ ] Sélectionner **Lucie** dans le sélecteur → bandeau « Tu agis pour Lucie » ; l'Accueil et le Planning montrent **ses** inscriptions et quotas (chip « Lucie participe »).
- [ ] **Filtrage catégoriel pour l'enfant (§4.5)** : Lucie étant une jeune, le planning consulté sous son sujet montre les séances **jeunes** + tout-public, **pas** les séances adultes ; on ne peut l'inscrire qu'à des séances ciblant sa catégorie (ou ouvertes).
- [ ] **Inscrire Lucie** sur une séance future (depuis le planning ou la fiche : bouton « Inscrire Lucie ») → l'inscription est au nom de **Lucie**, pas de Florence.
- [ ] **Désinscrire Lucie** → confirmation nominative.
- [ ] Quota : inscrire Lucie sur 2 natations de la même semaine → dialog quota au nom de Lucie.
- [ ] Notifications : les événements de Lucie (annulation, promotion) arrivent à **Florence** (Alertes / Envois — P1 = garant seul).
- [ ] **Accès autonome** (P1→P2) : « Mes enfants » → « Accès autonome » → saisir un email → invitation envoyée (lien 30 j dans les logs email) ; ouvrir le lien → Lucie choisit son mot de passe → elle passe **P2**, tutelle conservée.
- [ ] Revenir sur « Moi » dans le sélecteur → Florence retrouve ses propres inscriptions.

---

## 6. Parcours Parent multi-enfants — `sandrine@demo.club` (Jade P1 + Noah P2, athlète elle-même)

- [ ] Sélecteur à **3 pilules** : Moi / Jade / Noah.
- [ ] Sandrine s'inscrit **pour elle-même** (sujet « Moi ») ; puis bascule sur **Jade** et l'inscrit ; puis sur **Noah** — trois inscriptions distinctes, chacune au bon nom.
- [ ] « Mes enfants » : deux cartes (Jade P1 avec « Accès autonome », Noah P2 avec « Rompre la tutelle »). Noah affiche **Cadets** + surclassement **Juniors** sur sa fiche adhérent (visible côté admin).
- [ ] `[auto:D2]` **Rompre la tutelle** de Noah → dialog de confirmation → Noah disparaît de « Mes enfants » et du sélecteur ; notification aux **deux** ; Noah (P3) reste connecté et autonome. *(Recharger le seed ensuite si besoin de rejouer.)*

---

## 7. Parcours Mineur P2 + parent pur — `theo.mercier@demo.club` / `olivier@demo.club`

- [ ] Théo se connecte avec **son propre compte** et s'inscrit **lui-même** à une séance.
- [ ] Les notifications de Théo arrivent **en double** : à Théo ET à Olivier (vérifier dans Admin → Envois : deux destinataires pour le même événement).
- [ ] `[auto:S12]` **Olivier = parent pur** (aucun rôle) : il se connecte, voit Théo dans **« Mes enfants »**, peut l'inscrire/désinscrire via le sélecteur — mais **ne peut pas s'inscrire lui-même** (tentative → message « pas le rôle athlète »).
- [ ] Olivier ne peut **pas** modifier les préférences de notification de Théo.

---

## 8. Parcours Admin — `admin@demo.club`

### 8.1 Paramètres & catalogues (PRD §4.6, §4.17)
- [ ] Paramètres club : nom, fuseau, durée des liens d'invitation modifiables.
- [ ] Catalogues : disciplines, types d'épreuve, tags quota, qualifications, lieux — créer / renommer (rétroactif) / archiver ; **garde-fou** : impossible d'archiver la dernière discipline ou le dernier type actif ; suppression définitive uniquement si zéro référence.
- [ ] Catégories d'âge : bornes sans chevauchement (une modification en collision est refusée).

### 8.2 Adhérents (PRD §4.17.1)
- [ ] Recherche + filtres (rôle, accès) ; compteurs ; **Brigitte** apparaît « désactivée ».
- [ ] Créer un adhérent adulte (email requis) et un **mineur P1** (email facultatif, garant obligatoire).
- [ ] Fiche adhérent : **modifier la date de naissance** → catégorie recalculée, surclassement conservé (tester sur Noah) ; date future ou < 1900 refusée.
- [ ] Ajouter/retirer un rôle (coach) → tracé au journal.
- [ ] **Qualifications** : en ajouter une **avec date d'expiration** (champ optionnel à l'ajout) ; **crayon** sur une ligne existante → poser/modifier/effacer l'expiration ; passer une date au passé → badge « expirée » sur la fiche adhérent ET dans l'onglet Encadrement des séances qu'il encadre.
- [ ] **Suspendre** un athlète, vérifier qu'il ne peut plus s'inscrire, puis le **réactiver**.
- [ ] **Pupilles** : ouvrir la fiche de `sandrine@demo.club` → carte « Pupilles » listant Jade (P1) et Noah (P2), liens cliquables vers leurs fiches.
- [ ] **Tutelle côté admin** : fiche de Théo Mercier → carte Tutelle (garant affiché, rupture P2→P3 possible) ; fiche de Lucie → invitation P1→P2. Fiche d'un **mineur sans garant** (ex. `hugo@demo.club`) → avertissement « sans parent garant » + **« Lier ce garant »** (choisir un adulte actif) → le lien apparaît, tracé au journal d'audit.

### 8.3 Import CSV (PRD §4.17.1)
- [ ] Importer un CSV valide (colonnes nom/prénom/date_nais/email/parent) → aperçu, puis création en masse.
- [ ] CSV avec une ligne en erreur (date invalide, email dupliqué) → **tout-ou-rien** : rien n'est importé, erreurs listées par ligne.
- [ ] Ligne enfant référençant un parent **du même fichier** → le lien de tutelle est créé.

### 8.4 Suppressions RGPD (PRD §4.3) — y compris cas garant
- [ ] Bandeau Accueil : **1 compte éligible** (Daniel). Page Adhérents → filtre suppressions : Gilles (tampon en cours, annulable) + Daniel (éligible).
- [ ] Tenter de confirmer la suppression de **Gilles** → refusée (J+7 non écoulé) ; **annuler** sa demande.
- [ ] Confirmer la suppression de **Daniel** (saisie du nom + double validation) → compte **anonymisé** (ligne conservée, PII effacées), `AuditLog account_deleted`, ses débriefs éventuels restent (texte conservé, auteur anonymisé).
- [ ] `[auto:D1]` **Garant avec pupille P1** : tenter la suppression de `florence@demo.club` (garante de Lucie P1) → **refusée** avec message explicite (autonomiser ou reparenter l'enfant d'abord).
- [ ] **Garant avec pupille P2 seulement** : demander la suppression de `olivier@demo.club` → acceptée ; à la confirmation (après J+7, ou en base pour tester), la **tutelle de Théo est rompue automatiquement** (AuditLog `guardianship_severed`, notification aux deux) — Théo passe P3, sa fiche ne pointe plus un « Compte supprimé ». *(Recharger le seed ensuite.)*
- [ ] **Parent désactivé** : un compte en tampon de suppression (`is_active=false`) ne reçoit **plus** les notifications de ses enfants (routage filtré) ; si un P1 n'a plus aucun destinataire joignable, l'événement est tracé dans les logs applicatifs.

### 8.5 Modèles & génération (PRD §4.8)
- [ ] Liste : 10 templates actifs + « Natation lundi soir (ancien créneau) » **archivé** (filtre statut).
- [ ] Créer un template (jour, heure, durée, coachs par défaut, bornes de génération **obligatoires**) → **générer** → séances créées, coachs pré-affectés, récap notifié aux coachs.
- [ ] **Relancer** la génération → les séances existantes ne sont pas dupliquées ni écrasées.
- [ ] Archiver un template → il ne génère plus ; les séances déjà créées restent.

### 8.6 Dashboard, journaux, envois (PRD §4.16, §4.18, §4.15.6)
- [ ] Dashboard : stats de remplissage, activité coachs, compteur « séances futures sans coach » ; **export XLSX** téléchargeable et ouvrable.
- [ ] `[auto:S13,S14]` Journaux : **Audit** (overrides, suppressions, tutelle…) et **Activité** (inscriptions…) séparés, filtrables, exportables ; accessibles à l'admin seul (vérifier 403 avec un coach).
- [ ] **Envois** : lignes `sent` et `pending` (seed), filtres par statut/canal ; **annuler** une ligne pending ; **rejouer** une ligne échouée ; déclencher un envoi manuel.

### 8.7 Pages d'information (PRD §4.19)
- [ ] `[auto:S7]` **Admin → Pages d'info** : les **4 notes** seedées sont listées (2 « tous », 1 « coachs », 1 « admin »), avec leur niveau de visibilité et l'état épinglé.
- [ ] **Créer** une note (titre, contenu WYSIWYG, niveau de visibilité `tous`/`coachs`/`admin`, épinglage) → elle apparaît sur la page **Infos** aux seuls rôles concernés (recouper avec §1.10 athlète, §3.6 coach).
- [ ] **Épingler** une note → elle remonte en **bannière d'accueil** pour les rôles qui la voient ; la dépingler → la bannière disparaît (le rang dans la liste n'en dépend pas).
- [ ] **Réordonner** via ↑/↓ → l'ordre d'affichage change sur la page Infos ; les positions restent densifiées (pas de trou).
- [ ] **Modifier** puis **archiver** une note → elle disparaît de la page Infos et des bannières ; le contenu HTML est nettoyé (Markup::clean — pas d'injection).
- [ ] `[auto:S13]` **Contrôle d'accès** : un coach n'accède pas à `/admin/infos` (403 ou masquage) — seule l'édition membre `/infos` lui est ouverte.

### 8.8 ⚠️ Bascule de saison (PRD §4.4) — À FAIRE EN DERNIER
- [ ] Déclencher la **suspension de masse** → tous les athlètes passent « accès suspendu », leurs inscriptions **futures** sont annulées (promotions déclenchées), coachs/admins conservent leurs rôles.
- [ ] **Réactiver individuellement** un athlète → il peut se réinscrire.
- [ ] Nouvelle année sportive : recalcul des catégories (les surclassements sont réinitialisables via l'action dédiée).
- [ ] **Recharger le seed** après ce test.

---

## 9. Transversal — PWA, push, emails

- [ ] **PWA** : « Installer l'application » (Chrome/Android, Safari/iOS ≥ 16.4) → icône, splash, plein écran.
- [ ] **Offline** : charger le planning, couper le réseau, recharger → contenu de secours servi (lecture) ; retour réseau → à jour.
- [ ] **Push** (nécessite `php artisan vapid:generate` + clés en `.env`) : activer depuis Profil → notification reçue appareil verrouillé ; sur iOS uniquement si PWA installée (limitation documentée — l'email prend le relais).
- [ ] **Emails** : en démo, vérifier le contenu dans `storage/logs/laravel.log` (en prod : Brevo). Chaque notification email correspond à une ligne outbox.

---

## Suivi

| Parcours | Testeur | Date | Verdict |
|---|---|---|---|
| 1. Athlète | | | |
| 2. Suspendu | | | |
| 3. Coach | | | |
| 4. Coach-athlète | | | |
| 5. Parent P1 | | | |
| 6. Parent multi-enfants | | | |
| 7. Mineur P2 | | | |
| 8. Admin | | | |
| 9. Transversal | | | |
