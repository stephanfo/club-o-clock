# Documentation

Club'O'Clock est une application web installable (PWA) de gestion du planning
d'entraînement, pensée pour un **club sportif associatif de 50 à 150 adhérents administré
par des bénévoles**.

Chaque club installe **sa propre instance** : ses données restent chez lui, et il n'existe
aucun compte central. C'est le principe qui structure aussi cette documentation — elle
s'adresse à toi si tu vas héberger, exploiter et faire vivre l'application pour ton club.

## Par où commencer

| Tu veux… | Lire |
|---|---|
| **Voir l'application** sans rien installer | La [démo publique](https://demo.cluboclock.ratelet.fr/), ou [l'application en images](CAPTURES.md) |
| **Installer une instance** pour ton club | [Installation & déploiement](INSTALL.md) |
| **Explorer le jeu de démonstration** | [Comptes de démonstration](COMPTES_DEMO.md) |
| **Comprendre ce que fait le produit**, et ce qu'il ne fait pas | [Spécification produit](PRD.md) |
| **Comprendre les choix techniques** et ce qu'ils coûtent | [Cadrage technique](CADRAGE_TECHNIQUE.md) |
| **Recetter ton instance** avant l'ouverture aux adhérents | [Plan de tests — membres](PLAN_TESTS_MEMBRES.md) |
| **Contribuer** au projet | [Guide de contribution](../CONTRIBUTING.md) |
| **Monter un poste de développement** sans rien installer | [Poste de développement en conteneurs](DOCKER_LOCAL.md) |

## Les documents

### Démarrer

- **[Installation & déploiement](INSTALL.md)** — du poste de développement à la production.
  Prérequis, configuration (email, OAuth, notifications push), premier démarrage,
  déploiement sur mutualisé ou sur VPS, maintenance et dépannage.
- **[Comptes de démonstration](COMPTES_DEMO.md)** — le jeu de données du club fictif
  « TEAM44 » : qui est qui, quels cas chaque compte illustre, et la semaine type seedée.
- **[L'application en images](CAPTURES.md)** — treize écrans commentés, côté adhérent, coach
  et bureau.

### Comprendre

- **[Spécification produit](PRD.md)** — la source de vérité fonctionnelle. Rôles, cycle de vie
  des comptes mineurs, quotas, liste d'attente, encadrement, notifications, traçabilité.
  Précise aussi ce qui est **hors périmètre**, et pourquoi.
- **[Cadrage technique](CADRAGE_TECHNIQUE.md)** — les décisions techniques et leurs
  contreparties assumées : pourquoi un monolithe, pourquoi pas de SPA, ce que l'hébergement
  mutualisé impose.

### Recetter

- **[Plan de tests — membres](PLAN_TESTS_MEMBRES.md)** — parcours de recette entièrement en
  interface, sans ligne de commande. Destiné aux testeurs du club.
- **[Plan de tests — technique](PLAN_TESTS.md)** — la même recette côté organisateur, avec
  accès aux journaux, aux commandes Artisan et à la base.

### Contribuer

- **[Guide de contribution](../CONTRIBUTING.md)** — porte de qualité, conventions, périmètre.
- **[Poste de développement en conteneurs](DOCKER_LOCAL.md)** — faire tourner l'application, la
  porte de qualité et les tests navigateur sans installer PHP, MySQL ni les navigateurs sur sa
  machine. **Outil de poste uniquement** : la cible de déploiement reste le mutualisé sans Docker.
- **[Politique de sécurité](../SECURITY.md)** — signaler une vulnérabilité.
- **[Journal des versions](../CHANGELOG.md)** — ce qui change à chaque version.

## Bon à savoir

**Le projet est en français** — code, commentaires, interface et documentation. Ce n'est pas
un oubli d'internationalisation : c'est un choix assumé pour la version 1.

**L'administration est conçue pour le bureau, pas pour le téléphone.** L'application est
majoritairement consultée au mobile par les adhérents, mais les écrans d'administration
supposent un grand écran.

**Aucune donnée sensible n'est stockée** : ni numéro de téléphone, ni certificat médical, ni
numéro de licence fédérale. C'est une contrainte de conception, pas un réglage.

---

> Une question sans réponse dans ces pages ? Les
> [discussions GitHub](https://github.com/stephanfo/club-o-clock/discussions) sont ouvertes.
> Merci de n'y faire figurer **aucune donnée réelle d'adhérent**.
