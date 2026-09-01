# Harnais E2E (Playwright)

Tests de **rendu et d'interaction réels** dans un navigateur : ils cliquent, attendent les mises à
jour Livewire, vérifient l'état **en base** après l'action, et produisent des captures d'écran.

Ils couvrent ce que PHPUnit ne peut pas voir : l'apparence, la bascule responsive 768 px, et le
parcours complet d'une action (clic → dialog → confirmation → effet en base).

## Hors de la porte de qualité — volontairement

`composer check` **n'exécute pas** ces tests. Ils exigent un serveur lancé, une base de démo seedée
et un navigateur ; les y intégrer rendrait la porte de qualité fragile pour de mauvaises raisons.
La référence de non-régression reste la suite PHPUnit (`tests/Unit`, `tests/Feature`).

`phpunit.xml` ne scanne que ces deux répertoires : `tests/E2E` est ignoré sans configuration.

## Prérequis

```bash
php artisan serve                          # sur 127.0.0.1:8000
php artisan migrate:fresh
php artisan db:seed --class=CatalogSeeder
php artisan db:seed --class=DemoSeeder     # jeu de démo attendu par les scénarios
npx playwright install chromium            # une seule fois
```

### Sans rien installer : tout en conteneurs

Serveur, base et navigateurs peuvent tourner en conteneurs, ce qui évite d'installer PHP, MySQL et
les navigateurs de Playwright sur la machine. Recette complète — construction des images, réseau,
seed, et la subtilité du partage de pile réseau qui rend `BASE` joignable —, dans
[doc/DOCKER_LOCAL.md](../../doc/DOCKER_LOCAL.md) §4.

## Exécution

```bash
node tests/E2E/run.mjs          # les 3 suites non destructives (verdict agrégé)

node tests/E2E/scenarios.mjs    # S1–S5   gardes d'inscription et bascule de rôle
node tests/E2E/parcours.mjs     # S7–S17, S21  parcours métier, cloisonnement, alertes parent
node tests/E2E/comptes.mjs      # S18–S20 messages d'auth, correction d'email, suspension d'accès
node tests/E2E/responsive.mjs   # S6      bascule mobile/desktop
```

Sortie : une ligne par assertion (✅/❌), code de sortie non nul si un scénario échoue.
Les captures atterrissent dans `tests/E2E/shots/` (non versionné).

### Service worker — sans navigateur ni serveur

```bash
node --test tests/E2E/sw.test.mjs
```

`public/sw.js` n'est joignable ni par PHPUnit (qui ne voit pas le JavaScript) ni par Playwright (on
ne pilote pas un clic sur une notification système). Ce fichier charge le vrai `sw.js` dans une
portée feinte — un faux `self` qui capture les écouteurs — puis rejoue `notificationclick` à la
main. Runner intégré de Node, **aucune dépendance ajoutée**, aucun prérequis : contrairement au
reste du répertoire, il tourne sans serveur ni base.

### Scénarios destructifs — à part

```bash
node tests/E2E/destructif.mjs --oui-je-sais
```

Ils modifient durablement le jeu de démo (rupture de tutelle, gardes RGPD) et **reconstruisent la
base** en fin d'exécution (`migrate:fresh` + les deux seeders). Le drapeau explicite évite un
lancement accidentel. Ne pas remplacer la reconstruction par un simple `db:seed` : `DemoSeeder`
crée les séances sans garde d'unicité, donc un re-seed seul empile un jeu complet de doublons
(mesuré : 74 → 147 séances).

## Sécurité

`auth.php` ouvre une session **sans mot de passe** (magic link à usage unique) et `sql.php` exécute
des requêtes brutes. Les deux **refusent de s'exécuter si `APP_ENV != local`**.

## Couverture

| # | Scénario | Vérifie | PLAN_TESTS |
|---|---|---|---|
| S1 | Bascule coach → athlète | parcours complet + effet en base + remise en état | §3.5, §4 |
| S2 | Séance hors catégorie | bouton masqué, motif affiché, barre fixe non vide | §1.4 |
| S3 | Athlète suspendu | aucune action offerte, message explicite | §2 |
| S4 | Parent garant | le sujet enfant n'altère pas les actions propres | §5, §6 |
| S5 | Admin pur | ni inscription athlète, ni CTA coach | §8 |
| S6 | Responsive | coquilles mobile/desktop, pas de débordement horizontal | consignes |
| S7 | Pages d'info | cloisonnement `all` / `coach` / `admin` | §1.10, §3.6, §8.7 |
| S8 | Quota NAT (1/sem) | dialog de dépassement, annulation sans effet | §1.4 |
| S9 | Planning | filtrage catégoriel (adulte vs jeune) | §1.3 |
| S10 | Séance annulée | bandeau, actions gelées | §1.5 |
| S11 | Séance passée | inscriptions closes, débrief ouvert | §1.4, §1.7 |
| S12 | Parent pur | agit pour l'enfant, pas pour lui-même | §7 |
| S13 | Écrans admin | 403 pour un coach | §8.6 |
| S14 | Écrans admin | rendus et non vides pour l'admin | §8.6 |
| S15 | Sélecteur d'athlètes | suspendu et déjà-inscrits exclus | §2, §3.4 |
| S16 | Séance pleine | file d'attente rejointe, statut `waitlist` | §1.4 |
| S17 | Quota — mécanisme C | déblocage coach : bouton actif/désactivé, promotion, `AuditLog` | §1.4, §3.4 |
| S21 | Alertes d'un garant | ses alertes et celles de son enfant se distinguent (préfixe, séance nommée) | §5, §6 |
| D1 | RGPD | suppression refusée pour un garant de P1 | §8.4 |
| D2 | Tutelle | rupture P2 + `AuditLog guardianship_severed` | §6 |
| D3 | Bascule de saison | double validation, suspension de masse, réactivation, nouvelle année | §8.8 |

## Cibles dérivées, jamais d'id en dur

Les séances du jeu de démo sont placées **relativement à `now()`**, mais leur position par rapport à
l'instant du run dépend du **jour et de l'heure** : la natation du mercredi 18h15 est future si l'on
seede un lundi, déjà commencée si l'on lance la suite le mercredi soir — **y compris juste après un
`db:seed`**. Les ids, eux, dépendent de l'ordre d'insertion.

Coder `fiche(page, 8)` rendait donc un scénario vert ou rouge selon le moment du run, et pire :
S10 pointait une séance qui n'était plus annulée, et passait quand même sur un faux positif.

**Règle du harnais** : une séance se sélectionne par ses **propriétés**, via `seance(where)` ou
`seanceFuture(where)` de [`lib.mjs`](lib.mjs) — « la prochaine séance annulée », « une séance saturée
que Noah peut rejoindre », « une séance dont la file quota est non vide ». Le helper **lève** si
aucune ne convient, ce qui transforme une précondition disparue en erreur explicite plutôt qu'en
assertion trompeuse. Il en va de même pour les utilisateurs : `SELECT id FROM users WHERE email=…`,
jamais `user_id=5`.

## État de la base

Les scénarios **restaurent l'état** qu'ils modifient (S1 remet Mathieu encadrant, S8 restaure la
waitlist de Marie, S17 remet toute la file quota en place et purge ce qu'il a écrit aux journaux).

### Le garde-fou : `run.mjs` refuse un run qui laisse des traces

La règle existait, rien ne la vérifiait — et une remise en état incomplète ne se voit que des runs
plus tard, quand un scénario part d'un jeu appauvri. Parfois jamais : l'assertion devient simplement
moins exigeante, et reste verte.

`run.mjs` photographie donc l'état du jeu **avant** et **après** (comptes, séances, inscriptions par
statut, files, promotions, apéros, file d'envoi, journaux), et **fait échouer le run** sur le moindre
écart, en nommant le compteur qui a bougé :

```
❌ DÉRIVE DU JEU DE DÉMO — un scénario n'a pas restauré ce qu'il a modifié :
  journal d'audit : 10 → 12
  file quota dépassé : 2 → 1
```

Deux fuites réelles ont été trouvées ainsi, toutes deux invisibles depuis le début :

- **S17 ne restaurait qu'un promu sur deux.** `fillQuota` promeut **toute** la file quota d'un coup
  (§4.10.4) ; la remise en état ne portait que sur le premier. Le jeu de démo perdait une entrée de
  file quota à **chaque** run, définitivement.
- **Les notifications de promotion restaient « en attente ».** Ce n'est pas cosmétique : le prochain
  passage du cron les aurait **réellement envoyées**, pour des promotions défaites depuis.

### Restaurer aussi les journaux

Une action passe par l'interface : elle écrit dans `audit_logs`, `activity_logs` et
`notification_outbox`. Deux helpers de [`lib.mjs`](lib.mjs) encadrent le scénario :

```js
const journaux = repereJournaux();   // au début : hauteur des trois journaux
…
purgeJournaux(journaux);             // à la remise en état : n'efface QUE ce que le run a produit
```

La coupe se fait **par id**, jamais par type d'action : c'est le seul critère qui distingue à coup
sûr les lignes du run de celles que le jeu de démo contient déjà.

> **Une interruption casse la restauration.** Si un scénario plante (timeout Playwright, précondition
> absente), le code de remise en état qui suit ne s'exécute pas et la base garde l'état modifié — le
> run suivant part alors d'un état faussé. En cas de doute, repartir d'une base propre :
>
> ```bash
> php artisan migrate:fresh --seed --seeder=CatalogSeeder && php artisan db:seed --class=DemoSeeder
> ```
>
> (~15 s). Ne **pas** faire un simple `db:seed` sur une base existante : `DemoSeeder` est additif sur
> les séances et les dupliquerait.

> **Mécanismes A et B non automatisés en E2E, délibérément.** Ce sont des effets de bord serveur
> (promotion automatique sur place libérée, libération du propre quota) : ils ne se déclenchent par
> aucun geste dédié et leur effet porte souvent sur *un autre* utilisateur ou *une autre* séance. Les
> rejouer au navigateur reviendrait à réécrire `QuotaTest` / `RegistrationNotificationTest` en
> beaucoup plus lent, sans rien observer de plus. **C** est automatisé parce qu'il est le seul des
> trois à être un geste d'interface (un bouton, deux états, deux rendus).

## Ce qui reste en test manuel

PWA / offline / push (§9), import CSV (§8.3), export XLSX (§8.6), et l'appréciation visuelle fine
— un test vérifie qu'un élément est là, pas qu'il est réussi.

## Écrire un scénario

`lib.mjs` fournit `session(browser, email, viewport)` (connexion par magic link),
`fiche(page, id)`, `barreMobile(page)`, `sql(query)` et la classe `Scenario` (assertions agrégées,
captures, rapport). Deux règles :

1. **Apparier toute assertion négative à un contrôle positif** — « Kevin absent » ne vaut rien si
   la liste est vide ; on vérifie donc aussi qu'un athlète éligible est bien proposé.
2. **Restaurer ce qu'on modifie**, sinon la suite n'est plus rejouable.
