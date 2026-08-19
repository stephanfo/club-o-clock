# Harnais E2E (Playwright)

Tests de **rendu et d'interaction réels** dans un navigateur : ils cliquent, attendent les
mises à jour Livewire, vérifient l'état en base et produisent des captures d'écran.

Ils couvrent ce que PHPUnit ne peut pas voir : l'apparence, la bascule responsive 768 px,
et le parcours complet d'une action (clic → dialog → confirmation → effet en base).

## Hors de la porte de qualité — volontairement

`composer check` **n'exécute pas** ces tests. Ils exigent un serveur lancé, une base de démo
seedée et un navigateur ; les y intégrer rendrait la porte de qualité fragile pour de mauvaises
raisons. La référence de non-régression reste la suite PHPUnit.

## Prérequis

```bash
php artisan serve                          # sur 127.0.0.1:8000
php artisan db:seed --class=DemoSeeder     # jeu de démo attendu par les scénarios
npx playwright install chromium            # une seule fois
```

## Exécution

```bash
node tests/E2E/scenarios.mjs    # 5 scénarios métier, 23 assertions
node tests/E2E/responsive.mjs   # bascule mobile/desktop au point de rupture 768 px
```

Sortie : une ligne par assertion (✅/❌), code de sortie non nul si un scénario échoue.
Les captures atterrissent dans `tests/E2E/shots/` (non versionné).

## Sécurité

`auth.php` ouvre une session **sans mot de passe** (magic link) et `sql.php` exécute des
requêtes brutes. Les deux refusent de s'exécuter si `APP_ENV != local`.

## Scénarios

| # | Cas | Vérifie |
|---|---|---|
| S1 | Mathieu bascule coach → athlète | parcours complet + effet en base + remise en état |
| S2 | Séance hors catégorie | bouton masqué, motif affiché, barre fixe non vide |
| S3 | Athlète suspendu | aucune action offerte, message explicite |
| S4 | Parent garant | le sujet enfant n'altère pas les actions propres |
| S5 | Admin pur | ni inscription athlète, ni CTA coach |
| S6 | Responsive | coquilles mobile/desktop, pas de débordement horizontal |

Les scénarios **restaurent l'état** qu'ils modifient (S1 remet Mathieu encadrant).
