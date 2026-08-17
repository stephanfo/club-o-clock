# Contribuer à Club'O'Clock

Merci de l'intérêt que tu portes au projet. Club'O'Clock est développé pour les **clubs sportifs
associatifs** : les contributions les plus utiles viennent souvent de gens qui l'utilisent
réellement dans leur club.

## Avant tout : la langue du projet

Le projet est **en français** — code, commentaires, commits, issues, documentation, interface.
C'est un choix assumé : les utilisateurs finaux sont des bénévoles de clubs français, et l'écart
entre le vocabulaire du code et celui du terrain est une source de bugs. Merci de t'y tenir dans
tes contributions.

## Signaler un bug

Ouvre une **issue** avec :

- ce que tu faisais, ce que tu attendais, ce qui s'est passé ;
- ton rôle dans l'app (admin, coach, athlète, parent) — beaucoup de comportements en dépendent ;
- navigateur / appareil si c'est un problème d'affichage, **mobile ou desktop** ;
- les logs pertinents (`storage/logs/laravel.log`), **expurgés de toute donnée personnelle**.

> ⚠️ **Jamais de données réelles d'adhérents** dans une issue : ni nom, ni email, ni capture
> d'écran non floutée. Reproduis avec le jeu de démonstration
> ([COMPTES_DEMO.md](doc/COMPTES_DEMO.md)) quand c'est possible.

Pour une **faille de sécurité**, n'ouvre pas d'issue : suis [SECURITY.md](SECURITY.md).

## Proposer une fonctionnalité

Le périmètre est cadré par le **[PRD](doc/PRD.md)**, qui distingue le périmètre V1 (§3.1) de ce qui
en est explicitement exclu (§3.2). Avant d'écrire du code :

1. vérifie que ta proposition n'est pas déjà **hors périmètre** — plusieurs exclusions sont
   délibérées, avec leur raison ;
2. ouvre une **issue de discussion** avant une grosse PR. Une fonctionnalité développée puis
   refusée sur le principe, c'est du temps perdu pour tout le monde.

### Ce que le projet ne fera pas

Quelques partis pris structurants, pour t'éviter un travail voué au refus :

- **Pas de multi-tenant.** Un club = une instance. Aucune notion de `tenant_id`, aucune isolation
  logique multi-club. C'est ce qui garde le modèle de données et les autorisations simples.
- **Pas de stockage de numéro de téléphone ni de certificat médical**, et pas de numéro de licence
  fédérale — minimisation RGPD. Ces données vivent sur l'extranet de la fédération.
- **Pas d'application native** iOS/Android : c'est une PWA installable.
- **Pas de dépendance à un service non-UE** dans le chemin critique.

## Développer

### Mise en route

Suis **[doc/INSTALL.md](doc/INSTALL.md)** (§2), puis charge le jeu de démonstration — il couvre
tous les cas tordus (liste d'attente, quotas, mineurs sous tutelle, comptes suspendus) :

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSeeder
```

### Faire évoluer le schéma

La source de vérité est **[database/migrations/](https://github.com/stephanfo/club-o-clock/tree/main/database/migrations/)**. Une évolution passe par une
**nouvelle migration** — ne jamais éditer une migration déjà livrée, des instances l'ont déjà jouée.

`database/schema/mariadb-schema.sql` est un **artefact dérivé** que Laravel charge au début de
`migrate` pour reconstruire vite (tests, base neuve). Après toute migration qui touche au schéma,
régénère-le, sinon les tests repartent d'un schéma périmé :

```bash
php artisan schema:dump      # régénère le dump depuis les migrations
```

### La porte de qualité

**Toute PR doit passer `composer check`** — c'est le prérequis, pas une formalité :

```bash
composer check      # pint (style) + phpstan (niveau 5) + suite de tests
```

Les trois séparément, si tu veux itérer plus vite :

```bash
vendor/bin/pint                                  # corrige le style
vendor/bin/phpstan analyse --memory-limit=1G     # analyse statique
composer test                                    # tests
```

### Attentes sur le code

- **Des tests.** Toute correction de bug apporte un test qui échouait avant. Toute fonctionnalité
  apporte les tests de son comportement, y compris les refus (qui n'a **pas** le droit de faire
  quoi).
- **Fidélité au design system.** Les écrans réutilisent les classes existantes de `club-app.css`
  (`scard`, `seg`, `chip`, `ifield`, `btn-*`…). Avant d'écrire une classe maison, vérifie qu'elle
  n'existe pas déjà. Toute valeur de couleur ou d'espacement passe par un **token** (`var(--…)`),
  jamais en dur.
- **Mobile ET desktop.** L'app est utilisée majoritairement au téléphone. Un écran n'est fini que
  s'il rend correctement dans les deux formats.
- **Vocabulaire du modèle.** Le statut d'inscription est `participating` | `waitlist` |
  `cancelled` — pas d'autre valeur. La récurrence passe par le générateur `SessionTemplate`, pas
  par des RRULE.

### Branches et versions

**`main` est toujours déployable** — c'est la branche que les clubs installent, et la CI y rejoue
`composer check` à chaque push. On ne commite jamais directement dessus.

Une branche courte par sujet, fusionnée après revue, puis supprimée :

```
feat/…   nouvelle fonctionnalité      fix/…    correction de bug
docs/…   documentation seule          chore/…  outillage, dépendances
```

> **Pas de branche `develop`.** Elle sert à intégrer le travail de plusieurs équipes et à maintenir
> plusieurs versions en parallèle — ni l'un ni l'autre ici : un mainteneur, et chaque club déploie
> le `main` courant. Ce serait une branche de plus à synchroniser pour rien.

Les versions sont des **tags semver** sur `main`. Ce que chaque incrément signifie se lit du point
de vue de **celui qui met à jour son instance** :

| Incrément | Pour le club qui déploie |
|---|---|
| **MAJEUR** — 2.0.0 | Une **action manuelle** est nécessaire : nouvelle variable `.env` obligatoire, version de PHP, manipulation irréversible. À lire avant de déployer. |
| **MINEUR** — 1.1.0 | Fonctionnalité nouvelle. Migrations jouées par la checklist habituelle, **rien à décider**. |
| **CORRECTIF** — 1.0.1 | Correction de bug, **aucune migration**. |

Les versions sortent **quand un lot cohérent est prêt**, sans cadence annoncée : le projet est
maintenu bénévolement par une seule personne, et un rythme promis puis non tenu vaut moins que pas
de rythme du tout. **Une exception** : un correctif de **sécurité** part seul et sans attendre (cf.
[SECURITY.md](SECURITY.md)).

### Commits et PR

- Messages de commit en français, à l'impératif, préfixés par la zone touchée :
  `fix(planning): …`, `feat(admin): …`, `docs: …`.
- Une PR = un sujet. Une PR qui corrige un bug **et** refactorise trois fichiers est difficile à
  relire, donc lente à fusionner.
- Décris **le comportement observable** : ce qui change pour l'utilisateur, pas seulement pour le
  code. Une capture avant/après aide beaucoup sur un changement visuel.

## Licence

Le projet est sous **[AGPL-3.0](https://github.com/stephanfo/club-o-clock/blob/main/LICENSE)**. En contribuant, tu acceptes que ta contribution soit
distribuée sous cette licence.

Concrètement, l'AGPL implique que **si tu déploies une version modifiée accessible en réseau**, tu
dois en proposer le code source à ses utilisateurs (§13). C'est délibéré : ce que les clubs
s'apportent mutuellement doit rester disponible aux clubs.
