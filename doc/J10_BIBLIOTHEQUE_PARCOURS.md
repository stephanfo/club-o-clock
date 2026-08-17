# Bibliothèque de parcours GPX (J10)

> **Statut : prêt à coder** — cadrage validé le 2026-07-29, revue de complétude le 2026-08-01 (§12).
> À dérouler sur une **branche dédiée** (`feat/gpx-route-library`), en 3 sous-jalons J10.A → J10.C
> (+ J10.D optionnel, cf. §10).
> Prérequis **confirmé le 2026-08-01** : rien en production, aucune donnée à préserver → migrations
> éditées en place, `migrate:fresh --seed`. Pas de migration incrémentale, pas de reprise de données.

## Context

Aujourd'hui un GPX est une pièce jointe d'**une** séance : 5 colonnes `route_*` sur `sessions`, un fichier dans `storage/app/private/gpx/`, visible uniquement depuis l'onglet Parcours de la fiche séance. Le club accumule donc des traces qui ne sont consultables qu'en retrouvant la séance qui les portait.

Le besoin : offrir aux adhérents un **espace de consultation** pour leurs sorties « off » — parcourir les traces collectées, filtrer par direction, distance, discipline et point de départ, visualiser les circuits sur une carte, et télécharger le GPX. Ce qui impose deux changements de fond :

1. **Une entité `GpxRoute` réutilisable** — un parcours devient un objet de première classe, référencé par N séances, et créable directement sans séance.
2. **Des données géographiques persistées** — aujourd'hui aucune coordonnée n'est en base ; le filtrage par direction/zone est impossible sans elles.

Contrainte structurante : le cadrage §7.6 interdit le parsing GPX côté serveur (surface XXE). L'extraction géo reste donc **100 % client** (`resources/js/gpx.js`), le serveur borne et rejette. Cette décision est **confirmée et élargie**, pas amendée.

> **Rien n'est en production.** Aucune donnée à préserver : on modifie les migrations existantes en place et on repart d'un `migrate:fresh --seed`. Pas de migration incrémentale, pas de double-écriture, pas de reprise de données — tout cet échafaudage disparaît.

### Décisions actées

| Sujet | Décision |
|---|---|
| Modèle | Entité `GpxRoute` réutilisable + création directe sans séance |
| Extraction géo | Client uniquement, persistée, bornée/rejetée serveur |
| Exploration | Liste filtrable, carte d'ensemble, fiche détaillée. **Recherche par zone repoussée en J10.D optionnel** (2026-08-01) |
| Perf carte | Polyline simplifiée (Douglas-Peucker) stockée en base |
| Direction | Secteur cardinal calculé (8 secteurs), **cap vers le centroïde de la bbox** (2026-08-01), filtre par chips |
| Accès | Lecture tous membres · écriture coach+admin · suppression admin |
| Formulaire séance | **Upload direct OU sélection** d'un parcours existant |
| Seeder | Vrais fichiers `.gpx` de test + `GpxRoute` complètes |
| Navigation | Entrée principale « Parcours » (sidebar + bottom-nav, **6 entrées assumées**) |

**Nommage** : `GpxRoute` (table `gpx_routes`) — évite la collision avec la facade `Illuminate\Support\Facades\Route`, reste en anglais comme les autres entités du PRD.

---

## 1. Schéma

### Nouvelle migration `create_gpx_routes_table`

```
id, name string(160), description text?, discipline_id FK?(nullOnDelete)
gpx_path string(255), gpx_hash char(64)?, gpx_original_name string(255)?, gpx_size_ko uint?

-- métriques (promues en colonnes pour filtrer/trier en SQL)
distance_km decimal(6,1)?, dplus_m umediumint?, dmoins_m umediumint?
alt_min_m smallint?, alt_max_m smallint?          -- SIGNÉ (altitude négative possible)
point_count uint?, duration_min uint?   -- durée de l'ENREGISTREMENT source, pas du parcours (cf. note)

-- géo (extrait client)
start_lat/start_lng/end_lat/end_lng decimal(10,7)?   -- aligné sur locations.latitude
is_loop bool default false                            -- start≈end < 250 m
elongation decimal(4,2)?                              -- côté long / côté court de la bbox (cf. note)
bbox_min_lat/bbox_min_lng/bbox_max_lat/bbox_max_lng decimal(10,7)?
bearing_deg usmallint?                                -- 0..359
sector char(2)?                                       -- N|NE|E|SE|S|SO|O|NO
polyline json?                                        -- PAS de ->default() (MySQL 8.4)
elevation_profile json?                               -- idem

-- OpenRunner (mêmes règles que sessions : un parcours réutilisable a droit à son embed)
openrunner_embed_url string(500)?, openrunner_public_url string(500)?

-- provenance
start_location_id FK?(locations, nullOnDelete)
created_by FK?(users, nullOnDelete)                   -- nullable dès la 1re migration (RGPD)
archived_at timestamp?, archived_by FK?(users, nullOnDelete)
timestamps
```

**Index** : `discipline_id` · `['archived_at','sector']` · `['archived_at','distance_km']` · `['bbox_min_lat','bbox_max_lat']` · `gpx_hash` · `start_location_id`.

> **`elongation` — forme du circuit** (ajout 2026-08-01, sur constat des 50 GPX réels du club).
> Les fixtures fournies sont **toutes des boucles** au sens `is_loop` (départ-arrivée : 8-22 m) :
> ce booléen ne discrimine donc rien sur un club qui roule en boucles. Or le club distingue bien
> deux familles dans ses propres noms de fichiers — `_b` (arrondi) et `_d` (étiré) — et la mesure
> le confirme : ratio d'emprise ~1,0-1,4 pour les arrondis, ~1,5-2,8 pour les étirés (chevauchement
> réel, aucun seuil parfait).
>
> Colonne **décimale**, pas booléenne : le seuil de bascule (`GpxRoute::ELONGATION_THRESHOLD = 1.45`)
> est une décision d'affichage, qu'on ne fige pas en base. Calculée **serveur** dans
> `GpxStats::elongation()` à partir de la bbox déjà validée — le client ne l'envoie pas (une valeur
> de moins à transporter et à ne pas croire). Longitude corrigée par `cos(lat)`, sinon un circuit
> est-ouest paraîtrait plus étiré qu'il ne l'est. Emprise dégénérée → `null`.
>
> Filtre (chips « Arrondi » / « Étiré », `scopeShape()`) en **J10.B**, index `['archived_at','elongation']`.

> **Relief — indicateur de difficulté du dénivelé** (ajout 2026-08-02, demande utilisateur).
> **Aucune colonne** : le ratio `dplus_m / distance_km` (m/km, l'unité usuelle du cyclisme) se calcule
> à la volée depuis deux colonnes déjà présentes. Le stocker imposerait de recalculer tout le corpus
> à chaque ajustement de seuil, pour un gain nul à 50-150 parcours.
>
> **Les seuils sont RELATIFS au terrain du club, et c'est un choix assumé.** Le corpus réel s'étale
> de 3,9 à 9,1 m/km (médiane 6,9) : un barème absolu de cyclisme — vallonné à partir de ~10 m/km,
> montagne au-delà de ~20 — classerait **toutes** les traces en « facile » et ne trierait rien.
> `GRADE_ROLLING_MAX = 6.3` et `GRADE_HILLY_MAX = 7.3` sont calibrés sur les quartiles observés
> (répartition 4 / 6 / 8 sur 18 traces). Conséquence : « Exigeant » signifie *exigeant pour nos
> sorties* — d'où l'affichage systématique de la **valeur brute** sous le libellé, qui ne ment pas.
> À revoir si le club se met à rouler en montagne.
>
> **Invariant à préserver** : `scopeGrade()` compare `ROUND(dplus_m / distance_km, 1)` et non le
> ratio brut, pour classer exactement comme `gradeLabel()`. Sans cet arrondi, une trace à 7,2503
> s'affiche « Exigeant · 7,3 m/km » tout en sortant du filtre « Exigeant » — 4 des 18 traces du
> corpus étaient dans ce cas. **La valeur affichée fait foi.** Couvert par `GpxRouteGradeTest`.
>
> Écarté : l'amplitude (`alt_max - alt_min`), trop sensible au bruit GPS — deux traces du corpus
> mesurent `alt_min = -1 m` en Loir-et-Cher — et muette sur le profil (une bosse ou dix). La pente
> max et la longueur des montées seraient plus fidèles au ressenti, mais imposent une extraction
> client supplémentaire, une colonne et un lissage anti-bruit : à reconsidérer si le ratio déçoit.
>
> Livré en **J10.A** : `gradeIndex()`, `gradeLabel()`, `scopeGrade()` et l'affichage sur la fiche
> séance. **Reste à faire en J10.B** : les chips « Roulant / Vallonné / Exigeant » dans les filtres
> de la bibliothèque — le scope est déjà écrit et testé, il n'y a que l'UI à brancher.

> **`duration_min` n'est pas une propriété du parcours** (précision 2026-08-01). C'est la durée de la
> *sortie enregistrée* par celui qui a produit la trace : deux adhérents sur le même circuit n'ont pas
> la même. On la conserve (elle est déjà extraite, elle informe), mais elle est **libellée
> « Temps de l'enregistrement »** et non « Durée », et elle **n'est ni filtrable ni triable** dans la
> bibliothèque — contrairement à `distance_km` et `dplus_m`. Aucun index dessus.

Pièges appliqués : pas de `DEFAULT` sur JSON (MySQL 8.4 en prod à terme, cf. mémoire projet) · `sector` en `char(2)` + constante applicative plutôt qu'enum · FK anonymisables nullable dès la première migration (invariant ROADMAP_DEV).

### Modification en place de `create_sessions_table`

[create_sessions_table.php](../database/migrations/2026_01_01_000290_create_sessions_table.php), bloc parcours — on l'édite directement :

```php
// --- Parcours, tous kind (§4.13) ---
$table->string('route_openrunner_embed_url')->nullable();
$table->string('route_openrunner_public_url')->nullable();
$table->foreignId('route_id')->nullable()->constrained('gpx_routes')->nullOnDelete();
```

Supprimées : `route_gpx_path`, `route_stats` (portées par `GpxRoute`) et `route_openrunner_id` (morte depuis J7.4, jamais écrite). Les deux URLs OpenRunner **restent** sur `sessions` : c'est du par-séance, pas du GPX.

> Ordre de chargement : `create_gpx_routes_table` doit être **horodatée avant** `create_sessions_table` pour que la contrainte FK passe. Comme `create_sessions_table` porte la date du 2026-06-16, la nouvelle migration prend un timestamp antérieur (ex. `2026_06_16_165927_`), juste avant elle. C'est légitime ici : on réécrit l'historique d'une base jamais déployée.

**Cardinalité** : `sessions.route_id` (`belongsTo`), pas de pivot — une séance a 0 ou 1 parcours, un parcours sert N séances (`hasMany` inverse, qui alimente « séances où ce parcours a été utilisé »).

### Déduplication

`gpx_hash` = `hash_file('sha256', …)` — lecture d'octets, **aucune interprétation du XML**, donc §7.6 intact (~15 ms sur 5 Mo). Index **non-unique** : on signale, on ne bloque pas.

Comme le formulaire de séance garde un upload direct **en plus** du sélecteur, le risque de doublon est réel (un coach re-uploade une trace déjà en bibliothèque). L'UI de suggestion — `<x-banner kind="info">` « Ce GPX existe déjà : *Boucle Loire 42 km* » + « Utiliser ce parcours » / « Créer quand même » — devient donc **utile dès J10.A**, pas différable.

Limite assumée : ne détecte que les fichiers binairement identiques (un même parcours exporté de Strava et d'OpenRunner ne matchera pas). À documenter dans le PRD.

---

## 2. Extraction client — [resources/js/gpx.js](resources/js/gpx.js)

`parseGpx()` est étendue de façon **strictement additive** (`gpxMap` ne lit que `.points`, inchangé) :

```js
{
  points, pointCount, distanceKm, dplus, dmoins, altMin, altMax, durationMin,  // existant
  start: {lat, lon}, end: {lat, lon}, isLoop,
  bbox: {minLat, minLon, maxLat, maxLon},
  bearing,            // 0..359, cap start → CENTROÏDE de la bbox (cf. encadré)
  sector,             // N|NE|E|SE|S|SO|O|NO  (français : O, pas W)
  polyline,           // simplifiée ≤ 200 points, arrondie 5 décimales
  elevationProfile,   // [[distKm, altM]] ~120 échantillons, null sans altimétrie
}
```

Fonctions privées à ajouter : `bearingBetween()` (cap great-circle initial), `sectorFromBearing()` (`SECTORS[Math.round(deg/45) % 8]`), `simplify()`, `elevationProfileFrom()`. Réutilise le `haversine()` existant (ligne 12).

> **Référence du cap : le centroïde de la bbox, pas le point le plus éloigné** (décision 2026-08-01).
> Le point le plus éloigné répond à « jusqu'où ça va », mais il est **arbitraire sur une boucle** —
> le cas dominant en vélo : deux exports de la même boucle avec un départ décalé de 500 m, ou parcourue
> en sens inverse, donnent des secteurs différents. Le centroïde (`(minLat+maxLat)/2`,
> `(minLng+maxLng)/2`) est stable quels que soient le point de départ et le sens, et répond à la vraie
> question de l'adhérent : *« ce parcours part vers quel coin ? »*.
>
> Conséquence assumée : une boucle qui tourne **autour** du point de départ a un centroïde proche du
> départ, donc un cap peu significatif (mais jamais aberrant — il reste déterministe). On ne masque
> pas le secteur dans ce cas : introduire une 9ᵉ valeur « Autour » complique la rose des vents et le
> filtre SQL pour un gain marginal. À revoir si l'usage montre du bruit.
>
> Test de non-régression associé (§9) : **même trace, deux points de départ différents → même secteur**.

**Douglas-Peucker maison (~35 lignes), pas de dépendance** : `simplify-js` imposerait de toute façon d'écrire la projection lat/lon (`x = lon·cos(latMoy)`) soi-même, pour un gain net nul contre une entrée `package.json` et une surface supply-chain. **Tolérance adaptative par dichotomie** (~12 itérations) plutôt que fixe : une tolérance fixe donne 40 points sur une boucle de 5 km et 900 sur un trail de 80 km.

Payload : ~180 points × 20 o ≈ **3,6 ko par route**.

**`gpxField`** — isoler le bloc lourd sous une clé `geo`, pour ne pas polluer l'affichage et donner un point de validation serveur unique :
```js
this.meta = { name, sizeKo, distanceKm, dplus, dmoins, altMin, altMax, pointCount, durationMin };
this.$wire.set('gpxStats', { ...this.meta, geo: {...} }, false);   // deferred, déjà le cas ligne 122
```

---

## 3. Validation serveur — `app/Support/GpxStats.php`

Classe statique, voisine de [OpenRunner.php](app/Support/OpenRunner.php) et `Markup.php`. `SessionForm::sanitizeGpxStats()` (lignes 233-252) y est **déplacée telle quelle** → `GpxStats::sanitize()`, plus :

> **Le rejet en bloc est silencieux — et ça se voit (incident 2026-08-02).** Deux parcours déposés
> le matin du 2026-08-02 se sont retrouvés avec `bbox_*`, `sector`, `bearing_deg`, `polyline` et
> `elevation_profile` tous nuls, alors que leurs métriques (distance, D+, altitudes) étaient
> correctes. Cause : le bundle JS servi ne contenait pas encore l'extraction géo de J10.A — le bloc
> `geo` n'a jamais été envoyé. Les fichiers eux-mêmes étaient parfaitement valides (1946 points avec
> altitude, bbox cohérente).
>
> Le symptôme est traître : en bibliothèque, un parcours sans géo **paraît normal**, il est juste
> absent des filtres Direction et Forme, et sans profil sur sa fiche. D'où l'ajout d'un **liseré sur
> la fiche parcours** (`<x-alt-profile>` ne rend rien, mais `.or-fallback` explique pourquoi), avec
> invitation à redéposer le fichier réservée à ceux qui peuvent le faire. Test :
> `GpxRouteShowTest::test_a_route_without_geo_shows_a_notice`.
>
> **Aucune réparation serveur n'est possible** : recalculer la géo imposerait de parser le GPX côté
> serveur, ce que le cadrage §7.6 interdit. Le seul remède est de redéposer le fichier par le chemin
> normal. Les deux parcours concernés ont été supprimés (fichiers exportés au préalable) plutôt que
> rustinés. Réflexe à garder : **après toute évolution de `gpx.js`, rebâtir les assets avant de
> déposer quoi que ce soit** — sinon l'extraction tourne avec l'ancien code, sans la moindre erreur.

- `sanitizeGeo()` — **rejet, pas clamp** pour les coordonnées : une lat hors `-90..90`, une bbox inversée, ou un `start` hors bbox annulent **tout le bloc géo**. Justification de l'asymétrie avec `sanitize()` : clamper une coordonnée aberrante à 90/180 placerait le parcours au pôle Nord et polluerait carte et recherche par zone ; une métrique aberrante clampée est sans conséquence.
- `sector` recalculé serveur depuis `bearing` s'il est incohérent · `isLoop` recalculé (`haversine < 250 m`, 2 lignes PHP).
- `sanitizePolyline()` — tronquée à **250 paires** (garde-fou anti-payload hostile), arrondie 5 décimales, `< 2` → null.
- `sanitizeElevationProfile()` — tronqué 200 paires, bornes `distKm 0..100000`, `altM -1000..10000`.

**`app/Services/GpxRouteService.php`** — point de passage **unique** pour toute création de GPX dans l'app (formulaire de bibliothèque *et* dropzone de séance) : `store('gpx','local')`, `hash_file`, appels `GpxStats::*`, suppression de l'ancien fichier, `AuditLogger::record()`. Méthodes : `createFromUpload()`, `replaceGpx()`, `findDuplicateByHash()`, `delete()`.

> `delete()` refuse si `$route->sessions()->exists()` → propose l'archivage. Sinon `nullOnDelete` viderait silencieusement le parcours de N fiches séance.

**Validation du fichier — source unique** (décision 2026-08-01). La règle « ≤ 5 Mo + extension `.gpx` » vit aujourd'hui dans [SessionForm::rules()](app/Livewire/SessionForm.php#L191) ; `GpxRouteForm` en aurait besoin à l'identique. Plutôt que de la dupliquer (divergence silencieuse garantie le jour où la limite bouge), `GpxStats` porte :

```php
public const MAX_KB = 5120;                     // 5 Mo (§4.13.2)
public static function fileRules(bool $required = false): array   // ['file','max:5120', closure extension]
```
appelée par les deux formulaires. Cohérent avec le rôle de `GpxStats` comme garde serveur unique.

> Limite d'upload non vérifiée en prod : aucun `php_value upload_max_filesize` dans [public/.htaccess](public/.htaccess), pas de `config/livewire.php` publié (défauts Livewire). 5 Mo passe *a priori* sur OVH Pro, mais la bibliothèque va multiplier les uploads — **à tester avec un vrai fichier de 4-5 Mo au premier déploiement de J10.A**, avant de compter dessus.

### Cycle de vie des fichiers (décision 2026-08-01)

Constat : il n'existe **aucun ramasse-miettes** de fichiers dans l'app (les seules commandes sont `DrainNotifications`, `RefreshWeather`, `GenerateVapidKeys`). Le modèle partagé retire par ailleurs la suppression automatique que faisait `persist()`.

Règle retenue, volontairement simple :

| Événement | Fichier sur le disk |
|---|---|
| Séance détachée de son parcours (`removeGpx`) | **Conservé** — le parcours vit sa vie en bibliothèque |
| Parcours archivé | **Conservé** — l'archivage est réversible, restaurer sans le fichier n'aurait aucun sens |
| Parcours supprimé par un admin (orphelin) | **Supprimé** par `GpxRouteService::delete()` |
| Dernière séance d'un parcours supprimée | Parcours et fichier **conservés** (le parcours redevient simplement orphelin) |

**Limite assumée, à documenter au PRD** : un parcours archivé ou orphelin conserve son fichier indéfiniment. À 5 Mo maximum par trace et à l'échelle d'un club de 50-150 adhérents, le volume reste négligeable devant le quota mutualisé — on n'écrit pas de commande de purge pour ça. Si le besoin apparaît, un `gpx:purge-orphans` branché sur le cron existant se rajoute sans rien changer au modèle.

---

## 4. Composants Livewire

**`App\Livewire\GpxRouteLibrary`** — `/parcours`, un seul composant à 3 rendus (pattern [Planning.php](app/Livewire/Planning.php), partials Blade séparés) :
```php
#[Url] $mode = 'list';   // list | map | zone
#[Url] $search, $discipline, $sector, $dist, $startLocation, $bbox, $sort;
public int $perPage = 24;   // PAS #[Url], comme MemberList
```
Filtres = `<button wire:click>` mutant une propriété `#[Url]` ; cartes = `<a wire:navigate>` **sans** `wire:click` (piège documenté en mémoire projet : ne jamais empiler les deux).

> **Filtres à sélection multiple** (décision utilisateur 2026-08-02). Les cinq filtres à chips
> (`sector`, `discipline`, `shape`, `grade`, `distance`) sont des `array` et non des `string` :
> au sein d'un filtre les valeurs **s'unissent** (OU), entre filtres elles **se croisent** (ET).
> L'URL devient `?sector[]=N&sector[]=NE`. `toggle()` accumule et retire ; `isOn()` pilote l'état
> visuel des chips.
>
> Le besoin est démontré sur le **secteur** : 8 valeurs à 2 parcours chacune, et « rouler vers le
> nord » veut dire NO+N+NE — inexprimable en mono-select. Sur `grade` et `shape` (3 et 2 valeurs qui
> partitionnent le corpus), cocher 2 valeurs sur 3 revient à peu près à tout afficher : le filtre y
> perd en pouvoir discriminant. **Uniformité retenue quand même** — un utilisateur qui découvre que
> le secteur est multiple s'attend à ce que le reste le soit, et l'incohérence coûterait plus que
> la redondance.
>
> **Distance : les tranches contiguës sont fusionnées** avant traduction SQL (`applyDistance`).
> Cocher 50-60, 60-70 et 70-80 produit `>= 50 AND < 80`, pas trois `OR` — c'est l'usage dominant
> (on coche des voisines pour exprimer une plage) et une chaîne de OR sur une colonne indexée
> dégrade le plan sans rien apporter. La dernière tranche (`100+`, borne haute `null`) reste ouverte
> à droite après fusion.
>
> **Les valeurs viennent de l'URL, donc forgeables.** `GpxRoute::filterByUnion()` fait de la table
> `valeur => contrainte` à la fois la **liste blanche et l'implémentation** : une clé inconnue est
> écartée en silence, et il est structurellement impossible qu'une valeur acceptée n'ait pas de
> traduction SQL. C'est ce qui remplace le `match` sans branche par défaut, qui aurait levé un
> `UnhandledMatchError` le jour où la liste blanche et le `match` auraient divergé.

Calqué sur [MemberList](app/Livewire/Admin/MemberList.php#L100), qui est le pattern de référence :
- `updated()` **liste explicitement** les propriétés qui réinitialisent la fenêtre (`in_array($name, ['search','discipline','sector','dist','startLocation','bbox'], true)`) — pas un reset aveugle : `$sort` et `$mode` ne doivent pas rembobiner la pagination.
- `loadMore()` fait `$this->perPage += 24`.
- **Recherche** : `like` sur `name` **et** `description` (`%$term%`), même forme que [MemberList l.129-132](app/Livewire/Admin/MemberList.php#L129). Pas de recherche fulltext ni sur les relations en V1. Requête avec `with(['discipline','startLocation'])` **obligatoire** (`preventLazyLoading` hors prod) ; en mode carte, `select()` partiel qui **doit inclure les colonnes de FK**.

**`App\Livewire\GpxRouteShow`** — `/parcours/{gpxRoute}`. `loadMissing()` **dans `render()`**, pas `mount()` (mémoire projet : relations imbriquées perdues à la ré-hydratation). Actions archive/restore/delete, chacune avec son `authorize()` dans la méthode.

**`App\Livewire\GpxRouteForm`** — `/parcours/creer` et `/parcours/{gpxRoute}/modifier`. Miroir court de `SessionForm`, délègue à `GpxRouteService`. **Ré-autoriser dans `save()`**, pas seulement `mount()` (les actions Livewire ne repassent pas par mount). Aucun champ en `wire:model.live` — sinon les ~6 ko de `geo` voyagent à chaque frappe.

**`SessionForm` — deux chemins** :
- **Sélection** : un champ « Parcours » qui pose `route_id` (recherche parmi les `GpxRoute` actives + lien « créer un parcours »).
- **Upload direct** : la dropzone existante est conservée, mais `persist()` ne stocke plus de fichier lui-même — il appelle `GpxRouteService::createFromUpload()` et pose le `route_id` retourné. Avant création, `findDuplicateByHash()` alimente la bannière de doublon.

Points de détail vérifiés dans le code, à ne pas oublier :
- Propriétés `$route_gpx_path` / `$route_stats` ([l.87-89](app/Livewire/SessionForm.php#L87)) → remplacées par un unique `?int $route_id`.
- [Ligne 137](app/Livewire/SessionForm.php#L137) `$this->gpxStats = $s->route_stats` sert **l'affichage des métadonnées en édition** : recharger depuis `$s->gpxRoute` (distance/D+/D−/alt/taille), pas depuis un champ mort.
- `persist()` ([l.469-473](app/Livewire/SessionForm.php#L469)) : la suppression de l'ancien fichier (`Storage::delete($oldGpxPath)`) **disparaît d'ici** — un parcours est désormais partagé, le fichier ne suit plus le cycle de vie de la séance. Seul `GpxRouteService::delete()` supprime un fichier.
- **`removeGpx()` ([l.225-232](app/Livewire/SessionForm.php#L225)) change de sens** (décision 2026-08-01). Son commentaire actuel — *« la suppression du fichier stocké a lieu au save »* — devient faux et dangereux : le bouton ne doit plus rien supprimer, seulement **détacher**. À reprendre en entier : ne remet que `route_id` à `null`, libellé **« Retirer le parcours de cette séance »**, commentaire réécrit. Le parcours et son fichier restent en bibliothèque. Sans ça, un coach qui « retire » une trace croirait la supprimer — ou, pire, la supprimerait pour les 12 séances qui la référencent.
- Les règles de validation `gpxFile` ([l.191-195](app/Livewire/SessionForm.php#L191)) → `GpxStats::fileRules()` (§3).

**`contentChanges()` — le changement de parcours notifie les inscrits** (décision 2026-08-01). Aujourd'hui [l.414-419](app/Livewire/SessionForm.php#L414) agrège OpenRunner + GPX en un seul booléen présent/absent : un remplacement de trace serait donc silencieux. Avec des parcours nommés et réutilisables, passer de *Boucle Loire* à *Boucle Cher* est un changement de contenu que l'inscrit doit voir — il choisit sa séance en partie sur le parcours. La ligne « Parcours » compare donc les `route_id` et affiche les **noms** (`before`/`after` = nom du parcours ou « aucun »), au lieu de « présent »/« aucun ». Les URLs OpenRunner gardent la comparaison actuelle dans la même ligne.

### 4 bis. Consommateurs existants à réécrire (inventaire `grep`, 2026-08-01)

Retirer `route_gpx_path` / `route_stats` / `route_openrunner_id` casse **du code de production**, pas seulement des tests. Inventaire exhaustif (`grep -rn 'route_gpx_path\|route_stats\|route_openrunner_id' app resources tests database routes`) :

| Fichier | Traitement |
|---|---|
| [Session.php:28-36](app/Models/Session.php#L28) | Retirer des `$fillable` + le cast `route_stats => array`. Ajouter `route_id` et la relation `gpxRoute()` (`belongsTo`). |
| `SessionGpxController.php` | **Supprimé**, avec sa route `sessions.gpx` — décision 2026-08-01. (Pas de lien : le fichier n'existe plus.) Tout passe par `gpx-routes.gpx` : un seul contrôleur, une seule policy. Rien n'est en prod, aucune URL ne circule. |
| [fiche-parcours.blade.php](resources/views/livewire/partials/fiche-parcours.blade.php) | **Réécriture minimale** (décision 2026-08-01) : `$r = $session->route_stats` → les colonnes de `$session->gpxRoute` ; les 2 `route('sessions.gpx', $session)` (carte `gpxMap` + bouton de téléchargement) → `route('gpx-routes.gpx', $session->gpxRoute)`. **Markup et classes inchangés** (`.route-seg`, `.route-metrics`, `.rm`, `.or-fallback`) : c'est déjà le portage fidèle de `screen-parcours.jsx`, on n'y retouche pas le design. Ajouts : le **nom** du parcours et un lien « Voir la fiche du parcours » vers `/parcours/{id}`. Le profil altimétrique et les séances liées restent sur la fiche parcours, pas ici. |
| [session-show.blade.php:59](resources/views/livewire/session-show.blade.php#L59) et [:274](resources/views/livewire/session-show.blade.php#L274) | `$hasRoute` / condition d'affichage de l'onglet Parcours : `$session->route_gpx_path` → `$session->route_id`. |
| [SessionForm.php](app/Livewire/SessionForm.php) l.87-89, 135-137, 228-229, 414-419, 469-502 | Cf. ci-dessus. |
| [GpxUiTest.php](tests/Feature/GpxUiTest.php) l.44-48, 76, 85, 96, 101 | Cf. §9. |

> Pas de composant partagé `<x-route-block>` entre fiche séance et fiche parcours : les deux contextes divergent (onglet OpenRunner d'un côté, séances liées + profil altimétrique de l'autre) — un composant commun dégénérerait en sac à paramètres. Duplication assumée du bloc métriques.

**`app/Policies/GpxRoutePolicy.php`** (auto-découverte Laravel 11, comme les 3 policies existantes) : `viewAny`/`view` → tous · `create`/`update`/`archive` → coach+admin · `delete` → admin seul (même asymétrie que `SessionPolicy` : un coach ne doit pas pouvoir supprimer un GPX que 12 séances référencent). **Policy à la naissance de l'entité** — invariant ROADMAP_DEV.

Aucune gate nouvelle n'est nécessaire : il n'y a plus d'écran admin dans cette feature.

---

## 5. Carte d'ensemble et recherche par zone

**Composant Alpine `gpxRoutesMap`** dans `gpx.js` (même fichier : partage `haversine` et l'import dynamique Leaflet ; un fichier séparé dupliquerait Leaflet dans un 2e chunk).

### Livré le 2026-08-02 — écarts assumés par rapport à la spec ci-dessus

**1. Endpoint JSON, et non polylines inline.** La spec prévoyait `@js()` inline. **Mesure faite avant de coder** : 18 parcours = **69 Ko de polylines**, et le corpus visé est de 50-150. Le point manqué par le chiffrage initial est que Livewire **re-sérialise l'intégralité du state à chaque requête** — donc à chaque frappe de la recherche (`debounce 300ms`) et à chaque bascule de chip. 69 Ko × N interactions, là où un endpoint ne paie qu'une fois par jeu de filtres.

D'où [`GpxRouteTracesController`](../app/Http/Controllers/GpxRouteTracesController.php) sur `/parcours-traces` (segment propre : `/parcours/traces` entrerait en concurrence avec `/parcours/{gpxRoute}`). Mesuré : 72 Ko sans filtre, 12 Ko sur `sector=SO`, 4 Ko sur une recherche.

**2. Les filtres sont RÉAPPLIQUÉS côté serveur**, jamais reçus sous forme de liste d'ids. `GpxRouteLibrary::tracesQuery()` est publique et partagée par la liste et la carte : **une seule écriture des filtres**, donc impossible que la carte diverge de la liste. Le contrôleur instancie le composant et lui assigne les paramètres d'URL.

**3. Canal Livewire → îlot = l'événement `gpx-routes-filtered`, comme le prévoyait la spec.**

> **Tentative ratée, corrigée le 2026-08-02 — le piège vaut d'être retenu.** J'avais d'abord remplacé
> l'événement par un `x-effect="load(@js($tracesUrl))"`, jugé « moins de plomberie ». **Ça ne marche
> pas** : filtrer depuis le mode carte ne mettait pas la carte à jour. La raison est mécanique —
> `wire:ignore` empêche le re-render du sous-arbre, donc **ses attributs ne sont jamais remplacés**.
> L'expression `x-effect` reste celle du montage, avec l'URL initiale gravée dedans ; et Alpine ne
> réévalue un `x-effect` que si une dépendance **réactive** change, or une URL interpolée par Blade
> est une constante littérale. Les deux raisons se cumulent.
>
> **Règle générale : pour parler à un îlot `wire:ignore`, il faut un canal qui ne passe pas par le
> DOM** — donc un événement Livewire (`dispatch` → `x-on:…​.window`), exactement comme
> `location-located` → `locationMap.relocate()`. Aucune interpolation Blade ne franchit `wire:ignore`.

`notifyMap()` est appelée depuis les **trois** voies de mutation, et pas seulement `updated()` : les
chips passent par `toggle()` et le bouton par `resetFilters()`, qui sont des **actions** — `updated()`
ne se déclenche que sur les `wire:model`. C'est le second piège de cette correction.

**4. Couleurs en dur en JS — écart à la règle design, assumé.** La spec voulait lire `--bike` via `getComputedStyle`. Mais une couleur *par discipline* ne distingue rien : le club roule presque tout en vélo, les 18 tracés seraient de la même couleur. Il faut une palette **cyclique par index** pour séparer deux tracés voisins — ce que le design system ne fournit pas (il n'a pas de palette catégorielle). Les 6 teintes reprennent celles des tokens.

**5. `preferCanvas: true`** conservé tel quel.

**6. Popup au clic** conservé (décision utilisateur 2026-08-02), mais **sans `wire:navigate`** : le popup est construit en DOM hors de la portée de Livewire (`wire:ignore`), l'attribut n'y serait jamais câblé. Navigation pleine page. Le contenu est bâti par `createElement`/`textContent` et non par concaténation : les noms de parcours sont saisis par les coachs.

**Gardes ajoutées, non prévues par la spec :**
- **Anti-course** (`reqId`) : une réponse lente ne peut pas écraser l'affichage d'une requête émise après elle. Sans cette garde, taper vite dans la recherche laisse la carte sur un état arbitraire.
- **`wanted`** : l'import dynamique de Leaflet prend quelques centaines de ms, pendant lesquelles l'utilisateur peut déjà avoir coché une chip. On charge l'état le plus récent, pas celui du montage.
- **Plafond `MAX_TRACES = 120`** + `truncated` renvoyé, pour que la vue signale la troncature au lieu de mentir par omission.
- **Cible de clic élargie** (`renderer: L.canvas({ tolerance: 10 })`), corrigée le 2026-08-02 après essai réel : les tracés n'étaient **pas cliquables au doigt**. En `preferCanvas`, Leaflet détecte le clic à `weight / 2 + tolerance` du trait (`_clickTolerance`, dans `leaflet-src.js` — non versionné, cf. `node_modules/`), soit **1,5 px** avec un trait de 3 et une tolérance par défaut nulle. Le piège : `bubblingMouseEvents: false` **n'y joue aucun rôle** (il empêche seulement l'événement de remonter à la carte) — un commentaire du premier jet lui attribuait à tort l'élargissement de la cible. Il faut un `renderer` explicite ; 10 px donnent ~11,5 px de cible sans épaissir le trait.
- **Compteur des parcours sans polyline** affiché sous la carte : le compte de la carte ne peut pas coller à celui de la liste, et l'incident géo du 2026-08-02 (§ plus bas) a montré ce que coûte une perte silencieuse.
- **Verrou d'interaction** repris des cartes de tracé (`lockable`, cf. plus bas). Prévu initialement *sans* verrou — « la carte *est* le contenu du mode, il n'y a pas de page à faire défiler derrière elle » — ce raisonnement s'est révélé faux à l'usage (2026-08-02) : les **filtres restent au-dessus de la carte**, et 62vh de Leaflet capturent le scroll avant qu'on les atteigne. Même dispositif exactement (voile « Toucher pour interagir » + bouton de re-verrouillage), à deux différences près : le voile n'apparaît qu'une fois les tracés dessinés (sinon il se superposerait au voile de chargement), et le re-verrouillage **referme le popup ouvert**, qui resterait sinon à intercepter les clics au-dessus du voile.

### Recherche par zone — repoussée en J10.D optionnel (décision 2026-08-01)

Le dessin de rectangle (~40 lignes de gestion tactile), le filtre bbox, le raffinement polyline anti-faux-positifs et sa bannière d'explication forment **le morceau le plus cher du jalon**, pour un bénéfice incertain : à 50-150 routes, la carte d'ensemble les affiche **déjà toutes**, et zoomer sur une région répond à la même question sans rien coder. Les chips de secteur couvrent l'autre moitié du besoin.

On livre donc J10.A→C sans le mode zone, on observe l'usage, et on décide ensuite. **Zéro dette** : les colonnes `bbox_*` sont peuplées dès J10.A, donc la fonctionnalité reste activable sans migration ni recalcul. Le `#[Url] $mode` accepte `list|map` en J10.C, `zone` s'y ajoute sans refonte.

Si le besoin se confirme, **la variante « autour d'un lieu » est à préférer au rectangle dessiné** : un select des `locations` du club déjà géocodées + un rayon (10/25/50 km) filtré sur la bbox puis raffiné sur la polyline. Pas de gestion tactile, réutilise l'existant, et colle mieux à la formulation réelle du besoin (*« les sorties qui partent d'ici »*).

La spécification ci-dessous reste valable telle quelle pour J10.D.

**Intersection SQL** — quatre comparaisons indexables, aucune fonction SQL, aucune extension spatiale (portabilité MariaDB dev / MySQL 8.4 prod) :
```php
$q->whereNotNull('bbox_min_lat')
  ->where('bbox_min_lat','<=',$maxLat)->where('bbox_max_lat','>=',$minLat)
  ->where('bbox_min_lng','<=',$maxLng)->where('bbox_max_lng','>=',$minLng);
```
Garde sur les bornes : bbox malformée → filtre ignoré, pas de résultat aberrant. Toggle « chevauche la zone / entièrement dans la zone » (deux `when()`).

> **Limite à afficher dans l'UI** (`<x-banner kind="info">`) : une bbox n'est pas un tracé. Un aller-retour diagonal a une emprise qui couvre des zones où il ne passe jamais → **faux positifs systématiques, aucun faux négatif** (complet mais imprécis). Mitigation à coût quasi nul : raffiner en PHP sur la polyline simplifiée après le filtre SQL (300 × 180 comparaisons ≈ 5 ms).

---

## 6. Design et CSS

**Il n'existe aucun `screen-bibliotheque.jsx`** — vérifié. La règle CLAUDE.md « inventaire avant de coder » donne un résultat négatif : on compose **par analogie**, et c'est un écart assumé à documenter.

| Écran | Référence analogique | Emprunts |
|---|---|---|
| Index — liste | `screen-catalogues.jsx` + `member-list.blade.php` | `.dk-topbar`, `.card`, `.chip.is-active`, « charger plus » |
| Index — bascule 3 modes | `screen-planning.jsx` | `<x-segmented>` (`.seg`/`.seg-item`) |
| Carte de parcours | `<x-session-card>` variant `row` | `.scard.bike/.run/.swim`, `.chip`, `.meta`, `.num` |
| **Fiche parcours** | **`screen-parcours.jsx` directement** | `.route-seg`, `.route-metrics`/`.rm`, `.alt-profile`, `.or-fallback` |
| Formulaire | `session-form.blade.php` (bloc GPX l.255-287) | `.gpx-drop`, `.gpx-loaded`, `.gpx-meta-grid`, `.ifield` |

**CSS nouveau — trois règles, pas une de plus**, toutes sur tokens :
1. `.routes-map { height: clamp(340px, 58vh, 640px); … }` dans `app.css` — `.gpx-map` (ligne 418) est en `height:400px` fixe, inadapté à une carte d'ensemble. Réutilise `.gpx-fswrap`/`.gpx-fsbtn` pour le plein écran.
2. `.sector-chips` — grille 8 chips (rose des vents), conteneur de mise en page uniquement, les chips restent des `.chip`.
3. `.route-grid` — grille responsive 1/2/3 colonnes.

**`.alt-profile` existe déjà** (`club-app.css:679`) mais n'a jamais été porté : `<x-alt-profile>` le porte enfin (J10.C), rendu **serveur** depuis `elevation_profile` (~1,5 ko, statique, pas de JS ni de fetch).

> Le proto (`screen-parcours.jsx` `<AltProfile>`) dessine une polyline **en dur** : c'est une maquette,
> pas un composant. Le portage garde son SVG (viewBox `400×92`, `preserveAspectRatio="none"`, aire
> `--loire` à 12 %) mais calcule les points depuis la trace, avec une **normalisation min/max sur les
> deux axes** — une échelle absolue écraserait un profil de 80 m d'amplitude en ligne plate.
> Trois cas limites couverts par test : trace parfaitement plate (division par zéro → ligne médiane),
> profil absent et profil à un seul point (le composant ne rend **rien** plutôt qu'un cadre vide).
>
> **Axe Y chiffré** (ajout 2026-08-02, demande utilisateur). Sans repère d'altitude, un profil
> normalisé min/max est trompeur : 40 m d'amplitude et 400 m produisent exactement le même dessin.
> Trois graduations (min, milieu, max) dans une **gouttière de 42 px à gauche**, plus des lignes
> d'horizon en pointillé.
>
> Contrainte structurante : le tracé est en `preserveAspectRatio="none"` — il *doit* s'étirer sur
> toute la largeur — ce qui déformerait tout texte placé dans le même SVG. D'où la séparation :
> **gouttière en HTML** (typographie intacte, tokens du design system), **tracé en SVG étiré**, et
> les lignes d'horizon dans le SVG pour rester alignées sur leur graduation quelle que soit la
> largeur. Le flex est porté par une classe `.alt-graded` et non un `:has()`, pour que la forme
> historique `.alt-profile > svg` du proto continue de fonctionner. Sur une trace plate, une seule
> graduation est rendue : trois fois le même chiffre n'apprendrait rien.
>
> **Axe X chiffré** (ajout 2026-08-02, demande utilisateur). Graduations à valeurs **rondes**
> (0, 5, 10… km) et non à positions régulières : un « 11,064 km » pile au milieu du cadre se lit
> moins bien qu'un « 10 km » légèrement décalé — on lit une distance, pas une fraction de graphe.
> Contrepartie assumée : la dernière graduation ne tombe pas sur la fin du parcours.
>
> Le **pas s'adapte à la longueur** (0,5 / 1 / 2 / 5 / 10 / 20 / 50 km selon les seuils 2, 6, 12,
> 30, 60, 120), sinon un 3 km n'aurait qu'une graduation et un 180 km en aurait seize qui se
> chevauchent. Seuils calés sur le corpus du club (vélo 20-90 km, CAP 5-20 km) et sur la place
> disponible : ~250 px de large sur mobile, soit 5 libellés lisibles au maximum — vérifié en rendant
> le composant sur 0,8 / 5 / 22 / 88 km, qui donnent 2 à 6 graduations. Sous le premier pas
> (parcours < 500 m), **pas d'axe du tout** : une graduation solitaire n'informe de rien et coûterait
> quand même la hauteur de la réglette.
>
> La réglette est **dans le cadre** (correction 2026-08-02 après revue visuelle : posée dessous, elle
> flottait hors du bloc encadré), sous le tracé et séparée par un filet. Le cadre passe de **92 à
> 114 px** : la réglette s'*ajoute* au tracé plutôt que de le rogner — l'intégrer à hauteur constante
> aurait coûté un cinquième de la hauteur utile du profil. Elle vit dans `.alt-plot`, qui empile
> désormais SVG (`flex:1`) et réglette : elle hérite ainsi de la largeur exacte de la zone de tracé,
> et une graduation tombe pile sous son abscisse **sans retrait à recaler à la main**.
>
> Piège d'alignement traité au passage : les graduations Y sont positionnées en `%`, calculées sur les
> 92 px du SVG. Rapportées à une gouttière qui fait maintenant 114 px, elles glisseraient toutes sous
> leur ligne d'horizon. D'où `.alt-axis-plot` (même hauteur que le SVG) et la cale muette
> `.alt-axis-foot` qui reproduit la réglette dans la gouttière — cale absente quand il n'y a pas
> d'axe X, les deux colonnes restant alors de même hauteur.
>
> Les libellés sont centrés sur leur position **sauf aux extrémités** (`0` déborderait à gauche, le
> dernier à droite), et l'unité « km » est accolée à la dernière graduation plutôt que posée à droite
> du cadre, où elle chevaucherait ce libellé — souvent situé à ~90 %.
>
> **Bornes kilométriques sur la carte** (ajout 2026-08-02, demande utilisateur). `gpxMap` pose une
> pastille `.gpx-kmdot` (divIcon Leaflet, donc stylée aux tokens) tous les N km, **au même pas que
> les graduations du profil** : sur 22 km, la carte affiche 5/10/15/20 et le profil aussi — lire les
> deux côte à côte ne demande aucune conversion. Le km 0 est omis (c'est le départ).
>
> La position est **interpolée sur le segment qui franchit la borne**, pas arrondie au point GPX le
> plus proche : à 10 s d'échantillonnage, deux points consécutifs sont distants d'une centaine de
> mètres à vélo, et une pastille décalée d'autant se verrait. Calcul sur les points **bruts** et sur
> la distance non arrondie — la polyline simplifiée coupe les virages et raccourcirait le tracé de
> plusieurs centaines de mètres en fin de parcours. La boucle de franchissement est un `while` : un
> trou de signal (tunnel) peut franchir plusieurs bornes en un seul segment. Pastilles **non
> interactives** : un repère informe, et sur un tracé replié elles intercepteraient le déplacement
> de la carte.
>
> **Départ / arrivée** (ajout 2026-08-02, demande utilisateur). Même gabarit que les bornes km — la
> cohérence de lecture prime — distingués par la couleur : **D** en primaire (`--brand`), **A** en
> accent (`--accent`). L'accent et non le rouge : le tracé lui-même est rouge (`#d4282e`) et une pastille
> rouge s'y fondrait. Sur un parcours **en boucle** (`isLoop`, départ et arrivée à moins de
> `LOOP_METERS` = 250 m), une **seule pastille « D/A »** en graphite : deux marqueurs superposés
> n'apprendraient rien. Départ/arrivée passent **au-dessus** des bornes km (`zIndexOffset`) — sur une
> boucle, la borne du dernier kilomètre tombe souvent à quelques mètres du départ et masquerait le
> repère le plus utile de la carte. Largeur des libellés vérifiée : « D/A » ≈ 14 px pour 23 px
> utiles, aucun ajustement typographique nécessaire.
>
> ⚠️ **Duplicata surveillé** : le pas existe en PHP (`$stepKm`, profil rendu serveur) *et* en JS
> (`kmStepFor()`, carte qui parse le GPX client — cadrage §7.6, le serveur ne parse jamais de GPX).
> `tests/Unit/KmStepSyncTest.php` compare les seuils extraits des deux fichiers et casse s'ils
> divergent (garde-fou vérifié par mutation : passer un seuil JS de 5 à 4 fait bien échouer le test).
>
> **Correctif 2026-08-02 (revue) — même table, entrées différentes.** Des tables de seuils identiques
> ne suffisent pas : encore faut-il que les deux fonctions reçoivent la même grandeur. Le profil se
> basait sur `$maxX`, dernier échantillon de `elevation_profile` — or l'échantillonnage à pas
> constant (`total/120`) s'arrête un pas avant la fin, soit ~0,8 % trop court. Sur un parcours juste
> au-dessus d'un seuil (30,06 km → dernier échantillon 29,81), le profil repassait sous la barre des
> 30 km et graduait tous les 5 km quand la carte, travaillant sur le total brut, graduait tous les
> 10. Mesuré par balayage : **0,5 % des longueurs** touchées, toutes agglutinées juste au-dessus des
> seuils. Le composant reçoit désormais la longueur réelle via la prop `distance-km`.
>
> Le test de synchronisation a été **étendu à l'entrée** (il ne comparait que les tables, et ne
> pouvait donc pas voir ce bug) : `test_the_profile_uses_the_real_length_not_its_last_sample` fait
> passer trois longueurs de franchissement dans la table PHP et vérifie que le dernier échantillon
> donnerait un autre pas — le test s'auto-invalide si le cas cesse d'être démonstratif.
>
> **Deux autres correctifs de la même revue.** (1) *Recadrage des libellés de bord par position et
> non par index* : la dernière graduation ronde tombe souvent vers 90 %, où un libellé centré tient
> encore ; l'aligner à droite le décalait de 13 px de sa propre abscisse (mesuré sur une réglette de
> 300 px). Seules les graduations sous 4 % ou au-delà de 96 % sont désormais recadrées. (2) *Hauteur
> conditionnelle* : `.alt-xruled` n'est posée que si la réglette existe — un parcours trop court pour
> être gradué garde ses 92 px au lieu de payer 22 px de bande vide.

Vérification avant merge : `grep -rnoE 'style="[^"]*"' resources/views/livewire/gpx-route-*.blade.php | grep -E '#[0-9a-f]|rgba?\('` doit être vide.

---

## 7. Navigation

**Sidebar desktop** ([layouts/app.blade.php](resources/views/layouts/app.blade.php)) : « Parcours » (icône `route`) après Planning. Le calcul de `$active` (ligne 16) est déjà une chaîne de ternaires de ~700 caractères — **l'extraire dans `App\Support\NavContext::active()`** à cette occasion (10 lignes, sans risque, test de non-régression = chaque écran garde son item surligné).

**Bottom-nav mobile — 6 entrées assumées** (décision utilisateur) :
```
Accueil · Planning · Parcours · [Créer si coach] · [Enfants si garant] · Profil
```
Mesure réelle plutôt qu'estimation : `.botnav-item` est en `flex: 1` avec `padding: 6px 2px` ([club-app.css:234](resources/css/club-app.css#L234)). Sur iPhone SE (375 px moins `padding: 6px 4px`), 6 entrées → **~61 px par cible, ~57 px cliquables**, au-dessus du seuil de 44 px. Le cas à 6 ne concerne que les **coachs-garants** ; les autres profils restent à 4-5.

> Point de vigilance réel : le libellé, pas la cible. `font-size: 10px` ([club-app.css:240](resources/css/club-app.css#L240)) et « Parcours » est le mot le plus long de la barre. À valider visuellement en J10.B ; repli = réduire le padding horizontal de `.botnav-item`, pas changer le mot (cf. §Vérification, point 6).

Ne pas toucher au `position: fixed` de `.botnav` hors `.app-layout` (piège iOS documenté en mémoire projet).

---

## 8. Seeder de démo

`database/seeders/` — quelques fichiers `.gpx` réels committés dans `database/seeders/fixtures/gpx/` (3-5 traces, ~10-30 ko chacune : une boucle vélo, un footing, un aller-retour linéaire, dans des secteurs cardinaux différents pour que les filtres soient démontrables).

Le seeder copie le fichier sur le disk `local` et crée la `GpxRoute` avec sa géo **pré-calculée en dur** dans le seeder (pas de parsing serveur — cohérence §7.6). Quelques séances de démo reçoivent un `route_id`, pour peupler « séances où ce parcours a été utilisé ».

`polyline` et `elevation_profile` doivent recevoir une **valeur explicite** dans le seeder et la factory (pas de `DEFAULT` possible sur JSON).

À vérifier : `DemoSeederIntegrityTest` doit passer avec ces nouvelles données.

---

## 9. Tests

**Nouveaux** (~28) :
- `tests/Unit/GpxStatsTest.php` — clamps repris de `GpxUiTest` ; lat 91 → bloc null ; bbox inversée → null ; start hors bbox → null ; frontière de secteur (bearing 22 → N, 23 → NE) ; `isLoop` recalculé ; polyline 10 000 → 250.
- **Stabilité du secteur** (décision centroïde, §2) : même bbox, deux `start` différents sur la trace → **même secteur**. C'est le test qui protège la décision de 2026-08-01 contre une régression vers « point le plus éloigné ».
- `GpxRouteLibraryTest` — filtres secteur/discipline/distance/forme/relief, recherche, `loadMore`, archivées masquées, **intersection bbox** (route A dans la zone, route B hors → A seule), bbox malformée ignorée sans exception.
- `GpxRouteFormTest` — création coach + `Storage::fake('local')`, athlète interdit (`assertForbidden`), extension refusée, remplacement supprime l'ancien fichier (`Storage::assertMissing`), géo hors bornes → colonnes null mais route créée, polyline tronquée à 250, **doublon détecté par hash**.
- `GpxRouteShowTest` — séances liées affichées, download, 404 fichier manquant, coach ne peut pas supprimer une route utilisée, admin supprime une orpheline (+ fichier), **archiver une route ne supprime pas son fichier** (`Storage::assertExists` — protège la règle du cycle de vie, §3).
- `GpxRouteTracesTest` (28, J10.C bis) — endpoint : auth requise, tracé servi tel qu'il est **stocké** (le fichier GPX peut manquer du disque, la carte s'en moque), parcours sans polyline omis, archivés masqués et **impossibles à révéler en forgeant `?archived=1`** (garde serveur, un coach le peut), filtres honorés à l'identique de la liste, union multi-valeurs, forme scalaire `?sector=SO` acceptée, valeur forgée ignorée, **paramètre imbriqué `?sector[0][0]=` sans TypeError**, plafond `MAX_TRACES` + `truncated`, **et le cas limite du plafond exact** (à 120 pile rien n'est coupé : annoncer la troncature afficherait un avertissement mensonger — off-by-one corrigé le 2026-08-02). Bascule : mode `list` par défaut, `map` rend l'îlot en `wire:ignore`, mode inconnu → retombe sur la liste, l'URL des tracés porte les filtres, changer de mode **ne réinitialise pas la pagination**, écart carte/liste annoncé. **Notification de la carte** (4 tests de régression du correctif du 2026-08-02) : chip, recherche et « Réinitialiser » émettent chacun `gpx-routes-filtered` avec l'URL à jour — les trois voies, parce que `updated()` ne couvre que la recherche — et l'îlot écoute bien l'événement **sans** `x-effect` (dont la présence signerait le retour du bug). **Verrou d'interaction** : la carte est `lockable`, le voile et le bouton de déverrouillage sont présents.
- `SessionFormRouteTest` — sélection d'un parcours existant pose `route_id` ; upload direct crée une `GpxRoute` **et** pose `route_id` ; `contentChanges()` détecte le **remplacement** A → B (pas seulement l'ajout/retrait) et notifie les inscrits ; retirer le parcours d'une séance **ne supprime pas** le fichier (il est partagé) — `Storage::assertExists`.

**Existants à réécrire — une seule fois, sur le modèle cible** :

| Test | Traitement |
|---|---|
| [GpxUiTest.php](tests/Feature/GpxUiTest.php) l.44-48, 76, 85, 96, 101 | Réécrit : `$s->route_gpx_path` → `$s->gpxRoute->gpx_path`, création via `route_id`, download via `gpx-routes.gpx`. `preventSilentlyDiscardingAttributes` fera échouer bruyamment tout oubli — c'est souhaitable. Le test l.76-85 (« retirer le GPX supprime le fichier ») **change de sémantique** : le fichier survit désormais au détachement, seule la route perd son `route_id`. |
| [ParcoursUiTest.php](tests/Feature/ParcoursUiTest.php) | **Aucun impact confirmé** (`grep` 2026-08-01 : aucune occurrence de `route_gpx_path`, `route_stats` ni `sessions.gpx`) — ne touche que les URLs OpenRunner, conservées sur `sessions`. |
| `SessionManagementTest`, `DemoSeederIntegrityTest` | À adapter si le seeder/les assertions touchent les colonnes supprimées. |

**RGPD — vérifié le 2026-08-01, aucune action** : l'anonymisation ([MemberService.php:405-423](app/Services/MemberService.php#L405)) est un **scrub de la ligne `users`** conservée en tombstone (`anonymized_at`), pas un balayage des FK entrantes. `gpx_routes.created_by` / `archived_by` continuent donc de pointer une ligne devenue anonyme, exactement comme `sessions.created_by` aujourd'hui. Le `nullable()` dès la première migration reste néanmoins l'invariant à respecter (§1).

`composer check` (pint + phpstan 5 + tests) vert à **chaque** sous-jalon, sans ajout à la baseline.

---

## 10. Séquencement

> **Redécoupage 2026-08-01.** Le découpage initial J10.1 (schéma) / J10.2 (consommateurs) promettait
> `composer check` vert à chaque sous-jalon — **intenable** : retirer `route_gpx_path`/`route_stats` en
> J10.1 casse immédiatement 5 fichiers de production (§4 bis) que seul J10.2 réparait. Les deux étapes
> sont fusionnées en **J10.A** : c'est le seul point de coupe où la base et le code sont cohérents.
> Sous-jalons renommés en lettres pour éviter toute confusion avec l'ancien découpage.

| Sous-jalon | Contenu | Démontrable |
|---|---|---|
| **J10.A**<br>*fondation + bascule* | Migration `create_gpx_routes_table` + édition en place de `create_sessions_table` · modèle `GpxRoute` (fillable exhaustif, casts, relations dont **`sessions()`**, scopes `active()`/`inBbox()`) · `GpxRoutePolicy` · `GpxStats` · `GpxRouteService` · factory · seeder + fixtures GPX · extension `gpx.js` (bearing centroïde, secteur, Douglas-Peucker, profil alti) · `GpxRouteForm` · `GpxRouteGpxController` + routes · **réécriture des 5 consommateurs (§4 bis)** · bannière de doublon · `GpxStatsTest` + `GpxRouteFormTest` + `GpxUiTest` réécrit | `migrate:fresh --seed` peuple la base ; un coach crée un parcours, l'attache à une séance, le télécharge ; la fiche séance affiche le parcours comme avant |
| **J10.B** | `GpxRouteLibrary` mode liste · filtres secteur / discipline / distance / **forme** (`scopeShape`) / **relief** (`scopeGrade`) · `.route-grid`, `.sector-chips` · navigation sidebar + bottom-nav · `NavContext` | **La feature devient utile** |
| **J10.C** | `GpxRouteShow` · `<x-alt-profile>` · tracé Leaflet (`gpxMap`) · séances liées · archive/restore/delete avec `<x-dialog danger>` + `<x-conseq-row>` · cartes de la bibliothèque et de la fiche séance rendues cliquables | La feature est complète en consultation |
| **J10.C bis** ✅ *2026-08-02* | `gpxRoutesMap` (Leaflet multi-traces, `preferCanvas`) · `GpxRouteTracesController` + route `/parcours-traces` · `tracesQuery()` partagée liste/carte · bascule `.seg` liste/carte · `.routes-map` · `GpxRouteTracesTest` | Vue d'ensemble géographique |
| **J10.D**<br>*optionnel* | Recherche par zone (§5) — **à décider après usage réel**, de préférence en variante « autour d'un lieu » | Filtrage géographique |

J10.A est volumineux mais indivisible : c'est le prix de la bascule de modèle sur une base jamais déployée. J10.B et J10.C sont mergeables seuls. Chaque sous-jalon se termine par `composer check` vert.

> **Scission de J10.C** (décision 2026-08-02). La carte d'ensemble multi-traces est le morceau le
> plus lourd du sous-jalon (Leaflet en canvas, bascule liste/carte, perf mobile à mesurer) et le seul
> qui soit **entièrement isolable** : la fiche parcours n'en dépend pas.
> Elle part donc en **J10.C bis**, committé à part. Bénéfice : la fiche — qui débloque les deux
> écrans précédents, dont les cartes n'étaient pas cliquables — est livrable et validable sans
> attendre, et un problème de rendu carte sur mobile ne bloque plus rien.
>
> **J10.C bis livré le 2026-08-02.** Le plafond initialement prévu à 300 routes inline est devenu
> `MAX_TRACES = 120` **servies par un endpoint JSON** — voir §5 pour les six écarts par rapport à
> la spec, tous motivés par une mesure (69 Ko de polylines re-sérialisées à chaque frappe) plutôt
> que par une estimation. 623 tests verts.
>
> **Revue de branche (2026-08-02) — 3 correctifs.** (1) *Profil altimétrique tronqué* :
> `elevationProfileFrom()` avançait son curseur d'échantillonnage même avant le premier `<ele>`
> rencontré. Sur un GPX dont la tête n'a pas d'altitude — exports Garmin/Strava qui enregistrent
> avant le calage GPS — le profil démarrait au milieu du parcours (mesuré : 61 points à partir de
> 11,064 km au lieu de 121 depuis 0), et `<x-alt-profile>` les étirait sur tout le cadre : le graphe
> **prétendait couvrir tout le tracé**. (2) *`route_id` sans garde `active()`* : `pickRoute()`
> refusait un parcours archivé mais la propriété publique, elle, n'était validée que par
> `exists:gpx_routes,id` — les deux chemins d'entrée divergeaient. La règle exclut désormais les
> archivés, **sauf celui déjà attaché à la séance en édition** (archivé après coup, il ne doit pas
> bloquer une sauvegarde qui n'y touche pas). (3) *Off-by-one sur `truncated`* : `>= MAX_TRACES`
> annonçait une troncature à exactement 120 tracés alors que rien n'était coupé ; l'endpoint demande
> maintenant une ligne de plus et ne la sert pas.
>
> **Verrou d'interaction sur les cartes de tracé** (2026-08-02). `gpxMap` reçoit une option
> `lockable`, calquée sur celle de `locationMap` (§4.13.4) : verrouillée au montage, la carte n'est
> qu'un aperçu que le scroll de la page traverse ; un tap sur le voile la libère, un petit bouton la
> re-verrouille. Sans ça, ses 400 px de haut capturent le défilement sur mobile — le même symptôme
> qui avait motivé le verrou de la carte du lieu. Activé sur la **fiche parcours** et sur l'**onglet
> Parcours de la fiche séance**, les deux seuls usages de `gpxMap`.
>
> Deux détails qui ne se devinent pas : le verrou **se lève automatiquement en plein écran** (il n'y
> a plus de page à faire défiler, et le maintenir rendrait la carte inutilisable) et **se repose à
> la sortie**, sinon on ressortirait sur une carte qui capture le scroll. Et le bouton de verrou est
> décalé à `right: 52px` **uniquement quand le bouton plein écran est présent** : sur iOS, où
> l'API Fullscreen est indisponible, il occupe seul le coin sans laisser de trou.
>
> **Tracé de la fiche parcours : `gpxMap` sur le fichier GPX**, pas la polyline simplifiée. Le
> composant Alpine existe déjà et sert la fiche séance ; le réutiliser tel quel donne un tracé
> complet et fidèle pour zéro ligne nouvelle. La colonne `polyline` reste réservée à la carte
> d'ensemble, où le volume impose la simplification. Coût assumé : un téléchargement de fichier à
> l'ouverture de la fiche, négligeable pour une trace consultée à l'unité.
>
> **Suppression : bouton masqué plutôt que refus au clic.** Dès qu'une séance référence le parcours,
> `GpxRouteShow` ne rend pas le bouton « Supprimer » et affiche la raison en clair. L'UI reflète ce
> que le serveur autorise, au lieu de proposer une action qui échouerait. La garde de
> `GpxRouteService::delete()` reste évidemment en place — une action Livewire est appelable
> directement, l'absence de bouton n'est pas une protection (couvert par un test dédié).

**Documentation** au fil de l'eau : PRD **§4.20** (nouvelle section, avec l'exigence géo formulée **sans nommer de techno** — règle CLAUDE.md) + amendement §5.1 (entité `GpxRoute`, `Session.routeId?`) · CADRAGE §7.6 élargi + **§7.15** (DP maison, plafond 300 routes, bbox SQL) · ROADMAP_DEV **J10**.

---

## 11. Risques

1. **Deux chemins de création de GPX** (upload en séance + formulaire de bibliothèque) — surface doublée en apparence, mais `GpxRouteService::createFromUpload()` est le point de passage unique : la différence tient en une méthode de `SessionForm`, pas en une seconde implémentation. Le risque réel est le **doublon**, traité par `gpx_hash` + bannière dès J10.A.
2. **`preventLazyLoading`** — traître sur le mode carte : un `select()` partiel qui oublie `discipline_id` casse l'eager-load. Toujours inclure les colonnes de FK.
3. **Ordre des migrations** — `gpx_routes` doit précéder `sessions` (contrainte FK). Vérifier par un `migrate:fresh` propre dès J10.A.
4. **Collision `Route`** — évitée par le nommage `GpxRoute`. Reste à ne pas importer la facade dans les composants Livewire (ils utilisent `route()`).
5. **Payload Livewire du formulaire** — `geo` ≈ 6 ko à chaque requête tant que le formulaire est ouvert : aucun `wire:model.live`, `$wire.set(…, false)` deferred.
6. **Carte sur mobile d'entrée de gamme** — 150 polylines en canvas ≈ 2 Mo de mémoire JS. Plafond plus bas sur petit écran si besoin : **à mesurer avant d'optimiser**.

**Différable sans dette** : raffinement PHP anti-faux-positifs de la zone · couleurs par discipline sur la carte · endpoint JSON des polylines (seuil documenté).

---

## Vérification

**Par sous-jalon** : `composer check` vert (pint + phpstan 5), sans ajout à la baseline. Progression
réelle : 533 tests à la fin de J10.A, 551 à J10.B, 569 à J10.C, **608 à J10.C bis** (1588 assertions,
dont les 4 tests de régression du correctif « carte figée » et le verrou d'interaction, 2026-08-02).

> **Corrigé le 2026-08-02.** `composer check` échouait en fin de suite sur
> `JournalUiTest::test_export_returns_xlsx_download` (épuisement mémoire à la génération XLSX, sans
> rapport avec J10) : le `memory_limit` CLI par défaut est de **128 Mo**, quand la suite complète en
> demande davantage. Le script `test` de `composer.json` lance désormais
> `@php -d memory_limit=1G vendor/bin/phpunit`.
>
> Deux fausses pistes écartées en chemin, qui valent d'être notées :
> 1. **512 Mo ne suffisent pas.** Le test passe seul à 512 Mo, mais échoue dans la suite complète :
>    ce n'est pas un pic ponctuel, c'est de la mémoire **accumulée** sur ~600 tests. Mesurer sur la
>    suite entière, jamais sur le test isolé.
> 2. **`-d memory_limit=…` devant `artisan test` est sans effet** : `artisan test` délègue à un
>    **sous-processus** PHPUnit, qui repart du `php.ini`. Il faut appeler `vendor/bin/phpunit`
>    directement — d'où le changement de commande, et pas seulement d'option.

**Manuel, en fin de J10.C** (`migrate:fresh --seed` puis `php artisan serve`) :
1. Le seed peuple la bibliothèque : liste non vide, carte d'ensemble avec plusieurs traces dans des secteurs distincts.
2. Coach → `/parcours/creer`, déposer un `.gpx` réel → distance/D+/secteur cohérents avec un outil tiers (OpenRunner), carte correcte sur la fiche.
3. Re-déposer le **même** fichier → bannière de doublon proposant le parcours existant.
4. Formulaire de séance : sélectionner un parcours existant, puis tester l'upload direct → dans les deux cas `route_id` posé et onglet Parcours de la fiche fonctionnel.
5. Athlète simple → `/parcours` : liste et filtres OK, téléchargement OK, **boutons de création/édition absents**.
6. **Bottom-nav sur iPhone SE réel ou émulé 375 px** : 6 entrées lisibles, libellé « Parcours » non tronqué, cibles confortables. Repli si tronqué : réduire le `padding` horizontal de `.botnav-item` — **pas** changer le mot (« Parcours » est le vocabulaire du PRD §4.13 et de la fiche séance ; « Traces » et « Circuits » créeraient une divergence de langage dans l'app).
7. Supprimer un parcours utilisé par une séance → **le bouton n'est pas proposé**, la raison est affichée et l'archivage reste offert (décision 2026-08-02) ; supprimer une route orpheline en admin → fichier bien retiré du disk. *(Couvert par `GpxRouteShowTest`, y compris l'appel direct de l'action Livewire sans bouton.)*
8. **Retirer** le parcours d'une séance → le libellé dit bien « Retirer le parcours de cette séance », la séance perd son onglet Parcours, et le parcours **reste intact** dans la bibliothèque (fichier compris).
8 bis. Archiver un parcours → il disparaît de la liste, mais le restaurer le rend fonctionnel **avec sa carte et son téléchargement** (le fichier n'a pas été purgé).
8 ter. Déposer un GPX de **4-5 Mo** sur l'instance OVH (pas seulement en local) → l'upload aboutit, pas de 413 ni de timeout.
9. Modifier une séance en **remplaçant** son parcours → l'écran de confirmation liste « Parcours : Boucle Loire → Boucle Cher » et les inscrits sont notifiés.
10. *(si J10.D)* Mode zone : dessiner un rectangle → résultats plausibles ; un parcours diagonal hors zone ne doit **plus** apparaître après le raffinement polyline.

---

## 12. Revue de complétude — 2026-08-01

Passe de vérification du plan **contre le code réel** avant démarrage. Sept décisions prises, toutes intégrées ci-dessus.

| # | Point | Décision |
|---|---|---|
| 1 | `composer check` vert impossible avec J10.1/J10.2 séparés (5 fichiers de prod cassés entre les deux) | **Fusion en J10.A** (§10) |
| 2 | Consommateurs de `route_gpx_path`/`route_stats` absents du plan (contrôleur, 2 vues, modèle) | **§4 bis** — inventaire `grep` exhaustif |
| 3 | `bearing` instable sur les boucles (point le plus éloigné arbitraire) | **Cap vers le centroïde de la bbox** + test de stabilité (§2, §9) |
| 4 | Mode zone = morceau le plus cher pour le bénéfice le moins clair | **Repoussé en J10.D optionnel**, zéro dette (§5) |
| 5 | `contentChanges()` ne détecte que présent/absent → remplacement de trace silencieux | **Le changement de parcours notifie**, avec les noms (§4) |
| 6 | Sort de la route `sessions.gpx` non tranché | **Supprimée**, tout passe par `gpx-routes.gpx` (§4 bis) |
| 7 | `duration_min` sur une entité réutilisable | **Conservée mais requalifiée** « Temps de l'enregistrement », non filtrable (§1) |

### Seconde passe — angles morts (même jour)

Recherche de ce que le plan ne mentionnait **nulle part** (par opposition à la première passe, qui vérifiait ses affirmations).

| # | Point | Décision |
|---|---|---|
| 8 | **Aucun ramasse-miettes de fichiers** dans l'app, et le plan retire la seule suppression existante (`persist()`) | **Table du cycle de vie explicite** (§3) : archive conserve, seul `delete()` admin purge. Limite documentée, pas de commande de purge |
| 9 | `removeGpx()` ([l.225](app/Livewire/SessionForm.php#L225)) jamais cité, et son comportement « supprime le fichier au save » devient faux | **Devient « détacher »** — libellé, commentaire et corps réécrits (§4 bis) |
| 10 | Règle de validation `gpxFile` (5 Mo, `.gpx`) à dupliquer dans les 2 formulaires | **`GpxStats::MAX_KB` + `fileRules()`** (§3) |
| 11 | `updated()` / `loadMore()` / forme de la recherche non spécifiés | **Calqués sur `MemberList`**, reset explicite par liste blanche (§4) |
| 12 | Limite d'upload OVH jamais vérifiée (pas de `php_value`, pas de `config/livewire.php`) | **À tester avec un fichier de 4-5 Mo** au premier déploiement de J10.A (§3) |

Vérifications faites sans changement nécessaire : `ParcoursUiTest` réellement sans impact (`grep`) · l'anonymisation RGPD ne balaie pas les FK entrantes, `created_by` nullable suffit · l'icône `route` existe déjà ([icon.blade.php:66](resources/views/components/icon.blade.php#L66)) · `AuditLogger::record()` a bien la signature supposée · `PRD §4.20` et `CADRAGE §7.15` sont les bons numéros libres (PRD s'arrête à §4.19, cadrage à §7.14).

**Point resté ouvert, à trancher à l'usage** : une boucle qui tourne autour de son point de départ a un secteur peu significatif. On n'introduit pas de 9ᵉ valeur « Autour » maintenant (complique rose des vents + filtre SQL) ; à revoir si le seed puis l'usage réel montrent du bruit.
