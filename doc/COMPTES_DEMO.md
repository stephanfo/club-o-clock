# Comptes de démo — référence testeurs

> ⚠️ **Jeu de démonstration.** Le club « TEAM44 », ses adhérents, ses coachs, ses codes partenaires
> et ses identifiants sont **entièrement fictifs**. Seuls les lieux sont des équipements municipaux
> réels et publics, choisis pour que le géocodage et la météo soient démontrables sur des
> coordonnées valides. **Ne jamais exécuter `DemoSeeder` sur une instance de production** : il crée
> des comptes dont le mot de passe est `password`.

> Généré par le `DemoSeeder`. **Tous les comptes** ont le mot de passe **`password`** (sauf les comptes
> sans credential, cf. cas P1 ci-dessous).
>
> Rechargement complet du jeu de démo :
> ```bash
> php artisan migrate:fresh
> php artisan db:seed --class=CatalogSeeder
> php artisan db:seed --class=DemoSeeder
> ```
> Toutes les dates (séances, compétitions, AG) sont **relatives au jour du seed**.

## Semaine type seedée

Générée sur **6 semaines glissantes** à partir du lundi de la semaine du seed.

| Jour | Heure | Séance | Lieu | Ciblage |
|---|---|---|---|---|
| Mardi | 18:45 | Vélo piste — mardi | Stade de la Davray | Toutes catégories |
| Mercredi | 20:15 | Natation mercredi soir | Piscine de la Charbonnière | Adultes · quota NAT · capacité 42 |
| Jeudi | 19:30 | Course à pied jeudi | Stade de la Davray | Minimes → Master |
| Vendredi | 18:30 | PPG | Salle de la Davray | Toutes catégories |
| Samedi | 09:30 | Natation samedi matin — jeunes | Piscine de la Charbonnière | Jeunes · quota NAT · **capacité 6** → file d'attente |
| Samedi | 10:45 | Natation samedi matin — adultes | Piscine de la Charbonnière | Adultes · quota NAT · capacité 24 |
| Dimanche | 08:30 | Vélo — Grosses / Moyennes / Petites Cuisses | Parking de la piscine | Adultes — 3 niveaux, même départ |
| Dimanche | 10:45 | Enchaînement — jeunes | Stade de la Davray | Jeunes |

> 💡 **Les 3 sorties vélo du dimanche partent du même point** (le parking de la piscine), qui est
> aussi le départ des **16 traces GPX** de la bibliothèque (§4.20) — de quoi démontrer le filtre
> par secteur cardinal et la réutilisation d'un même parcours sur plusieurs séances.

---

## Légende des états

| État | Signification |
|---|---|
| **Admin / Coach / Athlete** | Rôles cumulables (`roles` JSON). Le statut « parent garant » est une **relation** (`guardian_id`), pas un rôle. |
| **Mineur** | `is_minor` dérivé de la date de naissance (< 18 ans au 31/08 de fin de saison). |
| **P1** | Compte mineur **sans credential** (pas d'email ni de mot de passe), géré par son garant. Notifications routées vers le **garant seul** (§4.15.5). |
| **P2** | Compte mineur **avec son propre compte** (email + mot de passe) **et** lien garant actif. Notifications routées vers **l'enfant ET le garant**. |
| **Suspendu** | `athlete_access_suspended` — ne peut plus s'inscrire aux séances, mais le compte reste **actif** (distinct de `is_active`). |

---

## Encadrement

| Email | Prénom Nom | Rôles | Spécificité |
|---|---|---|---|
| `admin@demo.club` | Admin Demo | Admin | Compte administrateur (acteur des logs / `created_by`). |
| `vincent@demo.club` | Vincent Coach | Coach | Qualifications **BF2** (valide) + **PSC1 expirée** (badge « expirée » sur les fiches qu'il encadre). |
| `damien@demo.club` | Damien Coach | Coach | Qualification **BF1** valide. |
| `karine@demo.club` | Karine BNSSA | Coach | Qualification **BNSSA** valide. |
| `mathieu@demo.club` | Mathieu Coach | **Coach + Athlete** | 🟢 **Multi-rôle** : coache ET s'inscrit aux séances. |
| `julien@demo.club` | Julien Coach | Coach | — |
| `nathalie@demo.club` | Nathalie Coach | Coach | — |

---

## Athlètes adultes

| Email | Prénom Nom | Spécificité |
|---|---|---|
| `marie@demo.club` | Marie Dupont | Participe à la compétition passée → **a publié un débrief**. |
| `lucas@demo.club` | Lucas Martin | Participe à la compétition passée → **a publié un débrief**. |
| `sophie@demo.club` | Sophie Bernard | Master. |
| `manon@demo.club` | Manon Laurent | — |
| `tom@demo.club` | Tom Simon | — |
| `ines@demo.club` | Inès Michel | — |
| `hugol@demo.club` | Hugo Lefebvre | — |
| `camille@demo.club` | Camille Leroy | — |
| `maxime@demo.club` | Maxime Roux | — |
| `julie@demo.club` | Julie David | — |
| `antoine@demo.club` | Antoine Bertrand | — |
| `sarah@demo.club` | Sarah Morel | — |
| `paul@demo.club` | Paul Fournier | Master. |
| `emilie@demo.club` | Émilie Girard | Master. |
| `romain@demo.club` | Romain Bonnet | Master. |
| `laura@demo.club` | Laura Dupuis | Master. |
| `kevin@demo.club` | Kévin Lambert | 🔴 **Accès athlète suspendu** (`athlete_access_suspended`) — ne peut plus s'inscrire, compte toujours actif. |
| `brigitte@demo.club` | Brigitte Ancienne | 🔴 **Accès athlète suspendu** — ancienne adhérente non renouvelée ; sert de cible à la **réactivation** sans toucher à Kévin. |

---

## Cas mineurs / parents

### Couples parent garant ↔ enfant

| Garant (parent) | Enfant | Phase | Détail |
|---|---|---|---|
| `florence@demo.club` — Florence Garnier | **Lucie Garnier** | 🟣 **P1** | Lucie n'a **pas de compte** (email + mot de passe nuls). Géré par Florence. Notifications → **Florence seule**. |
| `olivier@demo.club` — Olivier Mercier | `theo.mercier@demo.club` — **Théo Mercier** | 🔵 **P2** | 👤 **Parent pur** : Olivier n'a **aucun rôle** (ni athlète ni coach) — il n'existe que comme garant. Théo a son **propre compte** (mot de passe `password`) + lien garant actif. Notifications → **Théo ET Olivier**. |
| `sandrine@demo.club` — Sandrine Faure | **Jade Faure** | 🟣 **P1** | 👨‍👩‍👧‍👦 **Garant de 2 enfants**, et **athlète elle-même**. Jade n'a **pas de compte** (gérée par Sandrine). |
| `sandrine@demo.club` — Sandrine Faure | `noah.faure@demo.club` — **Noah Faure** | 🔵 **P2** | Second enfant de Sandrine. Compte propre (`password`) + lien garant actif. **Surclassé** : Cadets (principale) + Juniors. |

> 💡 **UI parent** : un garant connecté voit l'entrée **« Mes enfants »** (nav) et le **sélecteur
> « Tu consultes »** sur l'Accueil et le Planning — il bascule entre lui-même et chaque enfant pour
> consulter/inscrire **au nom de l'enfant** (jamais en se connectant à sa place).

> ⚠️ **Lucie (P1) n'a pas d'identifiants** : on ne se connecte pas avec elle. On la gère via le compte
> de sa garante **Florence**.
>
> 💡 Aucun prénom n'est partagé entre l'encadrement et les adhérents : les comptes coach et les
> comptes athlète/enfant se distinguent au premier coup d'œil.

### Mineurs autonomes (cas limite)

Ces comptes sont **mineurs** (`is_minor`) mais possèdent leur propre credential **sans garant rattaché**.
Utiles pour tester l'affichage des catégories jeunes (Benjamins → Cadets) côté athlète.

| Email | Prénom Nom | Catégorie d'âge |
|---|---|---|
| `hugo@demo.club` | Hugo Petit | Benjamins |
| `lea@demo.club` | Léa Robert | Benjamins |
| `nathan@demo.club` | Nathan Richard | Minimes |
| `chloe@demo.club` | Chloé Durand | Minimes |
| `enzo@demo.club` | Enzo Moreau | Cadets |

### Mineur SANS garant (orphelin de tutelle)

**Timéo Vidal** (Benjamins) — mineur **sans compte** (pas d'email/mot de passe) **et sans garant
rattaché** (`guardian_id` nul). C'est le seul cas de ce type dans le jeu. Il sert à tester la
**(ré)association parent ↔ enfant** en tant qu'admin (§4.2) :

- **Depuis la fiche de Timéo** (Admin → Adhérents → Timéo Vidal) : bandeau « Mineur sans parent
  garant » + sélecteur **« Rattacher un garant »** → choisir un adulte actif.
- **Depuis la fiche d'un parent** (ex. `sandrine@demo.club`) : carte **Pupilles** → bouton
  **« Ajouter un pupille »** → choisir Timéo.

> 💡 Ce flux permet aussi de **refaire un lien après l'avoir rompu** : sur un enfant P2, « Rompre la
> tutelle » (P2→P3) le rend orphelin, puis « Ajouter un pupille » / « Rattacher un garant » le ré-associe.

---

## Récap des cas couverts

| Cas à tester | Compte(s) |
|---|---|
| Admin | `admin@demo.club` |
| Coach simple | `vincent@demo.club` (et 5 autres) |
| **Multi-rôle** (coach + athlete) | `mathieu@demo.club` |
| Athlète adulte | `marie@demo.club` (et 16 autres) |
| **Accès athlète suspendu** | `kevin@demo.club`, `brigitte@demo.club` (ancienne adhérente non renouvelée) |
| **Mineur P1** (sans compte, géré par garant) | Lucie Garnier (via `florence@demo.club`), Jade Faure (via `sandrine@demo.club`) |
| **Mineur P2** (compte propre + garant) | `theo.mercier@demo.club` (garant `olivier@demo.club`), `noah.faure@demo.club` (garant `sandrine@demo.club`) |
| **Garant de 2 enfants (P1 + P2), athlète lui-même** | `sandrine@demo.club` |
| **Parent pur** (garant sans aucun rôle — ne peut pas s'inscrire lui-même) | `olivier@demo.club` |
| Garant (parent) | `florence@demo.club`, `olivier@demo.club`, `sandrine@demo.club` |
| Mineur autonome | `hugo@demo.club`, `lea@demo.club`, … |
| **Mineur sans garant** (rattachement / ré-association admin §4.2) | Timéo Vidal (fiche admin — pas de compte) |
| **Auteur de débrief** | `marie@demo.club`, `lucas@demo.club` |
| **Suppression RGPD demandée** (tampon 7 j en cours, annulable ; `is_active = false` → login refusé) | `gilles@demo.club` |
| **Suppression RGPD éligible** (tampon écoulé → bandeau admin ; `is_active = false`) | `daniel@demo.club` |
| **Surclassement** (2 catégories) | `noah.faure@demo.club` |

---

## États seedés (au-delà des comptes)

| État | Où le voir |
|---|---|
| **Séance annulée avec inscrits** | Une « Course à pied jeudi » future est annulée → bandeau « Séance annulée » sur la fiche, notifications d'annulation dans **Alertes** des inscrits et dans **Admin → Envois**. |
| **Apéro « parké »** | Le flag apéro de la séance annulée est garé (cascade §4.14.4) — il réapparaît si la séance est restaurée. |
| **Historique de notifications** | ~60 % des lignes outbox sont `sent`, le reste `pending` → les deux états sont visibles dans **Admin → Envois**, et l'écran **Alertes** n'est pas vide. |
| **Template archivé** | « Natation lundi soir (ancien créneau) » dans **Admin → Modèles** (filtre statut). |
| **Club_event avec inscrits** | L'AG (1ᵉʳ vendredi du mois prochain) a 4 présences déclarées. |
| **Séance en lieu texte libre** | « Sortie trail nature » (~J+9) : `location_text` sans lieu géocodé → pas de carte ni de météo. |
| **Qualification expirée** | PSC1 de Vincent → badge « expirée » dans l'onglet Encadrement des fiches. |
| **Pages d'information** | 4 notes club dans **Infos** (visibilité par niveau) : 2 codes promo pour **tous** (Sport Attitude épinglé en bannière d'accueil + Aquagliss), 1 code de portail piscine pour **coachs**, 1 fiche d'identifiants extranet pour le **bureau**. L'athlète ne voit que les 2 premières, le coach 3, l'admin les 4. Réordonnables via ↑/↓ dans **Admin → Pages d'info**. Tous les codes et mots de passe sont **factices** (`DEMO-…`, `demo-password`). |
