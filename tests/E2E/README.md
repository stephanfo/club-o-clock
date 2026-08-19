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

## Exécution

```bash
node tests/E2E/run.mjs          # les 3 suites non destructives (verdict agrégé)

node tests/E2E/scenarios.mjs    # S1–S5   gardes d'inscription et bascule de rôle
node tests/E2E/parcours.mjs     # S7–S16  parcours métier et cloisonnement
node tests/E2E/responsive.mjs   # S6      bascule mobile/desktop
```

Sortie : une ligne par assertion (✅/❌), code de sortie non nul si un scénario échoue.
Les captures atterrissent dans `tests/E2E/shots/` (non versionné).

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
| D1 | RGPD | suppression refusée pour un garant de P1 | §8.4 |
| D2 | Tutelle | rupture P2 + `AuditLog guardianship_severed` | §6 |

Les scénarios **restaurent l'état** qu'ils modifient (S1 remet Mathieu encadrant, S8 restaure la
waitlist de Marie).

## Ce qui reste en test manuel

PWA / offline / push (§9), import CSV (§8.3), export XLSX (§8.6), bascule de saison (§8.8),
et l'appréciation visuelle fine — un test vérifie qu'un élément est là, pas qu'il est beau.

## Écrire un scénario

`lib.mjs` fournit `session(browser, email, viewport)` (connexion par magic link),
`fiche(page, id)`, `barreMobile(page)`, `sql(query)` et la classe `Scenario` (assertions agrégées,
captures, rapport). Deux règles :

1. **Apparier toute assertion négative à un contrôle positif** — « Kevin absent » ne vaut rien si
   la liste est vide ; on vérifie donc aussi qu'un athlète éligible est bien proposé.
2. **Restaurer ce qu'on modifie**, sinon la suite n'est plus rejouable.
